import { expect, test } from '@playwright/test'
import { login } from '../helpers/auth.mjs'

test.use({ reducedMotion: 'reduce' })

test('new customer, supplier comparison and finance tabs reveal their destinations', async ({ page }) => {
  await login(page)
  await page.goto('/customer-debts')
  await page.getByRole('button', { name: 'New customer' }).click()
  await expect(page.locator('#customer-debt-form')).toBeFocused()
  await expect(page.locator('#customer-debt-form')).toBeInViewport()

  await page.goto('/purchasing-decisions#purchase-portfolio')
  await expect(page.getByRole('heading', { name: 'Purchasing plan' })).toBeFocused()
  await page.goto('/purchasing-decisions')
  await expect(page.getByRole('heading', { name: 'Purchasing plan' })).toHaveCount(0)
  const product = page.getByRole('combobox', { name: 'Product', exact: true })
  await product.selectOption({ label: 'A4 Paper Pack · OFF-001' })
  await page.getByRole('spinbutton', { name: 'Quantity', exact: true }).fill('5')
  await page.getByRole('button', { name: 'Check stock and quotes' }).click()
  await expect(page.locator('#purchase-suppliers')).toBeFocused()
  await expect(page.locator('#purchase-suppliers')).toBeInViewport()
  await page.getByRole('button', { name: 'Add to purchasing plan' }).click()
  await expect(page.getByRole('heading', { name: 'Purchasing plan' })).toBeFocused()

  await page.goto('/accounting')
  await page.getByRole('button', { name: 'Reconciliation', exact: true }).click()
  await expect(page.locator('#finance-workspace-panel')).toBeFocused()
  await expect(page.locator('#finance-workspace-panel')).toBeInViewport()
})
