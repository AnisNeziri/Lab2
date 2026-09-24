import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

const criticalRoutes = [
  '/dashboard', '/products', '/stock', '/suppliers', '/purchase-orders',
  '/procurement', '/quality', '/warehouse-operations', '/operations-center',
  '/daily-sales', '/customer-debts', '/shipments/my-shipments', '/control-tower',
  '/finance', '/money-accounts', '/accounting', '/reports', '/system-integrity',
]

test.describe('Release route health', () => {
  test('critical admin workspaces render without crashes or page overflow', async ({ page }) => {
    test.setTimeout(120_000) // Eighteen sequential routes; each assertion retains its own short timeout.
    const pageErrors = []
    page.on('pageerror', (error) => pageErrors.push(error.message))
    await page.setViewportSize({ width: 1366, height: 820 })
    await login(page)

    for (const route of criticalRoutes) {
      await test.step(route, async () => {
        await page.goto(route)
        await expect(page.locator('.main-content')).toBeVisible()
        await expect.poll(() => page.locator('.main-content').innerText()).not.toHaveLength(0)
        await expect(page.locator('body')).not.toContainText(/Cannot read properties|undefined is not a function|SQLSTATE|Stack trace/i)
        await expectNoDocumentOverflow(page)
      })
    }

    expect(pageErrors, `uncaught browser errors: ${pageErrors.join(' | ')}`).toEqual([])
  })
})
