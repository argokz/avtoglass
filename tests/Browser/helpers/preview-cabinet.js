import { expect } from '@playwright/test';

export async function signIntoPreviewCabinet(page, kk = false) {
    await page.getByRole('navigation').getByRole('button', {name: kk ? 'Менің тапсырыстарым' : 'Мои заказы', exact: true}).click();
    await expect(page.locator('[data-order-history], #login-phone')).toBeVisible();
    if (await page.locator('#login-phone').isVisible()) {
        await page.getByRole('button', {name: kk ? 'Код алу' : 'Получить код', exact: true}).click();
        await expect(page.locator('#login-code')).toBeVisible();
        await page.locator('#login-code').fill((await page.locator('[data-demo-code]').textContent()).trim());
        await page.getByRole('button', {name: kk ? 'Кіру' : 'Войти', exact: true}).click();
    }
    await expect(page.locator('[data-order-history]')).toBeVisible();
}

export async function openPreviewOrder(page, number = 'DEMO-1042', kk = false) {
    await signIntoPreviewCabinet(page, kk);
    await page.getByLabel(kk ? 'Тапсырыс нөмірі немесе тауар' : 'Номер заказа или товар', {exact: true}).fill(number);
    await page.getByRole('button', {name: kk ? `${number} тапсырысын ашу` : `Открыть заказ ${number}`, exact: true}).click();
    await expect(page.getByRole('button', {name: kk ? 'Тапсырыстар тізіміне оралу' : 'Вернуться к списку заказов', exact: true})).toBeVisible();
}
