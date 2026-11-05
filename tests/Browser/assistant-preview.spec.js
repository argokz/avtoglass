import {test, expect} from '@playwright/test';
import {mkdirSync} from 'node:fs';
import {resolve} from 'node:path';

test.setTimeout(180_000);

const bot = page => page.locator('[data-chat-role="assistant"]').last();

async function send(page, text) {
    await page.locator('#chat-input').fill(text);
    await page.locator('#chat-input').press('Enter');
}

async function cameraOffers(page, unknown = false, kk = false) {
    await send(page, 'Camry 2018');
    await bot(page).getByRole('button', {name: kk ? 'Иә, Toyota Camry 70' : 'Да, Toyota Camry 70', exact: true}).click();
    await bot(page).getByRole('button', {name: kk ? (unknown ? 'Білмеймін' : 'Камера жоқ') : (unknown ? 'Не знаю' : 'Нет камеры'), exact: true}).click();
    await expect(bot(page).locator('[data-offer-id]')).toHaveCount(unknown ? 3 : 2);
}

async function checkout(page, kk = false) {
    const drawer = page.getByRole('button', {name: new RegExp(kk ? '^Менің тізімім' : '^Мой подбор')});
    await expect(page.locator('[data-purchase-assistant]')).toBeVisible();
    if (page.viewportSize().width < 1280) {
        await drawer.click();
        await expect(page.getByRole('dialog')).toBeVisible();
    }
    await page.locator('[data-chat-selection]:visible').getByRole('button', {name: kk ? 'Тексеру және тапсырыс беру' : 'Проверить и оформить', exact: true}).click();
    await expect(page.locator('[data-chat-checkout]')).toBeVisible();
    await expect(page.locator('#assistant-checkout')).toBeFocused();
}

async function sidebar(page) {
    const toggle = page.getByRole('complementary', {name: 'Диалоги', exact: true}).getByRole('button', {name: 'Диалоги', exact: true});
    if (await toggle.isVisible() && await toggle.getAttribute('aria-expanded') !== 'true') await toggle.click();
}

async function capture(page, info, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/08-assistant', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.locator('[data-purchase-assistant]').screenshot({path: resolve(directory, `${name}.png`), animations: 'disabled'});
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['localhost', '127.0.0.1']).toContain(url.hostname);
    expect(['8015', '18015']).toContain(url.port);
    await page.goto('/preview/ux?view=chat');
    await expect(page.locator('#chat-input')).toBeVisible();
});

test('chat selection survives shop switch and explicit checkout returns an order to the conversation', async ({page}, info) => {
    await page.locator('#chat-input').fill('Camry');
    await page.locator('#chat-input').press('Shift+Enter');
    await page.locator('#chat-input').press('2');
    await expect(page.locator('#chat-input')).toHaveValue('Camry\n2');
    await expect(page.locator('[data-chat-role="user"]')).toHaveCount(0);
    await cameraOffers(page);
    await bot(page).getByRole('button', {name: 'Выбрать DEMO-1', exact: true}).click();
    await expect(bot(page).getByRole('button', {name: 'Выбрать DEMO-1', exact: true})).toHaveAttribute('aria-pressed', 'true');
    await capture(page, info, 'selected');
    await page.getByRole('button', {name: 'Продолжить в магазине', exact: true}).click();
    await expect(page.locator('[data-purchase-assistant]')).toHaveCount(0);
    await expect(page.getByRole('button', {name: 'Выбрать DEMO-1', exact: true})).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('navigation').getByRole('button', {name: 'Чат-помощник', exact: true}).click();
    await checkout(page);
    await page.locator('#preview-name').fill('Демо покупатель');
    await page.locator('#preview-phone').fill('+77000000001');
    await page.locator('[data-chat-checkout]').getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(page.locator('[data-chat-checkout]')).toContainText('Подтвердите согласие');
    await page.locator('[data-chat-checkout]').getByRole('checkbox', {name: 'Согласен на обработку данных для выполнения заказа', exact: true}).check();
    await page.locator('[data-chat-checkout]').getByRole('button', {name: 'Подтвердить заказ', exact: true}).click();
    await expect(bot(page)).toContainText('DEMO-1043');
    await expect(bot(page)).toContainText('35 000');
    await capture(page, info, 'order-result');
    await page.reload();
    await expect(bot(page)).toContainText('DEMO-1043');
});

test('stop and failed response preserve prompt and retry shows actionable cards', async ({page}, info) => {
    await page.locator('summary').filter({hasText: 'Демонстрация ответа'}).click();
    await page.locator('#chat-scenario').selectOption('slow');
    await send(page, 'DEMO-1');
    await page.getByRole('button', {name: 'Остановить ответ', exact: true}).click();
    await expect(bot(page)).toContainText('Ответ остановлен');
    await expect(bot(page).locator('[data-offer-id]')).toHaveCount(0);
    await expect(page.locator('[data-chat-role="user"]')).toHaveCount(1);
    await page.getByRole('button', {name: 'Повторить ответ', exact: true}).click();
    await expect(bot(page).locator('[data-offer-id="1"]')).toBeVisible();
    await expect(page.locator('[data-chat-role="user"]')).toHaveCount(1);
    const details = page.locator('details').filter({has: page.locator('#chat-scenario')});
    if ((await details.getAttribute('open')) === null) await details.locator('summary').click();
    await page.locator('#chat-scenario').selectOption('error');
    await send(page, 'DEMO-4');
    await expect(bot(page)).toContainText('Ответ прерван');
    await capture(page, info, 'generation-error');
    await page.reload();
    await page.getByRole('button', {name: 'Повторить ответ', exact: true}).click();
    await expect(bot(page).locator('[data-offer-id="4"]')).toBeVisible();
    await expect(page.locator('[data-chat-role="user"]')).toHaveCount(2);
});

test('Kazakh unknown fitment keeps manager review in the shared checkout', async ({page}, info) => {
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.locator('html')).toHaveAttribute('lang', 'kk');
    await cameraOffers(page, true, true);
    await bot(page).locator('[data-offer-id="1"] button').click();
    await checkout(page, true);
    await expect(page.locator('[data-chat-checkout]')).toContainText('жабдықтама');
    await page.locator('#preview-name').fill('Демо сатып алушы');
    await page.locator('#preview-phone').fill('+77000000002');
    await page.locator('[data-chat-checkout] input[type=checkbox]').last().check();
    await page.locator('[data-chat-checkout]').getByRole('button', {name: 'Келісуге жіберу', exact: true}).click();
    await expect(bot(page)).toContainText('DEMO-1043');
    await expect(bot(page)).toContainText('Келісу үстінде');
    await capture(page, info, 'review-result-kk');
});

test('new renamed and archived dialogs preserve selection and stale prices require refresh', async ({page}, info) => {
    await cameraOffers(page);
    const oldCard = bot(page);
    await page.getByLabel('Состояние для проверки').selectOption('changed');
    await expect(oldCard.getByRole('button', {name: 'Выбрать DEMO-1', exact: true})).toBeDisabled();
    await expect(oldCard).toContainText('Историческая карточка');
    await oldCard.getByRole('button', {name: 'Обновить условия', exact: true}).click();
    await expect(bot(page)).toContainText('36 500');
    await bot(page).getByRole('button', {name: 'Выбрать DEMO-1', exact: true}).click();
    await sidebar(page);
    await page.locator('#chat-title').fill('Закупка для Camry');
    await page.getByRole('button', {name: 'Переименовать', exact: true}).click();
    await expect(page.locator('[data-chat-dialog]').filter({hasText: 'Закупка для Camry'})).toBeVisible();
    await page.getByRole('button', {name: 'Новый диалог', exact: true}).click();
    await expect(page.locator('[data-chat-role="user"]')).toHaveCount(0);
    await page.locator('#chat-search').fill('Закупка');
    await page.locator('[data-chat-dialog]').filter({hasText: 'Закупка для Camry'}).click();
    await sidebar(page);
    await page.getByRole('button', {name: 'Архивировать диалог', exact: true}).click();
    await expect(page.locator('#chat-input')).toBeDisabled();
    await page.getByRole('button', {name: 'Вернуть из архива', exact: true}).click();
    await expect(page.locator('#chat-input')).toBeEnabled();
    await capture(page, info, 'restored-dialog');
    await page.getByRole('button', {name: 'Продолжить в магазине', exact: true}).click();
    await expect(page.locator('[data-purchase-assistant]')).toHaveCount(0);
    await expect(page.getByRole('button', {name: 'Выбрать DEMO-1', exact: true})).toHaveAttribute('aria-pressed', 'true');
});
