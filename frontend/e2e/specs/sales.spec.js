import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'

test.describe('Sales and inventory connection', () => {
  test('saving a sale immediately reduces available inventory once', async ({ page }) => {
    await login(page)
    await page.goto('/daily-sales')
    await page.getByRole('button', { name: /Add sale/i }).click()

    const editor = page.locator('.daily-sale-editor')
    const row = editor.locator('tbody tr').first()
    const productSelect = row.locator('select').first()
    const productId = await productSelect.locator('option').filter({ hasText: 'Laptop Stand' }).getAttribute('value')
    await productSelect.selectOption(productId)
    const numberFields = row.locator('input[type="number"]')
    await numberFields.nth(0).fill('1')
    await numberFields.nth(1).fill('29.99')

    const createResponse = page.waitForResponse((response) => response.url().endsWith('/api/daily-sales') && response.request().method() === 'POST')
    await editor.getByRole('button', { name: /Save sale/i }).click()
    const created = await createResponse
    expect(created.status(), await created.text()).toBe(201)
    await expect(page.locator('.success-banner')).toContainText(/saved/i)

    await page.goto('/products')
    await page.getByPlaceholder('Search by name or SKU').fill('Laptop Stand')
    const productRow = page.getByRole('row').filter({ hasText: 'Laptop Stand' })
    await productRow.getByRole('button', { name: /^view$/i }).click()
    const detail = page.getByRole('dialog', { name: /Laptop Stand/i })
    await expect(detail.locator('.product-detail-stock-grid article.is-primary')).toContainText('21')
    await expect(detail).toContainText(/Stock out/i)
  })
})
