import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

test('desktop mode and Redis connectivity have distinct status and retry behavior', async ({ page }) => {
  let state = { mode: 'offline', cache_available: true, redis: { status: 'disabled_offline' } }
  await page.route('**/api/system/mode', route => route.fulfill({ json: state }))
  await login(page)
  const banner = page.locator('.system-mode-banner')
  await expect(banner).toHaveCount(0)
  await page.setViewportSize({ width: 390, height: 844 })
  await expectNoDocumentOverflow(page)
  state = { mode: 'online', cache_available: true, redis: { status: 'unavailable', message: 'Start Redis and check REDIS_HOST/REDIS_PORT.' } }
  await page.reload()
  await expect(banner).toContainText('System connection needs attention')
  await expect(banner.locator('details')).not.toHaveAttribute('open','')
  await banner.locator('summary').click()
  await expect(banner.getByText('Start Redis and check REDIS_HOST/REDIS_PORT.')).toBeVisible()
  state = { mode: 'online', cache_available: true, redis: { status: 'connected' } }
  await page.getByRole('button', { name: 'Refresh system status' }).click()
  await expect(banner).toHaveCount(0)
})

test('status request failure is actionable and can recover', async ({ page }) => {
  let fail = true
  await page.route('**/api/system/mode', route => fail
    ? route.fulfill({ status: 503, json: { message: 'Unavailable' } })
    : route.fulfill({ json: { mode: 'offline', redis: { status: 'disabled_offline' } } }))
  await login(page)
  await expect(page.locator('.system-mode-banner')).toContainText('Check the local backend and retry')
  fail = false
  await page.getByRole('button', { name: 'Refresh system status' }).click()
  await expect(page.locator('.system-mode-banner')).toHaveCount(0)
})
