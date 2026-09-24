import {test, expect} from '@playwright/test';
import {mkdirSync} from 'node:fs';
import {resolve} from 'node:path';
import {openPreviewOrder} from './helpers/preview-cabinet.js';

test.setTimeout(180_000);

async function capture(page, info, name, selector = '[data-manager-proposal]') {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/07-settlement', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.locator(selector).screenshot({path: resolve(directory, `${name}.png`), animations: 'disabled'});
}

async function requestChange(page) {
    await page.getByRole('button', {name: 'Запросить изменение', exact: true}).click();
    await page.locator('#preview-change').fill('Изменить состав и получение');
    await page.getByRole('button', {name: 'Отправить запрос', exact: true}).click();
    await page.getByRole('button', {name: 'Подготовить изменённое предложение', exact: true}).click();
    await expect(page.locator('#manager-editor-title')).toBeFocused();
}

async function paid(page) {
    const summary = page.locator('[data-order-settlement] summary');
    await summary.click();
    await page.getByRole('button', {name: 'Демо: исходный заказ оплачен', exact: true}).click();
    await expect(page.locator('[data-order-settlement]')).toContainText('Оплачен');
}

async function sendTerms(page) {
    await page.locator('[data-manager-editor] input[type=checkbox]').last().check();
    await page.getByRole('button', {name: 'Показать предложение покупателю', exact: true}).click();
    await expect(page.locator('[data-manager-proposal]')).toBeVisible();
}

async function acceptTerms(page) {
    const proposal = page.locator('[data-manager-proposal]');
    await proposal.getByRole('checkbox').check();
    await proposal.getByRole('button', {name: 'Принять условия и подтвердить заказ', exact: true}).click();
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['localhost', '127.0.0.1']).toContain(url.hostname);
    expect(url.port).toBe('8015');
    await page.goto('/preview/ux');
});

test('replacement and delivery create only a supplement after accepting exact terms', async ({page}, info) => {
    await openPreviewOrder(page);
    await paid(page);
    await requestChange(page);
    await page.locator('#manager-offer-1').selectOption('4');
    await page.locator('#manager-fulfillment').selectOption('delivery');
    await page.locator('#manager-address').fill('Караганда, Новый адрес, 5');
    await sendTerms(page);
    await expect(page.locator('[data-proposal-before]')).toContainText('XYG');
    await expect(page.locator('[data-proposal-after]')).toContainText('FYG');
    await expect(page.locator('[data-proposal-after]')).toContainText('Новый адрес, 5');
    await expect(page.locator('[data-proposal-settlement]')).toContainText('7 000');
    await capture(page, info, 'replacement-delivery');
    await acceptTerms(page);
    await expect(page.locator('[data-settlement-due]')).toContainText('7 000');
    await page.getByRole('button', {name: 'Демо: начать оплату остатка', exact: true}).click();
    await expect(page.locator('[data-payment-attempt]')).toContainText('7 000');
    await page.getByRole('button', {name: 'Демо: платёж не прошёл', exact: true}).click();
    await expect(page.locator('[data-settlement-paid]')).toContainText('35 000');
    await page.getByRole('button', {name: 'Демо: начать оплату остатка', exact: true}).click();
    await page.getByRole('button', {name: 'Демо: платёж успешен', exact: true}).click();
    await expect(page.locator('[data-settlement-paid]')).toContainText('42 000');
    await capture(page, info, 'supplement-paid', '[data-order-settlement]');
});

test('refund remains pending through failure and survives reload after successful retry', async ({page}, info) => {
    await openPreviewOrder(page, 'DEMO-1501');
    await page.getByRole('button', {name: 'Подтверждён', exact: true}).click();
    await paid(page);
    await requestChange(page);
    await page.locator('#manager-offer-2').selectOption('1');
    await sendTerms(page);
    await acceptTerms(page);
    await expect(page.locator('[data-refund]')).toContainText('ожидает решения');
    await expect(page.locator('[data-settlement-refund]')).toContainText('13 000');
    await page.getByRole('button', {name: 'Демо: сотрудник начал возврат', exact: true}).click();
    await expect(page.locator('[data-refund]')).toContainText('Возврат обрабатывается');
    await page.getByRole('button', {name: 'Демо: возврат не прошёл', exact: true}).click();
    await expect(page.locator('[data-refund]')).toContainText('Возврат не выполнен');
    await expect(page.locator('[data-settlement-net]')).toContainText('48 000');
    await capture(page, info, 'refund-failed', '[data-order-settlement]');
    await page.getByRole('button', {name: 'Демо: сотрудник начал возврат', exact: true}).click();
    await page.getByRole('button', {name: 'Демо: возврат успешен', exact: true}).click();
    await expect(page.locator('[data-settlement-refunded]')).toContainText('13 000');
    await page.reload();
    await expect(page.locator('[data-settlement-paid]')).toContainText('48 000');
    await expect(page.locator('[data-settlement-net]')).toContainText('35 000');
    await expect(page.locator('[data-refund]')).toContainText('Возврат выполнен');
    await capture(page, info, 'refund-complete', '[data-order-settlement]');
});

test('unavailable installation time blocks acceptance and Kazakh receipt shows a new slot', async ({page}, info) => {
    await openPreviewOrder(page);
    await requestChange(page);
    await page.locator('#manager-fulfillment').selectOption('visit');
    await page.locator('#manager-address').fill('Караганда, Выездной адрес, 8');
    await page.locator('#manager-slot').selectOption({index: 1});
    await sendTerms(page);
    await expect(page.locator('[data-proposal-after]')).toContainText('10:00');
    await page.getByRole('button', {name: 'Демо: выбранное время стало недоступно', exact: true}).click();
    await expect(page.locator('[data-manager-proposal]')).toContainText('Время установки недоступно');
    await expect(page.locator('[data-manager-proposal]').getByRole('button', {name: 'Принять условия и подтвердить заказ', exact: true})).toBeDisabled();
    await capture(page, info, 'slot-unavailable');
    await page.getByRole('button', {name: 'Подготовить изменённое предложение', exact: true}).click();
    await page.locator('#manager-fulfillment').selectOption('installation');
    await page.locator('#manager-slot').selectOption({index: 2});
    await sendTerms(page);
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.locator('html')).toHaveAttribute('lang', 'kk');
    const proposal = page.locator('[data-manager-proposal]');
    await expect(page.locator('[data-proposal-after]')).toContainText('14:00');
    await proposal.getByRole('checkbox').check();
    await proposal.getByRole('button', {name: 'Шарттарды қабылдап, тапсырысты растау', exact: true}).click();
    await expect(proposal).toContainText('Келісілген есеп дайын');
    await capture(page, info, 'slot-accepted-kk');
});

test('new proposal in another tab requires loading it and renewed consent', async ({page, context}, info) => {
    await openPreviewOrder(page);
    await page.getByRole('button', {name: 'На согласовании', exact: true}).click();
    await page.getByRole('button', {name: 'Прислать полное предложение', exact: true}).click();
    const other = await context.newPage();
    await other.goto('/preview/ux?view=order&order=DEMO-1042');
    await other.getByRole('button', {name: 'Прислать полное предложение', exact: true}).click();
    await expect(other.locator('[data-manager-proposal]')).toContainText('Версия 2');
    await other.close();
    await acceptTerms(page);
    await expect(page.locator('[data-manager-proposal]')).toContainText('Заказ или предложение изменились');
    await page.getByRole('button', {name: 'Показать актуальный заказ и предложение', exact: true}).click();
    await expect(page.locator('[data-manager-proposal]')).toContainText('Версия 2');
    await expect(page.locator('[data-manager-proposal]').getByRole('checkbox')).not.toBeChecked();
    await capture(page, info, 'new-version-loaded');
    await acceptTerms(page);
    await expect(page.locator('[data-manager-proposal]')).toContainText('Согласованный расчёт готов');
});
