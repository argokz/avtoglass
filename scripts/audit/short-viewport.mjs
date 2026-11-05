import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';
const base = 'http://127.0.0.1:18016', output = 'output/playwright/audit-2026-10-09';
if ((await (await fetch(`${base}/preview/health`)).json()).preview !== 'avtoglass-isolated-v1') throw new Error('Preview identity failed');
const browser = await chromium.launch();
const context = await browser.newContext({ storageState: `${output}/retail-storage.json`, viewport: { width: 390, height: 450 } });
const page = await context.newPage(); const results = [];
try {
    await page.goto(`${base}/korzina`);
    const contacts = page.locator('[data-contacts-collapsed]');
    if (await contacts.getAttribute('open') === null) {
        await contacts.locator('summary').focus();
        await contacts.locator('summary').press('Enter');
    }
    for (const [name, control] of [['phone', page.locator('[data-order-form] input[name="phone"]')], ['submit', page.getByRole('button', { name: 'Оформить заказ', exact: true }).filter({ visible: true }).first()]]) {
        await control.focus(); await control.scrollIntoViewIfNeeded();
        results.push({ name, ...await control.evaluate(el => {
            const r = el.getBoundingClientRect(), top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return { focused: document.activeElement === el, centerAccessible: top === el || el.contains(top), coveringTag: top?.tagName,
                rect: { top: r.top, bottom: r.bottom }, viewport: innerHeight };
        }) });
        await page.screenshot({ path: `${output}/short-viewport-${name}.png` });
    }
    await page.setViewportSize({ width: 1365, height: 900 });
    await page.evaluate(() => document.documentElement.style.fontSize = '200%');
    results.push({ name: 'text-zoom-200', overflow: await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), method: 'CSS root font scaling, not native browser zoom' });
    await page.screenshot({ path: `${output}/text-zoom-200.png`, fullPage: true });
} finally {
    writeFileSync(`${output}/short-viewport.json`, JSON.stringify(results, null, 2));
    await browser.close();
}
console.log(JSON.stringify(results));
