import { test, expect } from '@playwright/test';
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { openPreviewOrder } from './helpers/preview-cabinet.js';

test.setTimeout(150_000);

async function capture(page, info, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/04-file-repeat', info.project.name);
    mkdirSync(directory, {recursive: true});
    await page.screenshot({path: resolve(directory, `${name}.png`), fullPage: true, animations: 'disabled'});
}

test.beforeEach(async ({page, baseURL}) => {
    const url = new URL(baseURL);
    expect(['127.0.0.1', 'localhost']).toContain(url.hostname);
    expect(url.port).toBe('8015');
    await page.goto('/preview/ux');
    await expect(page.getByRole('button', {name: 'Начать заново', exact: true})).toBeEnabled();
});

test('XLSX template downloads and uploads into review before application', async ({page}, info) => {
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await page.getByRole('button', {name: 'Загрузить файл', exact: true}).click();
    const download = page.waitForEvent('download');
    await page.getByRole('button', {name: 'Шаблон XLSX', exact: true}).click();
    const template = await download;
    expect(template.suggestedFilename()).toBe('purchase-template.xlsx');
    await page.getByLabel('Файл закупки', {exact: true}).setInputFiles({
        name: 'purchase-template.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: readFileSync(await template.path()),
    });
    const review = page.getByRole('button', {name: 'Проверить файл', exact: true});
    await expect(review).toBeEnabled();
    await review.click();
    await expect(page.locator('[data-import-row="2"]')).toContainText('FYG');
    await expect(page.locator('[data-quote-total]')).toContainText('0');
    await capture(page, info, 'xlsx-reviewed');
    await page.getByRole('button', {name: 'Добавить найденные строки (2)', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('126 000');
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeEnabled();
    const reportDownload = page.waitForEvent('download');
    await page.getByRole('button', {name: 'Скачать отчёт проверки', exact: true}).click();
    const report = await reportDownload;
    expect(readFileSync(await report.path(), 'utf8')).toContain('Добавлено в подбор');
    await capture(page, info, 'xlsx-applied');
});

test('bad file preserves selection and CSV review retains unresolved rows in Kazakh', async ({page}, info) => {
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await page.getByRole('checkbox', {name: 'Выбрать DEMO-1', exact: true}).check();
    await page.getByRole('button', {name: 'Загрузить файл', exact: true}).click();
    await page.getByLabel('Файл закупки', {exact: true}).setInputFiles({name: 'broken.xlsx', mimeType: 'application/zip', buffer: Buffer.from('invalid archive')});
    await expect(page.getByRole('button', {name: 'Проверить файл', exact: true})).toBeEnabled();
    await page.getByRole('button', {name: 'Проверить файл', exact: true}).click();
    await expect(page.getByText('Не удалось прочитать файл. Проверьте формат и сохраните его заново.', {exact: true})).toBeVisible();
    await expect(page.locator('[data-quote-total]')).toContainText('35 000');
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeDisabled();
    await page.getByLabel('Язык').selectOption('kk');
    await expect(page.getByRole('button', {name: 'Файлды тексеру', exact: true})).toBeVisible();
    let finishUpload;
    const uploadGate = new Promise(resolve => { finishUpload = resolve; });
    await page.route(/\/upload-file(?:\?|$)/, async route => {
        await uploadGate;
        await route.continue();
    });
    try {
        await page.getByLabel('Сатып алу файлы', {exact: true}).setInputFiles({name: 'purchase.csv', mimeType: 'text/csv', buffer: Buffer.from('Еурокод;Саны;Өндіруші;Ескерту\nDEMO-CAMRY;2;FYG;тексеру\nUNKNOWN;1;;табылмады', 'utf8')});
        await expect(page.getByRole('button', {name: 'Файлды тексеру', exact: true})).toBeDisabled();
    } finally {
        finishUpload();
    }
    await expect(page.getByRole('button', {name: 'Файлды тексеру', exact: true})).toBeEnabled();
    await page.getByRole('button', {name: 'Файлды тексеру', exact: true}).click();
    await expect(page.locator('[data-import-row="3"]')).toContainText('Тауар табылмады');
    await page.getByRole('button', {name: 'Табылған жолдарды қосу (1)', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('113 000');
    await expect(page.getByRole('button', {name: 'Жалғастыру', exact: true})).toBeDisabled();
    await capture(page, info, 'csv-unresolved-kk');
});

test('repeat keeps missing lines until explicit replacement and recalculates terms', async ({page}, info) => {
    await openPreviewOrder(page);
    await page.getByRole('button', {name: 'Пример заказа с удалённой позицией', exact: true}).click();
    await expect(page.locator('main')).toContainText('140 000');
    await page.getByRole('button', {name: 'Повторить заказ', exact: true}).click();
    await expect(page.locator('[data-missing-row="99"]')).toContainText('OLD-CAMRY');
    await expect(page.locator('[data-quote-total]')).toContainText('Цена уточняется');
    await capture(page, info, 'repeat-missing');
    await page.locator('summary').filter({hasText: 'Подобрать замену'}).click();
    await page.getByRole('button', {name: 'Выбрать замену DEMO-4', exact: true}).click();
    await expect(page.locator('[data-missing-row="99"]')).toHaveCount(0);
    await expect(page.locator('[data-quote-total]')).toContainText('156 500');
    await openPreviewOrder(page, 'DEMO-2090');
    await expect(page.locator('main')).toContainText('140 000');
    await expect(page.locator('main')).toContainText('Архивное предложение стекла Camry');
    await page.getByRole('navigation').getByRole('button', {name: /^Оформление/}).click();
    await page.getByLabel('Ваше имя', {exact: true}).fill('Демо клиент');
    await page.getByLabel('Телефон', {exact: true}).fill('+77000000001');
    await page.getByRole('checkbox', {name: 'Согласен на обработку данных для выполнения заказа', exact: true}).check();
    await page.getByRole('checkbox', {name: 'Принимаю новые условия', exact: true}).check();
    await capture(page, info, 'repeat-replaced');
    await page.getByRole('button', {name: 'Отправить на согласование', exact: true}).click();
    await expect(page.getByRole('heading', {name: 'Заявка отправлена менеджеру', exact: true})).toBeVisible();
});
