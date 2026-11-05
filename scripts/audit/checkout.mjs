import { chromium } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
const base = 'http://127.0.0.1:18016';
const response = await fetch(`${base}/preview/health`);
if (!response.ok || (await response.json()).preview !== 'avtoglass-isolated-v1') throw new Error('Isolated preview required');
const access = JSON.parse(readFileSync('.codex/modernization/preview-access.json', 'utf8'));
const browser = await chromium.launch(); const page = await browser.newPage();
await page.goto(`${base}/vhod`);
await page.locator('main').getByLabel('Телефон', { exact: true }).fill(access.retail_phone);
await page.locator('main').getByLabel('Пароль', { exact: true }).fill(access.password);
await page.getByRole('button', { name: 'Войти', exact: true }).click(); await page.waitForURL(/\/kabinet/);
await page.goto(`${base}/tovar/preview-camry-70`);
await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
await page.goto(`${base}/korzina`);
const form = page.locator('[data-order-form]');
const collapsed = form.locator('[data-contacts-collapsed]');
if (await collapsed.count()) await collapsed.locator('summary').click();
await form.locator('[name="name"]').fill('Демо аудит');
await form.locator('[name="phone"]').fill(access.retail_phone);
await form.locator('[name="fulfillment"][value="pickup"]').check();
const fields = await form.evaluate(form => Object.fromEntries(new FormData(form)));
fields.privacy_consent = '1'; fields.comment = 'Изолированный аудит: проверка повторной отправки';
const action = await form.getAttribute('action');
const started = performance.now();
const replies = await Promise.all([page.request.post(action, { form: fields, maxRedirects: 0 }), page.request.post(action, { form: fields, maxRedirects: 0 })]);
const errors = await Promise.all(replies.map(async response => {
    if (response.status() < 500) return null;
    const text = await response.text();
    return { uniqueFormTokenConstraint: text.includes('orders_form_token_unique'), uniqueViolation: text.includes('23505'), databaseConnectionFailure: text.includes('08006') };
}));
// Same retained payload after the successful response was hypothetically lost.
const retry = await page.request.post(action, { form: fields, maxRedirects: 0 });
writeFileSync('output/playwright/audit-2026-10-09/checkout-repeat.json', JSON.stringify({ formToken: fields.form_token, wallMs: performance.now() - started,
    responses: replies.map((r, i) => ({ status: r.status(), location: r.headers().location, error: errors[i] })),
    replayAfterCartCleared: { status: retry.status(), location: retry.headers().location } }, null, 2));
await browser.close();
