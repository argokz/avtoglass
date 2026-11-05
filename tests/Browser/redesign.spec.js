import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

test.setTimeout(120_000);

async function capture(page, info, name) {
    const directory = resolve('.codex/audit/screenshots', info.project.name);
    mkdirSync(directory, { recursive: true });
    await page.screenshot({ path: resolve(directory, `${name}.png`), fullPage: true, animations: 'disabled' });
}

test('selected price, installation, stock and quantity agree through checkout', async ({ page }, info) => {
    await page.goto('/tovar/preview-camry-70');
    await page.locator('input[name="picked_offer"][data-price="41750.55"]').check();
    const prices = page.locator('[data-product-price]');
    await expect(prices.first()).toHaveText('41 750,55 ₸');
    await expect(prices.last()).toHaveText('41 750,55 ₸');
    await expect(page.locator('[data-product-total]')).toHaveText('56 750,55 ₸');
    await expect(page.locator('[data-product-stock]')).toContainText('Под заказ');
    await page.getByRole('spinbutton', { name: 'Количество', exact: true }).fill('3');
    if (info.project.name === 'mobile') {
        const bar = page.locator('[data-product-mobile-bar]');
        await expect(bar.getByRole('button')).toHaveCount(1);
        await bar.getByRole('button', { name: 'Оформить', exact: true }).click();
    } else {
        await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
        await page.goto('/korzina');
    }
    await expect(page.locator('main')).toContainText('125 251,65');
    await expect(page.getByRole('spinbutton', { name: 'Количество', exact: true })).toHaveValue('3');
    await capture(page, info, 'fractional-checkout');
});

test('unknown offer never retains the previous price or installation total', async ({ page }) => {
    await page.goto('/tovar/preview-camry-70');
    await page.locator('input[name="picked_offer"][data-price="0"]').check();
    await expect(page.locator('[data-product-price]').first()).toHaveText('Цена по запросу');
    await expect(page.locator('[data-product-price]').last()).toHaveText('Цена по запросу');
    await expect(page.locator('[data-product-total]')).toHaveText('Уточним стоимость');
});

test('late model response cannot replace a cleared vehicle selection', async ({ page }) => {
    let release;
    let started;
    const waiting = new Promise(resolve => { release = resolve; });
    const requestStarted = new Promise(resolve => { started = resolve; });
    await page.route('**/api/brands/toyota/models', async route => {
        started();
        await waiting;
        await route.fulfill({ json: [{ id: 1, name: 'Delayed model', slug: 'delayed-model' }] });
    });
    await page.goto('/');
    await page.getByLabel('Марка', { exact: true }).selectOption({ label: 'Toyota' });
    await requestStarted;
    await page.getByLabel('Марка', { exact: true }).selectOption('');
    const response = page.waitForResponse('**/api/brands/toyota/models');
    release();
    await response;
    await expect(page.getByLabel('Модель', { exact: true })).toBeDisabled();
    await expect(page.getByLabel('Модель', { exact: true })).not.toContainText('Delayed model');
});

for (const width of [320, 360, 390, 768, 1365]) {
    for (const theme of ['light', 'dark']) {
        test(`redesign reflow ${width}px ${theme}`, async ({ page }, info) => {
            test.skip(info.project.name !== 'desktop', 'The explicit viewport matrix runs once.');
            await page.setViewportSize({ width, height: 900 });
            await page.addInitScript(theme => localStorage.setItem('avtoglass.theme', theme), theme);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            for (const [name, path] of [['home', '/'], ['search', '/search?q=Camry'], ['product', '/tovar/preview-camry-70']]) {
                const response = await page.goto(path);
                expect(response.status()).toBe(200);
                await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow`).toBeTruthy();
                await capture(page, info, `${name}-${width}-${theme}`);
            }
            if (width < 1024) {
                const box = await page.locator('[data-product-mobile-bar]').boundingBox();
                expect(box.x).toBeGreaterThanOrEqual(0);
                expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
                await expect(page.locator('[data-product-mobile-bar] button')).toHaveCount(1);
            }
            await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
            await page.goto('/korzina');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'cart overflow').toBeTruthy();
            await capture(page, info, `checkout-${width}-${theme}`);
            expect(errors).toEqual([]);
        });
    }
}
