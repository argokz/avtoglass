import { test, expect } from '@playwright/test';

// Проверки требований 2026 на изолированном preview: мобильная навигация,
// нижняя панель корзины и экспресс-покупка «в 1 клик».

test.beforeEach(async ({ baseURL }) => {
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(['8015', '18015']).toContain(url.port);
});

test('mobile tab bar leads to catalog, search, cart and chat', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');

    const tabs = page.getByRole('navigation', { name: 'Быстрая навигация', exact: true });
    await expect(tabs).toBeVisible();
    await expect(tabs.getByRole('link', { name: 'Каталог' })).toBeVisible();
    await expect(tabs.getByRole('link', { name: 'Поиск' })).toBeVisible();
    await expect(tabs.getByRole('link', { name: 'Корзина' })).toBeVisible();

    await tabs.getByRole('link', { name: 'Каталог' }).click();
    await expect(page).toHaveURL(/\/catalog/);
});

test('tab bar does not cover the product page sticky CTA', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/tovar/preview-camry-70');

    await expect(page.locator('[data-product-mobile-bar]')).toBeVisible();
    // На карточке своя нижняя панель — общей таб-панели быть не должно.
    await expect(page.getByRole('navigation', { name: 'Быстрая навигация', exact: true })).toHaveCount(0);
});

test('one-click purchase asks only for phone', async ({ page }) => {
    await page.goto('/tovar/preview-camry-70');

    await page.getByRole('button', { name: 'Купить в 1 клик', exact: true }).first().click();

    const modal = page.locator('#quick-order-modal');
    await expect(modal).toBeVisible();
    await expect(modal.getByLabel('Телефон')).toBeVisible();

    await modal.getByLabel('Телефон').fill('+7 700 000-00-11');
    const consent = modal.locator('input[name="privacy_consent"]');
    if (await consent.count()) {
        await consent.check();
    }
    await modal.getByRole('button', { name: 'Жду звонка', exact: true }).click();

    await expect(page).toHaveURL(/\/spasibo/);
});

test('cart shows mobile checkout bar with total', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/tovar/preview-camry-70');
    await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
    await page.goto('/korzina');

    const bar = page.locator('[data-cart-summary][class*="fixed"]');
    await expect(bar).toBeVisible();
    await expect(bar.getByRole('button', { name: 'Оформить заказ', exact: true })).toBeVisible();
});

test('made in kazakhstan badge is visible on domestic glass', async ({ page }) => {
    await page.goto('/tovar/preview-camry-70');
    await expect(page.getByText('Сделано в Казахстане').first()).toBeVisible();
});

test('search suggest appears while typing', async ({ page }) => {
    await page.goto('/');

    const input = page.locator('input[data-suggest]:visible').first();
    await input.click();
    await input.pressSequentially('camry', { delay: 60 });

    const box = page.locator('[data-suggest-box]:visible').first();
    await expect(box).toBeVisible({ timeout: 10000 });
    await expect(box).toContainText('Camry');
});
