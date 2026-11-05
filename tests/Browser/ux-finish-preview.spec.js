import {test, expect} from '@playwright/test';
import {mkdirSync} from 'node:fs';
import {resolve} from 'node:path';

test.setTimeout(120_000);
test.use({actionTimeout: 15_000});

async function capture(page, info, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/09-ux-finish', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.screenshot({path: resolve(directory, `${name}.png`), animations: 'disabled'});
}

async function actionFits(page) {
    const bar = page.locator('[data-purchase-action]');
    await expect(bar).toBeVisible();
    if (page.viewportSize().width < 1024) {
        await expect.poll(() => bar.evaluate(el => {
            const rect = el.getBoundingClientRect();
            return Math.abs(rect.bottom - innerHeight) < 2 && rect.left >= 0 && rect.right <= innerWidth;
        })).toBeTruthy();
        expect(await bar.evaluate(el => getComputedStyle(el).position)).toBe('fixed');
    }
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(['8015', '18015']).toContain(url.port);
    await page.goto('/preview/ux');
    await expect(page.locator('[data-theme-select]')).toBeEnabled();
});

test('theme preference overrides system and persists through locale reload and another tab', async ({page, context}, info) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.emulateMedia({colorScheme: 'dark'});
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.getByLabel('Тема', {exact: true}).selectOption('light');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    expect(await page.locator('[data-theme-select]').evaluate(el => getComputedStyle(el).colorScheme)).toBe('light');
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.getByLabel('Тақырып', {exact: true})).toHaveValue('light');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    const other = await context.newPage();
    await other.goto('/');
    await expect(other.locator('[data-theme-select]')).toBeEnabled();
    await page.locator('[data-theme-select]').selectOption('dark');
    await expect(other.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(other.locator('[data-theme-select]')).toHaveValue('dark');
    await other.close();
    await capture(page, info, 'theme-dark-kk');
    await page.locator('[data-theme-select]').selectOption('system');
    await page.emulateMedia({colorScheme: 'light'});
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light');
    await page.addInitScript(() => {
        Storage.prototype.getItem = () => { throw new DOMException('Storage blocked', 'SecurityError'); };
        Storage.prototype.setItem = () => { throw new DOMException('Storage blocked', 'SecurityError'); };
    });
    await page.reload();
    await page.locator('[data-theme-select]').selectOption('dark');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    expect(errors).toEqual([]);
});

test('checkout focuses errors and autosaves contacts without blur before explicit confirmation', async ({page}, info) => {
    await page.getByRole('button', {name: 'Выбрать DEMO-1', exact: true}).click();
    await actionFits(page);
    await page.getByRole('button', {name: 'Перейти к оформлению', exact: true}).click();
    await expect(page.locator('#preview-name')).toBeVisible();
    await actionFits(page);
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.locator('[data-validation-summary]')).toBeFocused();
    await page.getByRole('button', {name: 'Перейти к ошибке', exact: true}).click();
    await expect(page.locator('#preview-name')).toBeFocused();
    const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/update'));
    await page.locator('#preview-name').fill('Проверка сохранения');
    await saved;
    await expect(page.locator('#preview-name')).toBeFocused();
    await page.reload();
    await expect(page.locator('#preview-name')).toHaveValue('Проверка сохранения');
    await page.locator('#preview-phone').fill('+77000000001');
    await page.getByRole('radio', {name: 'Доставка стекла', exact: true}).check();
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.locator('[data-validation-summary]')).toBeFocused();
    await page.getByRole('button', {name: 'Перейти к ошибке', exact: true}).click();
    await expect(page.locator('#preview-address')).toBeFocused();
    await capture(page, info, 'checkout-address-error');
    await page.locator('#preview-address').fill('Демонстрационная улица, 1');
    await page.getByRole('checkbox', {name: 'Согласен на обработку данных для выполнения заказа', exact: true}).check();
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Заказ подтверждён', exact: true})).toBeVisible();
    await expect(page.locator('[data-purchase-action]')).toHaveCount(0);
});

test('wholesale quantity error keeps focus and blocks continuation until corrected', async ({page}, info) => {
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await page.getByRole('checkbox', {name: 'Выбрать DEMO-1', exact: true}).check();
    const quantity = page.getByLabel('Количество DEMO-1', {exact: true});
    await quantity.fill('100');
    await expect(quantity).toHaveAttribute('aria-invalid', 'true');
    await expect(quantity).toBeFocused();
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeDisabled();
    await capture(page, info, 'wholesale-quantity-error');
    await quantity.fill('2');
    await expect(page.locator('[data-quote-total]')).toContainText('70 000');
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeEnabled();
    await page.getByRole('button', {name: 'Вставить список', exact: true}).click();
    await page.locator('#preview-list').fill('UNKNOWN;1');
    await page.getByRole('button', {name: 'Проверить список', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeDisabled();
    await actionFits(page);
});

test('320px action reserves space for consent and keyboard navigation works without horizontal overflow', async ({page}, info) => {
    await page.setViewportSize({width: 320, height: 800});
    await page.getByRole('button', {name: 'Выбрать DEMO-1', exact: true}).click();
    await page.getByRole('button', {name: 'Перейти к оформлению', exact: true}).click();
    await expect(page.locator('#preview-name')).toBeVisible();
    await actionFits(page);
    const consent = page.getByRole('checkbox', {name: 'Согласен на обработку данных для выполнения заказа', exact: true});
    await consent.focus();
    await consent.scrollIntoViewIfNeeded();
    expect(await consent.evaluate(el => el.getBoundingClientRect().bottom <= document.querySelector('[data-purchase-action]').getBoundingClientRect().top)).toBeTruthy();
    await page.keyboard.press('Space');
    await expect(consent).toBeChecked();
    await capture(page, info, '320-light-consent');
    await page.locator('[data-theme-select]').selectOption('dark');
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.locator('html')).toHaveAttribute('lang', 'kk');
    await actionFits(page);
    await capture(page, info, '320-dark-kk');
});
