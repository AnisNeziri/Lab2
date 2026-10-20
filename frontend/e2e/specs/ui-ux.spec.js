import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

const routes = ['/dashboard', '/products', '/stock', '/warehouse-operations', '/operations-center', '/warehouse-mobile', '/order-hub', '/fulfillment', '/daily-sales', '/customer-debts', '/suppliers', '/purchase-orders', '/procurement', '/quality', '/invoices', '/finance', '/money-accounts', '/accounting', '/shipments/my-shipments', '/control-tower', '/inventory-intelligence', '/action-center', '/automation-studio', '/documents', '/reports']

for (const review of [
  { name: 'laptop light English', width: 1366, height: 768, theme: 'light', language: 'en' },
  { name: 'laptop dark Albanian', width: 1366, height: 768, theme: 'dark', language: 'sq' },
  { name: 'mobile dark Albanian', width: 390, height: 844, theme: 'dark', language: 'sq' },
]) test(`workspace visual and responsive review: ${review.name}`, async ({ page }, testInfo) => {
  test.setTimeout(240_000)
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  await page.setViewportSize({ width: review.width, height: review.height })
  await login(page)
  // Isolate each viewport/theme so one long session cannot consume every case's budget.
  for (const route of routes) {
    await page.goto(route)
    await page.waitForLoadState('networkidle')
    await page.evaluate(({ theme, language }) => {
      window.__aimsCertification.useSettingsStore.getState().applyPreferences({ theme, language })
    }, { theme: review.theme, language: review.language })
    await expect(page.locator('html')).toHaveAttribute('data-theme', review.theme)
    await expect(page.locator('html')).toHaveAttribute('lang', review.language)
    await expect(page.locator('.main-content')).toBeVisible()
    await expectNoDocumentOverflow(page)
    if (['/dashboard', '/products', '/finance', '/inventory-intelligence', '/suppliers', '/warehouse-mobile'].includes(route)) {
      await page.screenshot({ path: testInfo.outputPath(`${route.slice(1)}-${review.theme}.png`) })
    }
  }
  expect(errors).toEqual([])
})

test('navigation, product form, filtering and dialog focus remain usable', async ({page}) => {
  await login(page)
  await page.goto('/products')
  await expect(page.locator('form.product-form')).toHaveCount(0)
  await page.getByRole('button', {name: 'Add product', exact: true}).click()
  await expect(page.locator('form.product-form')).toBeVisible()
  await page.locator('form.product-form').getByLabel('Name', {exact: true}).fill('Unsaved work')
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('database-refresh')))
  await expect(page.locator('form.product-form').getByLabel('Name', {exact: true})).toHaveValue('Unsaved work')
  page.once('dialog', dialog => dialog.accept())
  await page.locator('form.product-form').getByRole('button', {name: 'Cancel', exact: true}).click()
  await page.getByPlaceholder('Search by name or SKU').fill('Laptop Stand')
  const row = page.getByRole('row').filter({hasText: 'Laptop Stand'})
  const view = row.getByRole('button', {name: 'View', exact:true})
  await view.click()
  await expect(page.locator('.product-detail-modal')).toBeVisible()
  await expect.poll(() => page.evaluate(() => getComputedStyle(document.body).overflow)).toBe('hidden')
  await page.keyboard.press('Escape')
  await expect(view).toBeFocused()
  await expect.poll(() => page.evaluate(() => getComputedStyle(document.body).overflow)).not.toBe('hidden')
  await page.getByRole('button', {name: 'Clear search', exact:true}).click()
  await expect(page.getByPlaceholder('Search by name or SKU')).toHaveValue('')
  await page.getByRole('button', {name: 'Intelligence & reports', exact:true}).click()
  await page.getByRole('link', {name: 'Inventory planning', exact:true}).click()
  await expect(page).toHaveURL(/view=planning/)
  await expect(page.getByRole('link', {name: 'Inventory planning', exact:true}).first()).toHaveAttribute('aria-current','page')
})

test('mobile navigation and settings release their scroll locks', async ({page}) => {
  await page.setViewportSize({width:390, height:844})
  await login(page)
  const toggle = page.locator('.mobile-menu-toggle')
  await toggle.click()
  await page.getByRole('button', {name:'Settings', exact:true}).click()
  await expect(page.getByRole('dialog', {name:'Settings', exact:true})).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog', {name:'Settings', exact:true})).toBeHidden()
  await expect(toggle).toHaveAttribute('aria-expanded','true')
  await page.keyboard.press('Escape')
  await expect(toggle).toHaveAttribute('aria-expanded','false')
  await expect.poll(() => page.evaluate(() => getComputedStyle(document.body).overflow)).not.toBe('hidden')
})
