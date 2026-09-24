import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow, openCommandCenter } from '../helpers/layout.mjs'

test.describe('Product browser workflow', () => {
  test('creates a fractional metre product and connects it to inventory', async ({ page }) => {
    await login(page)
    await page.goto('/products')

    const form = page.locator('form.product-form')
    await form.getByLabel('Category').selectOption({ label: 'Clothing' })
    await form.getByLabel('Name').fill('E2E Fabric Roll')
    await form.getByLabel(/Opening quantity/i).fill('12.375')
    await form.getByRole('textbox', { name: 'Unit', exact: true }).fill('m')
    await form.getByLabel('Min quantity').fill('2.5')
    await form.getByLabel('High stock at').fill('20')
    await form.getByLabel('Purchase Price').fill('3.20')
    await form.getByLabel('Selling Price').fill('5.50')

    const created = page.waitForResponse((response) => response.url().endsWith('/api/products') && response.request().method() === 'POST')
    await form.getByRole('button', { name: 'Save product' }).click()
    expect((await created).ok()).toBeTruthy()
    await expect(page.getByText(/E2E Fabric Roll/).last()).toBeVisible()

    await page.getByPlaceholder('Search by name or SKU').fill('E2E Fabric Roll')
    const row = page.getByRole('row').filter({ hasText: 'E2E Fabric Roll' })
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: /^view$/i }).click()
    const dialog = page.getByRole('dialog', { name: /E2E Fabric Roll/i })
    await expect(dialog).toContainText('12.375')
    await expect(dialog).toContainText(/Main Warehouse/i)
    await expect(dialog).toContainText(/Stock history/i)
    await page.keyboard.press('Escape')

    await page.goto('/stock')
    await expect(page.locator('table').getByRole('row').filter({ hasText: 'E2E Fabric Roll' }).first()).toBeVisible()
  })

  test('@smoke product details stay in the viewport and restore scrolling', async ({ page }) => {
    await login(page)
    await page.goto('/products')
    await expectNoDocumentOverflow(page)

    const viewButton = page.getByRole('button', { name: /^view$/i }).last()
    await viewButton.scrollIntoViewIfNeeded()
    await viewButton.click()

    const dialog = page.getByRole('dialog', { name: /product details|inventory product/i })
    await expect(dialog).toBeVisible()
    const box = await dialog.boundingBox()
    const viewport = page.viewportSize()
    expect(box.y).toBeGreaterThanOrEqual(0)
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1)

    await page.getByRole('button', { name: /close product details/i }).click()
    await expect(dialog).toBeHidden()
    await expect.poll(() => page.evaluate(() => getComputedStyle(document.body).overflowY)).not.toBe('hidden')

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight))
    const before = await page.evaluate(() => window.scrollY)
    await page.mouse.wheel(0, -600)
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeLessThan(before)
  })

  test('@smoke Ctrl+K supports keyboard navigation and Escape', async ({ page }) => {
    await login(page)
    let dialog = await openCommandCenter(page)
    const searchResponse = page.waitForResponse((response) => response.url().includes('/api/search?q='))
    await dialog.getByRole('textbox').fill('laptop')
    const response = await searchResponse
    expect(response.ok(), await response.text()).toBeTruthy()
    await expect(dialog).toContainText(/laptop/i)
    await dialog.getByRole('textbox').press('ArrowDown')
    await dialog.getByRole('textbox').press('Enter')
    await expect(page).toHaveURL(/\/products\?product=/)

    dialog = await openCommandCenter(page)
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
  })
})
