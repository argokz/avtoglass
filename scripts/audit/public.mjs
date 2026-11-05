import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';
const output = 'output/playwright/audit-2026-10-09';
const base = 'https://itwin.kz/avto-glass';
const browser = await chromium.launch();
const page = await browser.newPage();
const results = [];
await page.goto(`${base}/catalog/toyota`);
const modelLink = page.locator('main a[href*="/catalog/toyota/"]').first();
if (await modelLink.count()) await page.goto(await modelLink.getAttribute('href'));
const productLink = page.locator('a[href*="/tovar/"]').first();
const product = await productLink.count() ? await productLink.getAttribute('href') : null;
for (const width of [320, 390, 1365]) {
    await page.setViewportSize({ width, height: 900 });
    for (const [name, path] of [['home', '/'], ['models', '/catalog/toyota'], ['search', '/search?q=Camry'], ['product', product], ['selection', '/podbor'], ['chat', '/chat']]) {
        try {
            if (!path) { results.push({ name, width, limitation: 'No product link discovered' }); continue; }
            const response = await page.goto(path.startsWith('http') ? path : `${base}${path}`, { timeout: 60000 });
            await page.waitForTimeout(1000);
            const data = await page.evaluate(() => ({
                overflow: document.documentElement.scrollWidth > innerWidth,
                orderForms: document.querySelectorAll('[data-order-form]').length,
                visibleOrderForms: Array.from(document.querySelectorAll('[data-order-form]')).filter(x => x.getBoundingClientRect().height > 0).length,
                images: Array.from(document.querySelectorAll('main img')).map(i => {
                    const b = i.getBoundingClientRect(); const p = i.parentElement.getBoundingClientRect(); const s = getComputedStyle(i);
                    return { src: i.currentSrc, alt: i.alt, width: b.width, height: b.height, naturalWidth: i.naturalWidth, naturalHeight: i.naturalHeight, fit: s.objectFit,
                        parentOverflow: getComputedStyle(i.parentElement).overflow, parentWidth: p.width, parentHeight: p.height,
                        outsideParent: b.left < p.left - 1 || b.right > p.right + 1 || b.top < p.top - 1 || b.bottom > p.bottom + 1 };
                }),
                navigation: performance.getEntriesByType('navigation').map(n => ({ ttfb: n.responseStart - n.requestStart, load: n.loadEventEnd })),
            }));
            const screenshot = `public-${name}-${width}.png`;
            await page.screenshot({ path: `${output}/${screenshot}`, fullPage: true });
            results.push({ name, width, url: page.url(), status: response.status(), ...data, screenshot });
        } catch (error) { results.push({ name, width, error: error.message }); }
        writeFileSync(`${output}/public-results.json`, JSON.stringify(results, null, 2));
        console.log(`public ${name} ${width}`);
    }
}
await browser.close();
