import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { openPreviewOrder } from './helpers/preview-cabinet.js';

test.setTimeout(120_000);

async function capture(page, info, name) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const directory = resolve('.codex/modernization/screenshots/03-review-prototypes', info.project.name);
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

test('import reviews duplicates and unresolved rows before explicit application', async ({page}, info) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await page.getByRole('button', {name: 'Вставить список', exact: true}).click();
    await page.locator('#preview-list').fill('DEMO-CAMRY;2\nDEMO-4;1\nUNKNOWN;1');
    await page.getByRole('button', {name: 'Проверить список', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('0');
    await page.getByRole('button', {name: 'Выбрать FYG для строки 1', exact: true}).click();
    await expect(page.locator('[data-import-row="1"]')).toContainText('После объединения с подбором: 3 шт.');
    await capture(page, info, 'import-preview');
    await page.getByRole('button', {name: 'Добавить найденные строки (2)', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('117 000');
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeDisabled();
    const unresolved = page.locator('[data-import-row="3"]');
    await unresolved.getByLabel('Артикул', {exact: true}).fill('DEMO-2');
    await unresolved.getByRole('button', {name: 'Проверить строку', exact: true}).click();
    await page.getByRole('button', {name: 'Добавить найденные строки (1)', exact: true}).click();
    await expect(page.locator('[data-quote-total]')).toContainText('165 000');
    await expect(page.getByRole('button', {name: 'Продолжить', exact: true})).toBeEnabled();
    await page.reload();
    await page.getByRole('navigation').getByRole('button', {name: 'Оптовая закупка', exact: true}).click();
    await expect(page.locator('[data-import-row="3"]')).toContainText('Добавлено в подбор');
    await expect(page.locator('[data-quote-total]')).toContainText('165 000');
    await capture(page, info, 'import-applied');
    expect(errors).toEqual([]);
});

test('manager proposal requires consent and preserves order until acceptance', async ({page}, info) => {
    await openPreviewOrder(page);
    await page.getByRole('button', {name: 'На согласовании', exact: true}).click();
    await page.getByRole('button', {name: 'Прислать полное предложение', exact: true}).click();
    const proposal = page.locator('[data-manager-proposal]');
    await expect(proposal).toContainText('35 000');
    await expect(proposal).toContainText('36 500');
    await proposal.getByRole('button', {name: 'Принять условия и подтвердить заказ', exact: true}).click();
    await expect(proposal.getByText('Подтвердите согласие с этой версией предложения.', {exact: true})).toBeVisible();
    await capture(page, info, 'proposal-consent');
    await proposal.getByRole('checkbox').check();
    await proposal.getByRole('button', {name: 'Принять условия и подтвердить заказ', exact: true}).click();
    await expect(proposal.getByRole('heading', {name: 'Согласованный расчёт готов', exact: true})).toBeVisible();
    await expect(proposal.getByRole('button', {name: 'Принять условия и подтвердить заказ', exact: true})).toHaveCount(0);
    await capture(page, info, 'proposal-accepted');
});

test('unavailable expired and conflicting proposals keep payment unavailable', async ({page}, info) => {
    await openPreviewOrder(page);
    await page.getByRole('button', {name: 'На согласовании', exact: true}).click();
    const proposal = page.locator('[data-manager-proposal]');
    const accept = proposal.getByRole('button', {name: 'Принять условия и подтвердить заказ', exact: true});
    for (const [action, text] of [
        ['Предложение с ожиданием товара', 'Полного количества пока нет.'],
        ['Истёкшее предложение', 'Срок предложения истёк.'],
    ]) {
        await page.getByRole('button', {name: action, exact: true}).click();
        await expect(proposal).toContainText(text);
        await expect(accept).toBeDisabled();
    }
    await capture(page, info, 'proposal-expired');
    for (const [action, text, version] of [
        ['Наличие изменится при принятии', 'При подтверждении изменилось наличие.', 3],
        ['Конфликт версии при принятии', 'Заказ или предложение изменились.', 4],
    ]) {
        await page.getByRole('button', {name: action, exact: true}).click();
        await expect(proposal).toContainText(`Версия ${version}`);
        await proposal.getByRole('checkbox').check();
        await accept.click();
        await expect(proposal).toContainText(text);
        await expect(proposal.getByRole('heading', {name: 'Согласованный расчёт готов', exact: true})).toHaveCount(0);
    }
    await capture(page, info, 'proposal-conflict');
    await proposal.getByRole('button', {name: 'Запросить новые условия', exact: true}).click();
    await expect(proposal).toContainText('Запрошено новое предложение.');
});

test('Kazakh proposal decline keeps original terms', async ({page}, info) => {
    await page.getByLabel('Язык').selectOption('kk');
    await openPreviewOrder(page, 'DEMO-1042', true);
    await page.getByRole('button', {name: 'Келісу үстінде', exact: true}).click();
    await page.getByRole('button', {name: 'Толық ұсыныс жіберу', exact: true}).click();
    const proposal = page.locator('[data-manager-proposal]');
    await proposal.getByLabel('Ұсыныста нені өзгерту керек', {exact: true}).fill('Басқа өндірушіні ұсынамын');
    await proposal.getByRole('button', {name: 'Қабылдамай, ескертулерді жіберу', exact: true}).click();
    await expect(proposal).toContainText('Ұсыныс қабылданбады.');
    await expect(proposal.getByRole('heading', {name: 'Келісілген есеп дайын', exact: true})).toHaveCount(0);
    await capture(page, info, 'proposal-declined-kk');
});
