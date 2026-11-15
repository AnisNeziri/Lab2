import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

const viewports = [
  { name: 'mobile-390', width: 390, height: 844 },
  { name: 'tablet-768', width: 768, height: 1024 },
  { name: 'small-laptop-1024', width: 1024, height: 768 },
  { name: 'laptop-1366', width: 1366, height: 768 },
  { name: 'desktop-1920', width: 1920, height: 1080 },
]

for (const viewport of viewports) {
  test(`@smoke ${viewport.name} renders core pages without document overflow`, async ({ page }, testInfo) => {
    test.setTimeout(90_000) // Five independent page loads share the local PHP server.
    await page.setViewportSize({ width: viewport.width, height: viewport.height })
    await login(page)

    for (const route of ['/dashboard', '/products', '/purchase-orders', '/customer-debts', '/finance']) {
      await page.goto(route)
      await expect(page.locator('.main-content')).toBeVisible()
      if (route === '/finance') await expect(page.getByRole('button', { name: 'Refresh data' })).toBeEnabled()
      await expectNoDocumentOverflow(page)
    }

    if (viewport.width <= 700) {
      const tabs = page.locator('.finance-tabs')
      expect(await tabs.evaluate(nav => nav.scrollWidth - nav.clientWidth)).toBeLessThanOrEqual(2)
    }

    await page.screenshot({
      path: testInfo.outputPath(`${viewport.name}-finance.png`),
      fullPage: true,
    })
  })
}
