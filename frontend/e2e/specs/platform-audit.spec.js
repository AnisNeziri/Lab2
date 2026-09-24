import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

const routes = ['/dashboard', '/products', '/stock', '/categories', '/suppliers', '/reports', '/finance', '/money-accounts', '/accounting', '/system-integrity', '/invoices', '/purchase-orders', '/fulfillment', '/documents', '/procurement', '/quality', '/customer-debts', '/daily-sales', '/shipments/my-shipments', '/shipments/alerts', '/control-tower', '/users', '/activity-logs', '/cms', '/warehouse-operations', '/warehouse-mobile', '/operations-center']

async function preferences(page, theme, language) {
  const response = await page.request.put('/api/settings/preferences', { headers: { Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('api_token'))}` }, data: { theme, language } })
  expect(response.ok()).toBeTruthy()
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
  await expect(page.locator('html')).toHaveAttribute('lang', language)
}

test.afterEach(async ({ page }) => {
  const token = await page.evaluate(() => localStorage.getItem('api_token')).catch(() => null)
  if (token) await page.request.put('/api/settings/preferences', { headers: { Authorization: `Bearer ${token}` }, data: { theme: 'light', language: 'en', enable_3d_map: false } })
})

function watch(page) {
  const failures = []
  page.on('pageerror', (error) => failures.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error' && !/WebSocket|\[Echo\]/i.test(message.text())) failures.push(message.text()) })
  page.on('response', (response) => { if (new URL(response.url()).pathname.startsWith('/api/') && response.status() >= 400) failures.push(`${response.status()} ${response.url()}`) })
  return failures
}

for (const width of [390, 768, 1024, 1366, 1920]) {
  for (const [theme, language] of [['light', 'en'], ['dark', 'sq']]) {
    test(`platform layout ${width} ${theme} ${language}`, async ({ page }, testInfo) => {
      test.setTimeout(240_000)
      await page.setViewportSize({ width, height: 900 })
      await login(page)
      await preferences(page, theme, language)
      const failures = watch(page)
      for (const route of routes) {
        await test.step(route, async () => {
          await page.goto(route)
          await expect(page.locator('.main-content')).toBeVisible()
          await expect(page.locator('.main-content h1, .main-content h2').first()).toBeVisible()
          await expect(page.locator('body')).not.toContainText(/Cannot read properties|undefined is not a function|SQLSTATE|Stack trace|Access restricted/)
          await expectNoDocumentOverflow(page)
          await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
          await expect(page.locator('html')).toHaveAttribute('lang', language)
        })
      }
      await page.screenshot({ path: testInfo.outputPath('operations.png'), fullPage: true })
      expect(failures).toEqual([])
    })
  }
}

test('staff pages do not call management-only APIs; denied routes explain access', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page, 'staff')
  const failures = watch(page)
  for (const route of ['/control-tower', '/quality', '/fulfillment', '/documents', '/warehouse-operations', '/operations-center', '/money-accounts']) {
    await page.goto(route)
    await expect(page.locator('.main-content h1').first()).toBeVisible()
    await expect(page.locator('.main-content')).not.toContainText(/Insufficient permissions|Unauthorized|Forbidden/i)
  }
  await page.goto('/procurement')
  await expect(page.getByRole('heading', { name: 'Access restricted' })).toBeVisible()
  expect(failures).toEqual([])
})

test('search translations, keyboard navigation, focus restore, and unknown URLs', async ({ page }) => {
  await login(page)
  await preferences(page, 'dark', 'sq')
  await page.keyboard.press('Control+k')
  const dialog = page.getByRole('dialog', { name: 'Kërkimi dhe komandat e AIMS' })
  await expect(dialog).toBeVisible()
  await page.getByRole('textbox', { name: 'Kërko në AIMS', exact: true }).fill('porosi shitjeje')
  await expect(dialog.getByRole('button', { name: /Krijo porosi shitjeje/ })).toBeVisible()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')
  await expect(page).toHaveURL(/\/fulfillment\?new=1/)
  await page.keyboard.press('Control+k')
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await page.goto('/this-page-does-not-exist')
  await expect(page.getByRole('heading', { name: 'Faqja nuk u gjet' })).toBeVisible()
})

test('operations deep links, maps and settings preserve themes and language', async ({ page }) => {
  test.setTimeout(180_000)
  await login(page)
  const failures=watch(page)
  for(const [theme,language] of [['dark','en'],['light','sq']]) {
    await preferences(page,theme,language)
    await page.goto('/operations-center?tab=landed-costs')
    await expect(page.locator('.ops-tabs button.active')).toContainText(language==='en'?'Landed cost':'Kostoja e importit')
    await page.goto('/operations-center?tab=replenishment')
    await expect(page.locator('.ops-tabs button.active')).toContainText(language==='en'?'Replenishment':'Rimbushja')
    for(const route of ['/settings','/warehouse-layout','/warehouse-3d','/shipments/global-map']) {
      await page.goto(route)
      await expect(page.locator('body')).not.toContainText(/Cannot read properties|SQLSTATE|Stack trace|Access restricted/)
      await expect(page.locator('html')).toHaveAttribute('data-theme',theme)
      await expect(page.locator('html')).toHaveAttribute('lang',language)
      await expectNoDocumentOverflow(page)
    }
  }
  expect(failures).toEqual([])
})

test('populated stock history remains scrollable inside its table at 1024px', async ({ page }) => {
  test.setTimeout(90_000)
  await login(page)
  const headers={Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`}
  const products=await (await page.request.get('/api/products?per_page=1',{headers})).json()
  const created=await page.request.post('/api/products',{headers,data:{name:`Layout movement ${Date.now()}`,category_id:products.data[0].category_id,unit:'pcs',quantity:1,min_quantity:0,max_quantity:20,price:5,selling_price:5,purchase_price:3}})
  expect(created.ok(),await created.text()).toBeTruthy()
  await page.setViewportSize({width:1024,height:900})
  for(const [theme,language] of [['light','en'],['dark','sq']]) {
    await preferences(page,theme,language)
    await page.goto('/stock')
    await expect(page.locator('.table-wrap table tbody tr').first()).toBeVisible()
    await expectNoDocumentOverflow(page)
    await expect(page.locator('.table-wrap')).toHaveCSS('overflow-x','auto')
  }
})

test('enabled warehouse maps render and layout navigation works in both languages', async ({ page }) => {
  test.setTimeout(120_000)
  await login(page)
  const headers={Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`}
  expect((await page.request.put('/api/settings/preferences',{headers,data:{enable_3d_map:true}})).ok()).toBeTruthy()
  const failures=watch(page)
  for(const [theme,language,width] of [['light','en',1366],['dark','sq',390]]) {
    await page.setViewportSize({width,height:900})
    await preferences(page,theme,language)
    await page.goto('/warehouse-3d')
    await expect(page.locator('.warehouse-3d-page canvas')).toBeVisible()
    await expectNoDocumentOverflow(page)
    await page.getByRole('button',{name:language==='en'?'Edit layout':'Ndrysho planimetrinë',exact:true}).click()
    await expect(page).toHaveURL(/\/warehouse-layout$/)
    await expect(page.getByRole('heading',{name:language==='en'?'Warehouse layout':'Planimetria e depos',exact:true})).toBeVisible()
    await expectNoDocumentOverflow(page)
  }
  expect(failures).toEqual([])
})
