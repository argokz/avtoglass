import { chromium } from '@playwright/test';
import { writeFileSync } from 'node:fs';
const browser = await chromium.launch(); const page = await browser.newPage({ viewport: { width: 1365, height: 900 } });
await page.goto('https://itwin.kz/avto-glass/catalog/toyota');
await page.locator('img[alt="Toyota 4Runner"]').waitFor();
await page.screenshot({ path: 'output/playwright/audit-2026-10-09/photo-before.png' });
const data = await page.locator('img[alt="Toyota 4Runner"]').evaluate(i => {
    const p = i.parentElement; const a = i.getBoundingClientRect(), b = p.getBoundingClientRect(); const s = getComputedStyle(i), ps = getComputedStyle(p);
    return { classes: i.className, image: { width: a.width, height: a.height, top: a.top, bottom: a.bottom, naturalWidth: i.naturalWidth, naturalHeight: i.naturalHeight },
        container: { width: b.width, height: b.height, top: b.top, bottom: b.bottom },
        styles: { fit: s.objectFit, height: s.height, minHeight: s.minHeight, maxHeight: s.maxHeight, parentDisplay: ps.display, parentRows: ps.gridTemplateRows, parentPadding: ps.padding, parentOverflow: ps.overflow } };
});
writeFileSync('output/playwright/audit-2026-10-09/photo-diagnostics.json', JSON.stringify(data, null, 2));
// Browser-only diagnostic: constrain the intrinsic grid track; no application file changes.
await page.locator('img[alt="Toyota 4Runner"]').evaluate(i => {
    i.parentElement.style.gridTemplateRows = 'minmax(0, 1fr)';
    i.style.minHeight = '0';
});
data.experiment = await page.locator('img[alt="Toyota 4Runner"]').evaluate(i => ({ imageHeight: i.getBoundingClientRect().height, gridRows: getComputedStyle(i.parentElement).gridTemplateRows }));
await page.screenshot({ path: 'output/playwright/audit-2026-10-09/photo-experiment.png' });
data.widths = [];
for (const width of [320, 360, 390, 768, 1365, 1920]) {
    await page.setViewportSize({ width, height: 900 });
    const row = await page.locator('img[alt="Toyota 4Runner"]').evaluate(i => {
        i.parentElement.style.gridTemplateRows = '';
        i.style.minHeight = '';
        const measure = () => {
            const image = i.getBoundingClientRect(), container = i.parentElement.getBoundingClientRect(), s = getComputedStyle(i.parentElement);
            const bottom = container.bottom - parseFloat(s.paddingBottom), top = container.top + parseFloat(s.paddingTop);
            return { imageHeight: image.height, availableHeight: bottom - top, fitsInsidePadding: image.top >= top - 1 && image.bottom <= bottom + 1 };
        };
        const before = measure();
        i.parentElement.style.gridTemplateRows = 'minmax(0,1fr)'; i.style.minHeight = '0';
        return { before, after: measure() };
    });
    data.widths.push({ width, ...row });
}
// Simulate a missing resource in this browser only, without changing server data.
await page.route('**/models/toyota-4runner.jpg', route => route.fulfill({ status: 404, body: 'Audit synthetic missing image' }));
await page.reload();
await page.locator('img[alt="Toyota 4Runner"]').scrollIntoViewIfNeeded();
await page.waitForTimeout(700);
data.missingResource = await page.locator('img[alt="Toyota 4Runner"]').evaluate(i => ({ complete: i.complete, naturalWidth: i.naturalWidth, alt: i.alt, fallbackSvg: !!i.parentElement.querySelector('svg') }));
await page.screenshot({ path: 'output/playwright/audit-2026-10-09/photo-missing.png' });
writeFileSync('output/playwright/audit-2026-10-09/photo-diagnostics.json', JSON.stringify(data, null, 2));
console.log(JSON.stringify(data)); await browser.close();
