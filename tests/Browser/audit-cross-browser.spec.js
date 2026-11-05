import { test, expect } from '@playwright/test';

test('core purchase path and keyboard access work across browser engines', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('link', { name: 'Перейти к содержимому' })).toBeFocused();
    await page.getByLabel('Марка', { exact: true }).selectOption({ label: 'Toyota' });
    await expect(page.getByLabel('Модель', { exact: true })).toBeEnabled();
    await page.getByLabel('Модель', { exact: true }).selectOption({ label: 'Camry 70' });
    await page.getByLabel('Год', { exact: true }).fill('2018');
    await page.getByRole('button', { name: 'Показать стёкла' }).click();
    await expect(page).toHaveURL(/catalog\/toyota\/camry-70\?year=2018/);
    await page.goto('/tovar/preview-camry-70');
    await page.getByRole('complementary').getByRole('button', { name: 'В корзину', exact: true }).click();
    await page.goto('/korzina');
    await expect(page.locator('main')).toContainText('Лобовое стекло Toyota Camry 70');
    await expect(page.getByRole('button', { name: 'Оформить заказ', exact: true })).toBeVisible();
    await page.getByLabel('Тема', { exact: true }).selectOption('dark');
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});
