import { chromium } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
const base = 'http://127.0.0.1:18016';
const out = 'output/playwright/audit-2026-10-09';
if ((await (await fetch(`${base}/preview/health`)).json()).preview !== 'avtoglass-isolated-v1') throw new Error('Preview identity failed');
const access = JSON.parse(readFileSync('.codex/modernization/preview-access.json', 'utf8'));
const extras = process.env.AUDIT_PHASE === 'extras';
const results = extras ? JSON.parse(readFileSync(`${out}/interactions.json`, 'utf8')) : { profile: 'Chromium mobile 390px CPU4, RTT150ms 1.6Mbps', events: [], journeys: [], errors: [] };
if (extras) { results.harnessErrors = results.errors; results.errors = []; }
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
await context.addInitScript(() => {
    window.auditInteractions = [];
    new PerformanceObserver(list => { for (const e of list.getEntries()) if (e.interactionId) window.auditInteractions.push({ name: e.name, duration: e.duration, interactionId: e.interactionId }); }).observe({ type: 'event', durationThreshold: 16, buffered: true });
});
const page = await context.newPage(); const cdp = await context.newCDPSession(page);
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 200000, uploadThroughput: 93750 });
await context.tracing.start({ screenshots: true, snapshots: true });
const save = () => writeFileSync(`${out}/interactions.json`, JSON.stringify(results, null, 2));
async function login(role) {
    await context.clearCookies(); await page.goto(`${base}/vhod`);
    await page.locator('main').getByLabel('Телефон', { exact: true }).fill(access[`${role}_phone`]);
    await page.locator('main').getByLabel('Пароль', { exact: true }).fill(access.password);
    await page.getByRole('button', { name: 'Войти', exact: true }).click(); await page.waitForURL(/\/kabinet/);
}
async function measure(name, action, server = false) {
    await page.evaluate(() => window.auditInteractions = []);
    const started = performance.now();
    try {
        if (server) await Promise.all([page.waitForResponse(r => /\/livewire(?:-[^/]+)?\//.test(r.url()) && r.request().method() === 'POST'), action()]);
        else await action();
        const actionMs = performance.now() - started;
        await page.waitForTimeout(350);
        results.events.push({ name, actionMs, eventTimings: await page.evaluate(() => window.auditInteractions), serverIncluded: server });
    } catch (error) { results.errors.push({ name, error: error.message }); }
    save();
}
try {
    if (!extras) {
    await login('wholesale'); await page.goto(`${base}/opt`);
    results.journeys.push({ name: 'wholesale-row-stock-labels', rows: await page.locator('[wire\\:change^="toggle"]:visible').evaluateAll(inputs => inputs.map(input => ({
        action: input.getAttribute('wire:change'), text: input.closest('tr,li')?.innerText,
    }))) });
    for (let i = 0; i < 5; i++) await measure('wholesale-select', () => page.locator('[wire\\:change^="toggle"]:visible').first().click(), true);
    for (let i = 0; i < 5; i++) await measure('wholesale-quantity-keyboard', async () => {
        const input = page.getByLabel('Количество', { exact: true }).filter({ visible: true }).first();
        await input.focus(); await input.press('Control+A'); await input.pressSequentially(String(i + 2));
    }, true);
    const selectedBefore = await page.locator('input[name$="[offer_id]"]').count();
    for (let i = 0; i < 5; i++) await measure('wholesale-filter', () => page.getByLabel('Поиск оптовых предложений').fill(i % 2 ? 'Camry' : 'AUDIT-UNKNOWN'), true);
    const selectedAfterFilter = await page.locator('input[name$="[offer_id]"]').count();
    await page.reload(); const selectedAfterReload = await page.locator('input[name$="[offer_id]"]').count();
    results.journeys.push({ name: 'wholesale-persistence', selectedBefore, selectedAfterFilter, selectedAfterReload });
    }
    await login('retail'); await page.goto(`${base}/tovar/preview-camry-70`);
    await measure('add-to-cart-navigation', () => page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click());
    await page.goto(`${base}/korzina`);
    const details = page.locator('[data-contacts-collapsed] summary');
    if (!extras) for (let i = 0; i < 5; i++) await measure('checkout-open-close-contacts', () => details.click());
    else if (await page.locator('[data-contacts-collapsed]').getAttribute('open') === null) await details.click();
    const form = page.locator('[data-order-form]');
    await form.locator('input[name="phone"]').scrollIntoViewIfNeeded();
    results.journeys.push({ name: 'mobile-checkout-visible', data: await form.locator('input[name="phone"]').evaluate(el => {
        const field = el.getBoundingClientRect();
        return { field: { top: field.top, bottom: field.bottom }, viewport: innerHeight, active: document.activeElement?.tagName,
            overlays: [...document.querySelectorAll('body *')].filter(e => ['fixed', 'sticky'].includes(getComputedStyle(e).position)).filter(e => {
                const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.left < field.right && r.right > field.left && r.top < field.bottom && r.bottom > field.top;
            }).map(e => ({ tag: e.tagName, role: e.getAttribute('aria-label'), classes: e.className })) };
    }) });
    await page.screenshot({ path: `${out}/mobile-checkout-focus.png` });
    await page.setViewportSize({ width: 390, height: 450 });
    for (const [name, control] of [['phone', form.locator('input[name="phone"]')], ['submit', page.getByRole('button', { name: 'Оформить заказ', exact: true }).filter({ visible: true }).first()]]) {
        await control.scrollIntoViewIfNeeded();
        results.journeys.push({ name: `short-viewport-${name}`, data: await control.evaluate(el => {
            const r = el.getBoundingClientRect(), top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return { top: r.top, bottom: r.bottom, viewport: innerHeight, centerAccessible: top === el || el.contains(top), coveringTag: top?.tagName };
        }) });
        await page.screenshot({ path: `${out}/short-viewport-${name}.png` });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await context.clearCookies(); await page.goto(`${base}/chat`);
    await page.getByLabel('Сообщение помощнику').fill('DEMO-1');
    await measure('chat-enqueue', () => page.getByRole('button', { name: 'Отправить', exact: true }).click(), true);
    await measure('chat-cancel-queue-without-worker', () => page.getByRole('button', { name: 'Остановить', exact: true }).click(), true);
    results.journeys.push({ name: 'chat-queue', limitation: 'Dedicated 5441 UI server has no queue worker; generation quality measured separately on real audit worker.' });
} catch (error) { results.errors.push({ error: error.message }); }
finally { save(); await context.tracing.stop({ path: `${out}/interaction-trace.zip` }); await browser.close(); }
console.log(JSON.stringify({ events: results.events.length, errors: results.errors.length }));
