import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

const accessPath = resolve('.codex/modernization/preview-access.json');

async function capture(page, testInfo, name) {
    await page.evaluate(() => document.fonts.ready);
    const directory = resolve('.codex/modernization/screenshots', process.env.CAPTURE_STAGE || 'current', testInfo.project.name);
    mkdirSync(directory, { recursive: true });
    await page.screenshot({ path: resolve(directory, `${name}.png`), fullPage: true, animations: 'disabled' });
}

async function signIn(page, kind) {
    const access = JSON.parse(readFileSync(accessPath, 'utf8'));
    await page.goto('/vhod');
    await page.getByLabel('Телефон', { exact: true }).fill(access[`${kind}_phone`]);
    await page.getByLabel('Пароль', { exact: true }).fill(access.password);
    await page.getByRole('button', { name: 'Войти', exact: true }).click();
    await expect(page).toHaveURL(/\/kabinet(?:\?.*)?$/);
}

test.beforeEach(async ({ baseURL }) => {
    // These tests modify only synthetic customer sessions in the local preview.
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(['8015', '18015']).toContain(url.port);
});

test('public storefront and assistant render', async ({ page }, testInfo) => {
    for (const [name, path] of [['home', '/'], ['catalog', '/catalog'], ['product', '/tovar/preview-camry-70'], ['chat', '/chat']]) {
        const response = await page.goto(path);
        expect(response.status(), path).toBe(200);
        await expect(page.locator('main')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), path).toBeTruthy();
        await capture(page, testInfo, name);
    }

    await page.getByPlaceholder('Camry 2018, лобовое…').fill('Camry 2018, нужно лобовое стекло');
    await page.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByText('Это демонстрационный диалог.', { exact: false })).toBeVisible({ timeout: 30000 });
});

test('switching modes preserves cart and dialogue', async ({ page }, testInfo) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/tovar/preview-camry-70');
    await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
    await expect(page.locator('#toast-root')).toHaveAttribute('data-flash-success', 'Добавлено в корзину');
    const switcher = page.getByRole('navigation', { name: 'Способ подбора', exact: true });
    await switcher.getByRole('button', { name: 'ИИ-помощник', exact: true }).click();
    await expect(page).toHaveURL(/\/chat$/);
    await expect(page.locator('header').getByRole('link', { name: 'Корзина (1)', exact: true })).toBeVisible();
    const composer = page.getByRole('textbox', { name: 'Сообщение помощнику', exact: true });
    await composer.fill('Camry 2018');
    await composer.press('Shift+Enter');
    await composer.pressSequentially('windshield');
    await expect(composer).toHaveValue('Camry 2018\nwindshield');
    await composer.press('Enter');
    await expect(page.getByRole('log')).toContainText('Это демонстрационный диалог.', { timeout: 30000 });
    await composer.fill('Спасибо, вернусь к каталогу');
    await page.getByRole('button', { name: 'Отправить', exact: true }).click();
    await expect(page.getByRole('log').getByText('Это демонстрационный диалог.', { exact: false })).toHaveCount(2, { timeout: 30000 });
    await switcher.getByRole('button', { name: 'Магазин', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Стекло на какую машину?', exact: true })).toBeVisible();
    await switcher.getByRole('button', { name: 'ИИ-помощник', exact: true }).click();
    await expect(page.getByRole('log')).toContainText('Это демонстрационный диалог.');
    await page.goto('/');
    await expect(page.getByRole('link', { name: 'Продолжить в чате', exact: false })).toBeVisible();
    await page.locator('header').getByRole('link', { name: 'Корзина (1)', exact: true }).click();
    await expect(page.locator('main')).toContainText('Лобовое стекло Toyota Camry 70');
    await capture(page, testInfo, 'cart-after-switch');
    expect(errors).toEqual([]);
});

test('selected offer reaches the retail cart', async ({ page }, testInfo) => {
    await page.goto('/tovar/preview-camry-70');
    await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
    await expect(page.locator('#toast-root')).toHaveAttribute('data-flash-success', 'Добавлено в корзину');
    await page.goto('/korzina');
    await expect(page.locator('main')).toContainText('Лобовое стекло Toyota Camry 70');
    await expect(page.locator('main')).toContainText('35');
    await capture(page, testInfo, 'cart');
});

test('retail order history remains available', async ({ page }, testInfo) => {
    await signIn(page, 'retail');
    await expect(page.locator('main')).toContainText('Лобовое стекло Toyota Camry 70');
    await capture(page, testInfo, 'account');
});

test('approved wholesale customer can repeat a specification', async ({ page }, testInfo) => {
    await signIn(page, 'wholesale');
    await page.getByRole('link', { name: 'Повторить заявку', exact: true }).click();
    await expect(page).toHaveURL(/\/opt\?repeat=/);
    await expect(page.locator('main')).toContainText('Лобовое стекло Toyota Camry 70');
    await capture(page, testInfo, 'wholesale');
});
