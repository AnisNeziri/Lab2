import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

test.describe('Sales and inventory connection', () => {
  test('phone sale entry protects edits and reduces available inventory once', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 900 })
    await login(page)
    await page.goto('/daily-sales')
    const addSale = page.getByRole('button', { name: 'Add sale', exact: true })
    await addSale.click()

    const editor = page.getByRole('dialog', { name: 'Add sale' })
    await expect(editor).toBeVisible()
    const { x, width } = await editor.boundingBox()
    expect(x).toBeGreaterThanOrEqual(12)
    expect(x + width).toBeLessThanOrEqual(756)
    await page.setViewportSize({ width: 390, height: 900 })
    await expectNoDocumentOverflow(page, 2, editor.getByRole('heading', { name: 'Add sale' }))
    const row = editor.getByRole('group', { name: 'Line 1' })
    const productSelect = row.getByRole('combobox', { name: 'Product', exact: true })
    const [productId] = await productSelect.selectOption({ label: 'Laptop Stand · ELEC-007' })
    const token = await page.evaluate(() => localStorage.getItem('api_token'))
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    async function availableStock() {
      const response = await page.request.get(`/api/products/${productId}`, { headers })
      expect(response.ok()).toBe(true)
      const { product } = await response.json()
      return Number(product.available_quantity ?? product.inventory?.available ?? product.quantity)
    }
    const before = await availableStock()
    await row.getByLabel('Quantity', { exact: true }).fill('1')
    const price = row.getByLabel('Price', { exact: true })
    await price.fill('29.99')
    await page.keyboard.press('Escape')
    const discard = page.getByRole('dialog', { name: 'Discard unsaved changes?' })
    await discard.getByRole('button', { name: 'Keep Editing' }).click()
    await expect(price).toHaveValue('29.99')
    expect(await availableStock()).toBe(before)

    const createResponse = page.waitForResponse((response) => response.url().endsWith('/api/daily-sales') && response.request().method() === 'POST')
    await editor.getByRole('button', { name: /Save sale/i }).click()
    const created = await createResponse
    expect(created.status(), await created.text()).toBe(201)
    const feedback = page.getByRole('status').filter({ hasText: /sale saved/i })
    await expect(feedback).toBeVisible()
    await expect(editor).toHaveCount(0)
    await expect(feedback).toBeFocused()
    expect(await availableStock()).toBe(before - 1)
    await expectNoDocumentOverflow(page)

    await page.emulateMedia({ media: 'print' })
    await expect(page.getByRole('cell', { name: 'Laptop Stand', exact: true }).first()).toBeVisible()
    await expect(page.locator('.daily-sales-day-total')).toBeVisible()
    await expect(addSale).toBeHidden()
  })

  test('an unlisted sale collects all four fields and rejects a zero total before saving', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 900 })
    await login(page)
    await page.goto('/daily-sales')
    await page.getByRole('button', { name: 'Add sale', exact: true }).click()
    const editor = page.getByRole('dialog', { name: 'Add sale' })
    await editor.getByRole('button', { name: 'Use an unlisted item' }).click()
    const line = editor.getByRole('group', { name: 'Line 1' })
    const name = line.getByRole('textbox', { name: 'Unlisted item name' })
    await expect(name).toBeFocused()
    await expect(line.getByRole('combobox', { name: 'Product' })).toHaveCount(0)
    await name.fill('Synthetic cutting service')
    await line.getByRole('textbox', { name: 'Unit', exact: true }).fill('m')
    await line.getByRole('spinbutton', { name: 'Quantity' }).fill('2.5')
    const price = line.getByRole('spinbutton', { name: 'Price' })
    await price.fill('0')
    const save = editor.getByRole('button', { name: 'Save sale' })
    let saves = 0
    page.on('request', request => {
      if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/daily-sales') saves += 1
    })
    await save.click()
    await expect(editor.getByRole('alert')).toContainText('total greater than zero')
    expect(saves).toBe(0)
    await price.fill('4')
    await expect(editor.getByRole('alert')).toHaveCount(0)
    await page.screenshot({ path: testInfo.outputPath('unlisted-sale-mobile.png') })
    const savedResponse = page.waitForResponse(response => response.url().endsWith('/api/daily-sales') && response.request().method() === 'POST')
    await save.click()
    const response = await savedResponse
    expect(response.status(), await response.text()).toBe(201)
    const { items, total_amount: total } = await response.json()
    expect(items[0]).toMatchObject({ product_id: null, warehouse_id: null, unit: 'm' })
    expect(Number(items[0].quantity)).toBe(2.5)
    expect(Number(total)).toBe(10)
    await expect(page.getByRole('status').filter({ hasText: /sale saved/i })).toBeVisible()
    expect(saves).toBe(1)
  })
})
