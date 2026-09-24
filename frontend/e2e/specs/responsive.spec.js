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
    await page.setViewportSize({ width: viewport.width, height: viewport.height })
    await login(page)

    for (const route of ['/dashboard', '/products', '/purchase-orders', '/customer-debts', '/finance']) {
      await page.goto(route)
      await expect(page.locator('.main-content')).toBeVisible()
      await expectNoDocumentOverflow(page)
    }

    await page.screenshot({
      path: testInfo.outputPath(`${viewport.name}-finance.png`),
      fullPage: true,
    })
  })
}
