import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { signIntoPreviewCabinet, openPreviewOrder } from './helpers/preview-cabinet.js';

test.setTimeout(180_000);

async function capture(page, info, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/06-preferences', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.screenshot({path: resolve(directory, `${name}.png`), fullPage: true, animations: 'disabled'});
}

async function account(page, section) {
    await page.getByRole('navigation').getByRole('button', {name: 'Кабинет', exact: true}).click();
    await page.getByRole('navigation', {name: 'Разделы кабинета', exact: true}).getByRole('button', {name: section, exact: true}).click();
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(url.port).toBe('8015');
    await page.goto('/preview/ux');
    await expect(page.getByRole('button', {name: 'Начать заново', exact: true})).toBeEnabled();
});

test('profile and address are saved and selected explicitly for checkout', async ({page}, info) => {
    await signIntoPreviewCabinet(page);
    await account(page, 'Профиль');
    await page.locator('#profile-name').fill('Демо покупатель');
    await page.locator('#profile-email').fill('demo@example.test');
    await page.getByRole('button', {name: 'Сохранить профиль', exact: true}).click();
    await expect(page.getByText('Профиль сохранён.', {exact: true})).toBeVisible();
    await account(page, 'Адреса');
    await page.locator('#address-label').fill('Склад');
    await page.locator('#address-city').fill('Караганда');
    await page.locator('#address-line').fill('Демонстрационная улица, 1');
    await page.locator('#address-recipient').fill('Демо получатель');
    await page.locator('#address-phone').fill('+77000000003');
    await page.getByRole('button', {name: 'Сохранить адрес', exact: true}).click();
    await expect(page.locator('[data-customer-address="1"]')).toContainText('Основной адрес');
    await page.reload();
    await expect(page.locator('[data-customer-address="1"]')).toContainText('Демо получатель');
    await capture(page, info, 'saved-address');
    await page.getByRole('navigation').getByRole('button', {name: 'Каталог', exact: true}).click();
    await page.getByRole('button', {name: 'Выбрать DEMO-1', exact: true}).click();
    await page.getByRole('navigation').getByRole('button', {name: /^Оформление/}).click();
    await page.getByRole('radio', {name: 'Доставка стекла', exact: true}).check();
    await page.locator('summary').filter({hasText: 'Выбрать сохранённый адрес'}).click();
    await page.getByRole('button', {name: 'Использовать адрес Склад', exact: true}).click();
    await expect(page.getByLabel('Адрес', {exact: true})).toHaveValue('Караганда, Демонстрационная улица, 1');
    await expect(page.getByLabel('Ваше имя', {exact: true})).toHaveValue('Демо получатель');
    await page.getByRole('checkbox', {name: 'Согласен на обработку данных для выполнения заказа', exact: true}).check();
    await page.getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Заказ подтверждён', exact: true})).toBeVisible();
});

test('template preserves previous selection and approval requires acceptance of new prices', async ({page}, info) => {
    await signIntoPreviewCabinet(page);
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await page.getByRole('checkbox', {name: 'Выбрать DEMO-1', exact: true}).check();
    await page.getByLabel('Количество DEMO-1', {exact: true}).fill('2');
    await page.getByLabel('Количество DEMO-1', {exact: true}).blur();
    await expect(page.locator('[data-quote-total]')).toContainText('70 000');
    await account(page, 'Шаблоны закупок');
    await page.locator('#template-name').fill('Плановая закупка');
    await page.getByRole('button', {name: 'Сохранить текущий оптовый подбор', exact: true}).click();
    await expect(page.locator('[data-purchase-template="1"]')).toContainText('Плановая закупка');
    await capture(page, info, 'template-saved');
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await page.getByRole('checkbox', {name: 'Выбрать DEMO-1', exact: true}).uncheck();
    await page.getByRole('checkbox', {name: 'Выбрать DEMO-4', exact: true}).check();
    await account(page, 'Шаблоны закупок');
    await page.getByRole('button', {name: 'Создать подбор из шаблона', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('70 000');
    await account(page, 'Оптовый доступ');
    await page.locator('#company-name').fill('Демо организация');
    await page.getByRole('button', {name: 'Запросить оптовый доступ', exact: true}).click();
    await expect(page.locator('[data-wholesale-access]')).toContainText('рассматривается');
    await page.locator('summary').filter({hasText: 'Состояние для проверки'}).click();
    await page.getByRole('button', {name: 'Пример одобрения доступа', exact: true}).click();
    await expect(page.locator('[data-wholesale-access]')).toContainText('одобрен');
    await capture(page, info, 'wholesale-approved');
    await page.getByRole('navigation').getByRole('button', {name: /^Оформление/}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('64 000');
    await expect(page.getByText('Оптовые условия изменились', {exact: true})).toBeVisible();
    await page.getByLabel('Ваше имя', {exact: true}).fill('Демо покупатель');
    await page.getByLabel('Телефон', {exact: true}).fill('+77000000001');
    await page.getByRole('checkbox', {name: 'Согласен на обработку данных для выполнения заказа', exact: true}).check();
    await page.getByRole('button', {name: 'Отправить на согласование', exact: true}).click();
    await expect(page.getByText('Сначала подтвердите новые условия.', {exact: true})).toBeVisible();
    await page.getByRole('checkbox', {name: 'Принимаю новые условия', exact: true}).check();
    await page.getByRole('button', {name: 'Отправить на согласование', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Заявка отправлена менеджеру', exact: true})).toBeVisible();
});

test('editing resumes after reload and conflicting version keeps local quantities', async ({page, context}, info) => {
    await openPreviewOrder(page, 'DEMO-1501');
    await page.getByRole('button', {name: 'Изменить заказ', exact: true}).click();
    await page.getByLabel('Количество DEMO-2', {exact: true}).fill('3');
    await page.getByLabel('Количество DEMO-2', {exact: true}).blur();
    await expect(page.locator('[data-quote-total]')).toContainText('144 000');
    await page.reload();
    await expect(page.getByText('Редактирование DEMO-1501 · исходная версия 1', {exact: true})).toBeVisible();
    await expect(page.getByLabel('Количество DEMO-2', {exact: true})).toHaveValue('3');
    await capture(page, info, 'editing-restored');
    const manager = await context.newPage();
    await manager.goto('/preview/ux?view=order&order=DEMO-1501');
    await manager.getByRole('button', {name: 'Можно изменить', exact: true}).click();
    await expect(manager.getByRole('button', {name: 'Можно изменить', exact: true})).toHaveAttribute('aria-pressed', 'true');
    await manager.close();
    await page.reload();
    await expect(page.getByText(/Ваш ввод сохранён; прежнюю версию/)).toBeVisible();
    await expect(page.getByLabel('Количество DEMO-2', {exact: true})).toHaveValue('3');
    await capture(page, info, 'editing-conflict');
    await page.getByRole('button', {name: 'Сохранить как новый подбор', exact: true}).click();
    await expect(page.getByText('Подбор отделён от редактирования. Исходный заказ не изменён.', {exact: true})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Сохранить изменения', exact: true})).toHaveCount(0);
});

test('logout hides saved profile and another phone has empty preferences', async ({page}, info) => {
    await signIntoPreviewCabinet(page);
    await account(page, 'Профиль');
    await page.locator('#profile-name').fill('Личный профиль');
    await page.getByRole('button', {name: 'Сохранить профиль', exact: true}).click();
    await expect(page.getByText('Профиль сохранён.', {exact: true})).toBeVisible();
    await page.getByRole('button', {name: 'Выйти из кабинета', exact: true}).click();
    await page.getByRole('navigation').getByRole('button', {name: 'Кабинет', exact: true}).click();
    await page.locator('#login-phone').fill('+77000000002');
    await page.getByRole('button', {name: 'Получить код', exact: true}).click();
    await page.locator('#login-code').fill((await page.locator('[data-demo-code]').textContent()).trim());
    await page.getByRole('button', {name: 'Войти', exact: true}).click();
    await expect(page.locator('#profile-name')).toHaveValue('');
    await expect(page.getByText('Личный профиль', {exact: true})).toHaveCount(0);
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.locator('html')).toHaveAttribute('lang', 'kk');
    await capture(page, info, 'profile-other-owner-kk');
});
