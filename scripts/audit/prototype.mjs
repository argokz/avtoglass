import { chromium } from '@playwright/test';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { writeFileSync } from 'node:fs';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1365, height: 900 } });
const url = pathToFileURL(resolve('docs/audit-2026-10-09/prototype.html')).href;
const result = { checks: [], errors: [] };
page.on('pageerror', e => result.errors.push(e.message));
function assert(condition, name) { result.checks.push({ name, passed: !!condition }); if (!condition) throw new Error(name); }
try {
    await page.goto(url);
    assert(await page.locator('#positions').innerText() === '3', 'Initial mixed list');
    assert(await page.locator('#known').innerText() === '94 500 ₸', 'Unknown prices excluded');
    await page.locator('#catalog').click(); await page.locator('#car').click();
    assert(await page.locator('#positions').innerText() === '5', 'Catalog plus car reference');
    await page.locator('#filter').fill('1995');
    assert(await page.locator('#rows tr').count() === 2, 'Filter changes visible rows only');
    await page.reload(); assert(await page.locator('#positions').innerText() === '5', 'Reload preserves specification');
    await page.locator('details summary').click();
    await page.locator('#paste').fill('DEMO-1; 4\nUNKNOWN; 120'); await page.locator('#add-list').click();
    assert(await page.locator('#positions').innerText() === '5', 'Known duplicate merged and invalid row rejected');
    assert(await page.getByLabel('Количество DEMO-1 · Camry 70, лобовое', { exact: true }).inputValue() === '7', 'Duplicate quantity retained');
    assert((await page.locator('#import-result').innerText()).includes('Строка 2'), 'Import row error visible');
    await page.locator('#address').fill('Демонстрационная улица, 1'); await page.reload();
    assert(await page.locator('#address').inputValue() === 'Демонстрационная улица, 1', 'Contact/fulfillment draft retained');
    await page.locator('#consent').check(); await page.locator('#checkout button[type="submit"]').click();
    assert(await page.locator('#sent').isVisible(), 'Local manager handoff shown');
    assert(await page.locator('form').count() === 1, 'Single checkout form');
    for (const width of [390, 1365]) {
        await page.setViewportSize({ width, height: 900 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `No overflow at ${width}`);
        await page.screenshot({ path: `output/playwright/audit-2026-10-09/prototype-${width}.png`, fullPage: true });
    }
} finally {
    writeFileSync('output/playwright/audit-2026-10-09/prototype-qa.json', JSON.stringify(result, null, 2));
    await browser.close();
}
console.log(JSON.stringify(result));
