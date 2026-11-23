import { test, expect } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { login } from '../helpers/auth.mjs'

test('@smoke owner can review priorities and follow the evidence for unverified financial totals', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Command center', exact: true }).click()
  const brief = page.getByRole('region', { name: 'Executive Command Center' })
  await expect(brief.getByRole('heading', { name: 'What needs your attention' })).toBeVisible()
  await expect(brief.getByText('Financial conclusions are blocked:', { exact: false })).toBeVisible()
  await expect(brief.getByRole('link', { name: /Cash & bank/ }).locator('strong')).toHaveText('—')
  await brief.getByRole('link', { name: 'Review differences' }).click()
  await expect(page).toHaveURL(/\/accounting\?tab=reconciliation$/)

  await page.goto('/dashboard')
  await page.getByRole('button', { name: 'Command center', exact: true }).click()
  await brief.getByRole('link', { name: 'All work' }).click()
  await expect(page.getByRole('heading', { name: 'Related approvals and issues' })).toBeVisible()
  await page.getByLabel('Issue area').selectOption('approval')
  await expect(page).toHaveURL(/\/action-center\?.*area=approval/)
})

test('@smoke accountant can download a VAT workbook for the selected finance period', async ({ page }) => {
  await login(page, 'accountant')
  await page.goto('/finance?tab=vat')
  const downloadEvent = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Export VAT Books', exact: true }).click()
  const download = await downloadEvent
  expect(download.suggestedFilename()).toMatch(/^kosovo-vat-books-\d{4}-\d{2}-\d{2}-to-\d{4}-\d{2}-\d{2}\.xlsx$/)
  const workbook = await readFile(await download.path())
  expect(workbook.subarray(0, 4)).toEqual(Buffer.from([0x50, 0x4b, 0x03, 0x04]))
  expect(workbook.includes(Buffer.from('xl/workbook.xml'))).toBe(true)
})

test('purchasing can open a purchase order without requesting finance accounts', async ({ page }) => {
  await login(page, 'purchasing')
  const financeRequests = []
  page.on('request', request => {
    if (new URL(request.url()).pathname === '/api/finance/accounts') financeRequests.push(request.url())
  })
  await page.goto('/purchase-orders')
  await expect(page.getByRole('heading', { name: 'Purchase Orders', exact: true })).toBeVisible()
  const token = await page.evaluate(() => localStorage.getItem('api_token'))
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
  const suppliers = await (await page.request.get('/api/suppliers', { headers })).json()
  const created = await page.request.post('/api/purchase-orders', { headers, data: {
    supplier_id: (suppliers.data || suppliers)[0].id, ordered_at: '2026-09-14', currency: 'EUR',
    status: 'draft', items: [{ description: 'Role check fixture', unit: 'pcs', quantity: 1, unit_price: 10 }],
  } })
  expect(created.ok(), await created.text()).toBe(true)
  const order = await created.json()
  await page.goto(`/purchase-orders?po=${order.id}`)
  await expect(page.getByRole('heading', { name: order.po_number, exact: true })).toBeVisible()
  const orderActions = page.getByRole('button', { name: `Actions for ${order.po_number}` })
  await orderActions.click()
  await expect(page.getByRole('menuitem', { name: 'Record payment' })).toHaveCount(0)
  expect(financeRequests).toEqual([])
})

for (const [role, route, endpoint, restricted] of [
  ['sales', '/customers', '/customer-workspace', '/accounting/periods'],
  ['warehouse', '/warehouse-operations?tab=locator', '/warehouses', '/purchase-requests'],
  ['accountant', '/accounting?tab=periods', '/accounting/periods', '/superadmin/dashboard'],
  ['superadmin', '/superadmin', '/superadmin/dashboard', '/customer-workspace'],
]) {
  test(`@smoke ${role} can open the work area and restricted records stay inaccessible`, async ({ page }) => {
    const companyRequests = []
    if (role === 'superadmin') page.on('request', request => {
      if (/\/api\/settings\/(workspace|preferences)$/.test(new URL(request.url()).pathname)) companyRequests.push(request.url())
    })
    await login(page, role)
    const receiptRequests = []
    if (role === 'warehouse') page.on('request', request => {
      if (new URL(request.url()).pathname.startsWith('/api/goods-receipts')) receiptRequests.push(request.url())
    })
    await page.goto(route)
    await expect(page).toHaveURL(new RegExp(`${route.split('?')[0]}(?:\\?|$)`))
    if (role === 'superadmin') await expect(page.getByRole('heading', { name: 'Platform administration' })).toBeVisible()
    else await expect(page.locator('.main-content h1').first()).toBeVisible()
    const token = await page.evaluate(() => localStorage.getItem('api_token'))
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    const allowed = await page.request.get(`/api${endpoint}`, { headers })
    expect(allowed.ok(), await allowed.text()).toBe(true)
    const denied = await page.request.get(`/api${restricted}`, { headers })
    expect(denied.status()).toBe(403)
    if (role === 'warehouse') {
      await expect(page.locator('.stock-locator-panel')).toBeVisible()
      await expect(page.locator('.stock-locator-product-grid > button').first()).toBeVisible()
      expect(receiptRequests).toEqual([])
      const receive = await page.request.post('/api/mobile-warehouse/receive', { headers, data: {} })
      expect(receive.status()).toBe(403)
    }
    if (role === 'superadmin') {
      await expect(page.locator('.sidebar a[href="/customers"]')).toHaveCount(0)
      await page.goto('/customers')
      await expect(page).toHaveURL(/\/superadmin$/)
      expect(companyRequests).toEqual([])
    }
  })
}
