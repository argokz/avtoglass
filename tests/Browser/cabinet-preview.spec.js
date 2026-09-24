import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { signIntoPreviewCabinet, openPreviewOrder } from './helpers/preview-cabinet.js';

test.setTimeout(150_000);

async function capture(page, info, name, fullPage = true) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/05-cabinet', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.screenshot({path: resolve(directory, `${name}.png`), fullPage, animations: 'disabled'});
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(url.port).toBe('8015');
    await page.goto('/preview/ux');
    await expect(page.getByRole('button', {name: 'Начать заново', exact: true})).toBeEnabled();
});

test('OTP gates history, list paginates and URL filters survive reload and detail return', async ({page}, info) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.getByRole('navigation').getByRole('button', {name: 'Мои заказы', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Вход в кабинет', exact: true})).toBeVisible();
    await expect(page.locator('[data-history-order]')).toHaveCount(0);
    await capture(page, info, 'login');
    await signIntoPreviewCabinet(page);
    await expect(page.locator('[data-history-order]')).toHaveCount(20);
    await capture(page, info, 'history-first-page', false);
    await page.getByRole('button', {name: 'Далее', exact: true}).click();
    await expect(page.locator('[data-history-order]')).toHaveCount(5);
    await expect(page).toHaveURL(/page=2/);
    await expect(page.locator('#history-title')).toBeFocused();
    await page.getByLabel('Номер заказа или товар', {exact: true}).fill('DEMO-1042');
    await expect(page.locator('[data-history-order]')).toHaveCount(1);
    await expect(page).toHaveURL(/q=DEMO-1042/);
    await page.reload();
    await expect(page.getByLabel('Номер заказа или товар', {exact: true})).toHaveValue('DEMO-1042');
    await capture(page, info, 'history-filtered');
    await page.getByRole('button', {name: 'Открыть заказ DEMO-1042', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Документы', exact: true})).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', {name: 'Заказ DEMO-1042', exact: true}).first()).toBeVisible();
    await page.getByRole('button', {name: 'Вернуться к списку заказов', exact: true}).click();
    await expect(page.locator('[data-history-order]')).toHaveCount(1);
    await page.getByRole('button', {name: 'Выйти из кабинета', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Вход в кабинет', exact: true})).toBeVisible();
    expect(errors).toEqual([]);
});

test('wrong and expired code keep guest selection and work in Kazakh', async ({page}, info) => {
    await page.getByRole('button', {name: 'Выбрать DEMO-1', exact: true}).click();
    await page.getByLabel('Язык').selectOption('kk');
    await page.getByRole('navigation').getByRole('button', {name: 'Менің тапсырыстарым', exact: true}).click();
    await page.getByRole('button', {name: 'Код алу', exact: true}).click();
    await expect(page.locator('#login-code')).toBeFocused();
    await page.locator('#login-code').fill('000000');
    await page.getByRole('button', {name: 'Кіру', exact: true}).click();
    await expect(page.getByText('Код сәйкес келмеді. Қалған әрекет: 4.', {exact: true})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Жаңа код жіберу', exact: true})).toBeDisabled();
    await page.locator('summary').filter({hasText: 'Тексеру жағдайы'}).click();
    await page.getByRole('button', {name: 'Кодтың мерзімін аяқтау', exact: true}).click();
    await page.locator('#login-code').fill((await page.locator('[data-demo-code]').textContent()).trim());
    await page.getByRole('button', {name: 'Кіру', exact: true}).click();
    await expect(page.getByText('Кодтың мерзімі өткен немесе ол қолданылған. Жаңасын сұраңыз.', {exact: true})).toBeVisible();
    await capture(page, info, 'expired-code-kk');
    await page.getByRole('button', {name: 'Тапсырысты кірмей жалғастыру', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('35 000');
});

test('payment protects editing and cancellation persists in history', async ({page}, info) => {
    await openPreviewOrder(page, 'DEMO-1502');
    await expect(page.getByRole('button', {name: 'Изменить заказ', exact: true})).toHaveCount(0);
    await expect(page.getByText('По заказу есть оплата или незавершённый платёж. Изменения согласуются с менеджером.', {exact: true})).toBeVisible();
    await capture(page, info, 'payment-blocked');
    await openPreviewOrder(page, 'DEMO-1501');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', {name: 'Отменить заявку', exact: true}).click();
    await expect(page.getByText('Покупатель отменил заявку', {exact: true})).toBeVisible();
    await page.reload();
    await expect(page.getByText('Покупатель отменил заявку', {exact: true})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Отменить заявку', exact: true})).toHaveCount(0);
    await capture(page, info, 'cancelled-order');
});
