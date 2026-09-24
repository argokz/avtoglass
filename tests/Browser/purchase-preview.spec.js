import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { openPreviewOrder } from './helpers/preview-cabinet.js';

test.setTimeout(120_000);

async function capture(page, info, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/02-purchase-prototypes', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.screenshot({path: resolve(directory, `${name}.png`), fullPage: true, animations: 'disabled'});
}

async function retailSelection(page) {
    await page.getByRole('button', { name: 'Выбрать DEMO-1', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Выбрать DEMO-1', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Перейти к оформлению', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Оформление', exact: true })).toBeVisible();
}

async function contacts(page, kk = false) {
    await page.getByLabel(kk ? 'Атыңыз' : 'Ваше имя', {exact: true}).fill('Демо клиент');
    await page.getByLabel('Телефон', {exact: true}).fill('+77000000001');
    await page.getByRole('checkbox', {name: kk ? 'Тапсырысты орындау үшін деректерді өңдеуге келісемін' : 'Согласен на обработку данных для выполнения заказа', exact: true}).check();
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(url.port).toBe('8015');
    await page.goto('/preview/ux');
    await expect(page.getByRole('button', {name: 'Начать заново', exact: true})).toBeEnabled();
});

test('retail checkout recalculates and preserves contacts without registration', async ({page}, info) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await retailSelection(page);
    const quantity = page.getByRole('spinbutton', {name: 'Количество DEMO-1'});
    await quantity.fill('2');
    await quantity.blur();
    await expect(page.locator('[data-quote-total]')).toContainText('70 000');
    await page.getByRole('radio', {name: 'Установка в сервисе', exact: true}).check();
    await expect(page.locator('[data-quote-total]')).toContainText('90 000');
    await page.getByRole('radio', {name: 'Доставка стекла', exact: true}).check();
    await contacts(page);
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.getByText('Укажите адрес получения.', {exact: true})).toBeVisible();
    await expect(page.getByLabel('Ваше имя', {exact: true})).toHaveValue('Демо клиент');
    const address = 'Демонстрационная улица, 1';
    const saved = page.waitForResponse(response => response.status() === 200
        && response.request().method() === 'POST' && response.url().includes('/update')
        && response.request().postDataJSON()?.components?.some(component => component.updates?.address === address));
    await page.getByLabel('Адрес', {exact: true}).fill(address);
    await page.getByLabel('Адрес', {exact: true}).blur();
    await saved;
    await capture(page, info, 'retail-delivery');
    await page.reload();
    await page.getByRole('navigation').getByRole('button', {name: /^Оформление/}).click();
    await expect(page.getByLabel('Адрес', {exact: true})).toHaveValue('Демонстрационная улица, 1');
    await contacts(page);
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Заказ подтверждён', exact: true})).toBeVisible();
    await capture(page, info, 'retail-result');
    await page.getByRole('button', {name: 'Открыть заказ', exact: true}).click();
    await expect(page.locator('main')).toContainText('Доставка стекла');
    await page.getByRole('button', {name: 'Повторить заказ', exact: true}).click();
    await expect(page.getByRole('radio', {name: 'Доставка стекла', exact: true})).toBeChecked();
    expect(errors).toEqual([]);
});

test('Kazakh wholesale flow resolves pasted rows and goes to manager', async ({page}, info) => {
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.locator('html')).toHaveAttribute('lang', 'kk');
    await page.getByRole('navigation').getByRole('button', {name: 'Көтерме сатып алу', exact: true}).click();
    await page.getByRole('button', {name: 'Тізімді қою', exact: true}).click();
    const list = page.locator('#preview-list');
    await list.fill('DEMO-1;2\nUNKNOWN;1');
    await page.getByRole('button', {name: 'Тізімді тексеру', exact: true}).click();
    await expect(page.getByText('Жібермес бұрын тізім жолдарын түзетіңіз', {exact: true})).toBeVisible();
    await list.fill('DEMO-1;2\nDEMO-2;1');
    await page.getByRole('button', {name: 'Тізімді тексеру', exact: true}).click();
    await page.getByRole('button', {name: 'Табылған жолдарды қосу (2)', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Жалғастыру', exact: true})).toBeEnabled();
    await expect(page.locator('[data-quote-total]')).toContainText('118 000');
    await capture(page, info, 'wholesale-kk');
    await page.getByRole('button', {name: 'Жалғастыру', exact: true}).click();
    await contacts(page, true);
    await page.getByRole('button', {name: 'Келісуге жіберу', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Өтінім менеджерге жіберілді', exact: true})).toBeVisible();
    await capture(page, info, 'wholesale-result-kk');
});

test('order change remains a request and repetition requires new terms', async ({page}, info) => {
    await openPreviewOrder(page);
    await page.getByRole('button', {name: 'Запросить изменение', exact: true}).click();
    await page.getByLabel('Что нужно изменить', {exact: true}).fill('Изменить адрес доставки');
    await page.getByRole('button', {name: 'Отправить запрос', exact: true}).click();
    await expect(page.locator('main')).toContainText('Заказ пока остаётся без изменений.');
    await capture(page, info, 'order-change-request');
    await page.getByRole('button', {name: 'Повторить заказ', exact: true}).click();
    await contacts(page);
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.getByText('Сначала подтвердите новые условия.', {exact: true})).toBeVisible();
    await capture(page, info, 'repeat-price-changed');
    await page.getByRole('checkbox', {name: 'Принимаю новые условия', exact: true}).check();
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Заказ подтверждён', exact: true})).toBeVisible();
});

test('unknown prices and save failures stay visible and recoverable', async ({page}, info) => {
    await retailSelection(page);
    await page.getByLabel('Состояние для проверки').selectOption('unknown_price');
    await expect(page.locator('[data-quote-total]')).toContainText('Цена уточняется');
    await expect(page.getByRole('button', {name: 'Отправить на согласование', exact: true})).toBeVisible();
    await capture(page, info, 'unknown-price');
    await page.getByLabel('Состояние для проверки').selectOption('out_of_stock');
    await capture(page, info, 'out-of-stock');
    await page.getByLabel('Состояние для проверки').selectOption('save_error');
    await expect(page.getByRole('button', {name: 'Повторить сохранение', exact: true})).toBeVisible();
    await page.getByRole('button', {name: 'Повторить сохранение', exact: true}).click();
    await expect(page.getByRole('status').first()).toContainText('Сохранено');
    await page.getByLabel('Состояние для проверки').selectOption('empty');
    await expect(page.getByRole('heading', {name: 'Пока ничего не выбрано', exact: true})).toBeVisible();
    await capture(page, info, 'empty');
});

test('quantity validation rejects excess and draft tabs keep both modes', async ({page}, info) => {
    await retailSelection(page);
    const quantity = page.getByRole('spinbutton', {name: 'Количество DEMO-1'});
    await quantity.fill('100');
    await quantity.blur();
    await expect(page.locator('[data-quote-total]')).toContainText('Укажите целое количество от 1 до 99.');
    await expect(quantity).toHaveValue('100');
    await quantity.fill('2');
    await quantity.blur();
    await expect(page.locator('[data-quote-total]')).toContainText('70 000');
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await expect(page.getByRole('checkbox', {name: 'Выбрать DEMO-1', exact: true})).not.toBeChecked();
    await page.getByRole('checkbox', {name: 'Выбрать DEMO-2', exact: true}).check();
    await expect(page.locator('[data-quote-total]')).toContainText('48 000');
    await page.getByRole('navigation').getByRole('button', {name: 'Черновики', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Розница', exact: true})).toBeVisible();
    await expect(page.getByRole('heading', {name: 'Оптовая закупка', exact: true})).toBeVisible();
    await capture(page, info, 'separate-drafts');
});
