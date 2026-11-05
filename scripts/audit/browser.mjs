import { chromium, firefox, webkit } from '@playwright/test';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const output = resolve('output/playwright/audit-2026-10-09');
mkdirSync(output, { recursive: true });
const base = process.env.PREVIEW_URL || 'http://127.0.0.1:18015';
if (!/^http:\/\/127\.0\.0\.1:(18016|18015|8015)$/.test(base)) throw new Error('Isolated preview required');
const health = await fetch(`${base}/preview/health`);
if (!health.ok || (await health.json()).preview !== 'avtoglass-isolated-v1') throw new Error('Preview identity failed');
const access = JSON.parse(readFileSync('.codex/modernization/preview-access.json', 'utf8'));
const results = { generatedAt: new Date().toISOString(), base, measurements: [], screens: [], journeys: [], errors: [] };
const resultFile = process.env.AUDIT_ONLY ? `browser-${process.env.AUDIT_ONLY}.json` : 'browser-results.json';
function save() { writeFileSync(`${output}/${resultFile}`, JSON.stringify(results, null, 2)); }
async function login(page, kind) {
    await page.goto(`${base}/vhod`);
    await page.locator('main').getByLabel('Телефон', { exact: true }).fill(access[`${kind}_phone`]);
    await page.locator('main').getByLabel('Пароль', { exact: true }).fill(access.password);
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await page.waitForURL(/\/kabinet/);
}
const pages = [
    ['home', '/', 'guest'], ['models', '/catalog/toyota', 'guest'],
    ['search', '/search?q=Camry', 'guest'], ['product', '/tovar/preview-camry-70', 'guest'],
    ['cart', '/korzina', 'retail'], ['checkout', '/korzina#oformlenie', 'retail'],
    ['wholesale', '/opt', 'wholesale'], ['chat', '/chat', 'guest'],
];
const browser = await chromium.launch({ headless: true });
const states = {};
for (const kind of ['guest', 'retail', 'wholesale']) {
    const context = await browser.newContext(); const page = await context.newPage();
    if (kind !== 'guest') await login(page, kind);
    if (kind === 'retail') {
        await page.goto(`${base}/tovar/preview-camry-70`);
        await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
        await page.waitForLoadState('load');
    }
    states[kind] = await context.storageState(); await context.close();
}
if (process.env.AUDIT_PHASE !== 'screens') for (const [profile, width] of [['desktop', 1365], ['mobile', 390]]) {
    for (const [name, path, role] of pages) {
        if (process.env.AUDIT_ONLY && name !== process.env.AUDIT_ONLY) continue;
        const context = await browser.newContext({ storageState: states[role], viewport: { width, height: 900 }, isMobile: profile === 'mobile' });
        await context.addInitScript(() => {
            window.auditVitals = { lcp: null, cls: 0, events: [] };
            try { new PerformanceObserver(list => { for (const e of list.getEntries()) window.auditVitals.lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true }); } catch {}
            try { new PerformanceObserver(list => { for (const e of list.getEntries()) if (!e.hadRecentInput) window.auditVitals.cls += e.value; }).observe({ type: 'layout-shift', buffered: true }); } catch {}
            try { new PerformanceObserver(list => { for (const e of list.getEntries()) if (e.interactionId) window.auditVitals.events.push({ name: e.name, duration: e.duration }); }).observe({ type: 'event', buffered: true, durationThreshold: 16 }); } catch {}
        });
        const page = await context.newPage(); const cdp = await context.newCDPSession(page);
        if (profile === 'mobile') {
            await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
            await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 200000, uploadThroughput: 93750 });
        }
        for (const cache of ['cold', 'warm']) {
            for (let run = 1; run <= 5; run++) {
                try {
                    if (cache === 'cold') await cdp.send('Network.clearBrowserCache');
                    await page.goto('about:blank');
                    const started = performance.now(); const response = await page.goto(`${base}${path}`, { waitUntil: 'load', timeout: 60000 });
                    await page.waitForTimeout(1200);
                    const metrics = await page.evaluate(() => {
                        const n = performance.getEntriesByType('navigation')[0]; const r = performance.getEntriesByType('resource');
                        return { ...window.auditVitals, ttfb: n.responseStart - n.requestStart, domContentLoaded: n.domContentLoadedEventEnd, load: n.loadEventEnd,
                            transferredBytes: n.transferSize + r.reduce((sum, x) => sum + x.transferSize, 0), resources: r.length,
                            largeResources: r.filter(x => x.transferSize > 100000).map(x => ({ path: new URL(x.name).pathname, type: x.initiatorType, bytes: x.transferSize })),
                            resourceTypes: r.reduce((o, x) => { (o[x.initiatorType] ||= { count: 0, bytes: 0 }).count++; o[x.initiatorType].bytes += x.transferSize; return o; }, {}) };
                    });
                    results.measurements.push({ profile, name, path, role, cache, run, status: response.status(), finalPath: new URL(page.url()).pathname, wallMs: performance.now() - started, ...metrics });
                } catch (error) { results.measurements.push({ profile, name, cache, run, error: error.name }); }
                save();
            }
        }
        console.log(`measured ${profile} ${name}`); await context.close();
    }
}
if (process.env.AUDIT_PHASE !== 'measurements') for (const width of [320, 360, 390, 768, 1365, 1920]) {
    for (const theme of ['light', 'dark']) {
        for (const [name, path, role] of pages) {
            const context = await browser.newContext({ storageState: states[role], viewport: { width, height: 900 } });
            await context.addInitScript(theme => localStorage.setItem('avtoglass.theme', theme), theme);
            const page = await context.newPage(); const errors = [];
            page.on('pageerror', e => errors.push(e.message));
            try {
                const response = await page.goto(`${base}${path}`); await page.evaluate(() => document.fonts.ready);
                const details = await page.evaluate(() => {
                    const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                    const ids = Array.from(document.querySelectorAll('[id]')).map(e => e.id);
                    return { overflow: document.documentElement.scrollWidth > innerWidth, theme: document.documentElement.dataset.theme,
                        orderForms: document.querySelectorAll('[data-order-form]').length,
                        visibleOrderForms: Array.from(document.querySelectorAll('[data-order-form]')).filter(visible).length,
                        duplicateIds: [...new Set(ids.filter((id, i) => ids.indexOf(id) !== i))],
                        images: Array.from(document.querySelectorAll('main img')).map(i => ({ src: i.currentSrc, width: i.clientWidth, height: i.clientHeight,
                            naturalWidth: i.naturalWidth, naturalHeight: i.naturalHeight, fit: getComputedStyle(i).objectFit,
                            container: { width: i.parentElement.clientWidth, height: i.parentElement.clientHeight, overflow: getComputedStyle(i.parentElement).overflow } })) };
                });
                const screenshot = `${name}-${width}-${theme}.png`;
                await page.screenshot({ path: `${output}/${screenshot}`, fullPage: true, animations: 'disabled' });
                results.screens.push({ name, path, role, width, theme, status: response.status(), ...details, errors, screenshot });
            } catch (error) { results.errors.push({ name, width, theme, error: error.message }); }
            save(); await context.close();
        }
    }
    console.log(`screens ${width}`);
}
// Verify selection loss independently of the retail cart.
{
    const context = await browser.newContext({ storageState: states.wholesale }); const page = await context.newPage();
    await context.tracing.start({ screenshots: true, snapshots: true });
    await page.goto(`${base}/opt`);
    const toggle = page.locator('input[type="checkbox"][wire\\:model]').first();
    const checkboxes = page.locator('main input[type="checkbox"]');
    results.journeys.push({ name: 'wholesale-controls', checkboxCount: await checkboxes.count(), uploadInputs: await page.locator('input[type="file"]').count() });
    const selectButton = page.locator('[wire\\:change^="toggle"]:visible').first();
    if (await selectButton.count()) {
        await selectButton.click(); await page.waitForTimeout(800);
        const before = await page.locator('main').innerText();
        const selectedBefore = await page.locator('input[name$="[offer_id]"]').count();
        await page.reload(); const after = await page.locator('main').innerText();
        const selectedAfter = await page.locator('input[name$="[offer_id]"]').count();
        results.journeys.push({ name: 'wholesale-reload', before, after, selectedBefore, selectedAfter });
    } else if (await toggle.count()) { results.journeys.push({ name: 'wholesale-reload', limitation: 'manual control inspection required' }); }
    await context.tracing.stop({ path: `${output}/wholesale-trace.zip` }); await context.close(); save();
}
await browser.close();
if (process.env.AUDIT_ONLY) { save(); process.exit(0); }
for (const [engine, launch] of [['firefox', firefox], ['webkit', webkit]]) {
    try {
        const b = await launch.launch(); const context = await b.newContext({ storageState: states.retail }); const page = await context.newPage();
        for (const [name, path] of pages.filter(x => x[2] !== 'wholesale')) {
            const response = await page.goto(`${base}${path}`);
            results.journeys.push({ engine, name, status: response?.status() ?? 200, sameDocument: response === null, overflow: await page.evaluate(() => document.documentElement.scrollWidth > innerWidth) });
        }
        await b.close();
    } catch (error) { results.errors.push({ engine, error: error.message }); }
    save();
}
writeFileSync(`${output}/retail-storage.json`, JSON.stringify(states.retail));
writeFileSync(`${output}/wholesale-storage.json`, JSON.stringify(states.wholesale));
console.log(JSON.stringify({ measurements: results.measurements.length, screens: results.screens.length, errors: results.errors.length }));
