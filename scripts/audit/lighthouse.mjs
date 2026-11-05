import lighthouse from 'lighthouse';
import { launch } from 'chrome-launcher';
import { chromium } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';

const base = 'http://127.0.0.1:18016';
const output = 'output/playwright/audit-2026-10-09/lighthouse';
mkdirSync(output, { recursive: true });
const health = await fetch(`${base}/preview/health`);
if (!health.ok || (await health.json()).preview !== 'avtoglass-isolated-v1') throw new Error('Preview identity failed');
const access = JSON.parse(readFileSync('.codex/modernization/preview-access.json', 'utf8'));
const chrome = await launch({ chromePath: chromium.executablePath(), chromeFlags: ['--headless=new', '--no-sandbox', '--disable-dev-shm-usage'] });
const browser = await chromium.connectOverCDP(`http://127.0.0.1:${chrome.port}`);
const summary = existsSync(`${output}/summary.json`) ? JSON.parse(readFileSync(`${output}/summary.json`, 'utf8')) : [];
try {
    for (const profile of ['desktop', 'mobile']) {
        for (const role of ['guest', 'retail', 'wholesale']) {
            const context = browser.contexts()[0]; await context.clearCookies();
            const page = context.pages()[0] || await context.newPage();
            await page.setViewportSize({ width: 1365, height: 900 });
            const cdp = await context.newCDPSession(page);
            if (role !== 'guest') {
                await page.goto(`${base}/vhod`);
                await page.locator('main').getByLabel('Телефон', { exact: true }).fill(access[`${role}_phone`]);
                await page.locator('main').getByLabel('Пароль', { exact: true }).fill(access.password);
                await page.getByRole('button', { name: 'Войти', exact: true }).click(); await page.waitForURL(/\/kabinet/);
            }
            if (role === 'retail') {
                await page.goto(`${base}/tovar/preview-camry-70`);
                await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
            }
            const pages = role === 'guest' ? [['home', '/'], ['models', '/catalog/toyota'], ['search', '/search?q=Camry'], ['product', '/tovar/preview-camry-70'], ['chat', '/chat']]
                : role === 'retail' ? [['cart', '/korzina'], ['checkout', '/korzina#oformlenie']] : [['wholesale', '/opt']];
            for (const [name, path] of pages) {
                for (let run = 1; run <= 5; run++) {
                    if (summary.some(x => x.profile === profile && x.name === name && x.run === run && Number.isFinite(x.lcp) && !x.error && !x.runtimeError && (name !== 'checkout' || x.checkoutCanonical))) continue;
                    const previous = summary.findIndex(x => x.profile === profile && x.name === name && x.run === run);
                    if (previous >= 0) summary.splice(previous, 1);
                    try {
                        await page.goto('about:blank');
                        await cdp.send('Network.clearBrowserCache');
                        // The checkout is embedded in cart. Lantern cannot reliably simulate
                        // LCP after automatic anchor scrolling; audit its document URL and
                        // measure opening/scrolling the form separately in interactions.mjs.
                        const result = await lighthouse(`${base}${path.split('#')[0]}`, {
                            port: chrome.port, output: ['json', 'html'], logLevel: 'error', onlyCategories: ['performance', 'accessibility'],
                            formFactor: profile, disableStorageReset: true, throttlingMethod: 'simulate', maxWaitForLoad: 45000,
                            screenEmulation: { mobile: profile === 'mobile', width: profile === 'mobile' ? 390 : 1365, height: 900, deviceScaleFactor: 1, disabled: false },
                            throttling: profile === 'mobile' ? { rttMs: 150, throughputKbps: 1638.4, cpuSlowdownMultiplier: 4 } : { rttMs: 40, throughputKbps: 10240, cpuSlowdownMultiplier: 1 },
                        });
                        const id = `${profile}-${name}-${run}`;
                        writeFileSync(`${output}/${id}.json`, result.report[0]); writeFileSync(`${output}/${id}.html`, result.report[1]);
                        summary.push({ profile, name, run, cache: 'cold-http', checkoutCanonical: name === 'checkout', requestedPath: path, finalUrl: result.lhr.finalDisplayedUrl, runtimeError: result.lhr.runtimeError,
                            performance: result.lhr.categories.performance.score, accessibility: result.lhr.categories.accessibility.score,
                            lcp: result.lhr.audits['largest-contentful-paint'].numericValue, cls: result.lhr.audits['cumulative-layout-shift'].numericValue,
                            tbt: result.lhr.audits['total-blocking-time'].numericValue, ttfb: result.lhr.audits['server-response-time']?.numericValue,
                            bytes: result.lhr.audits['total-byte-weight'].numericValue });
                    } catch (error) { summary.push({ profile, name, run, error: error.message }); }
                    writeFileSync(`${output}/summary.json`, JSON.stringify(summary, null, 2));
                    console.log(`lighthouse ${profile} ${name} ${run}`);
                }
            }
        }
    }
} finally { await browser.close(); await chrome.kill(); }
