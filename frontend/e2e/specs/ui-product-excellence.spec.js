import { test, expect } from '@playwright/test'
import { login } from '../helpers/auth.mjs'
import { expectNoDocumentOverflow } from '../helpers/layout.mjs'

const routes = ['/dashboard','/action-center','/products','/stock','/categories','/warehouse-operations','/warehouse-mobile','/operations-center','/order-hub','/fulfillment','/daily-sales','/customer-debts','/suppliers','/procurement','/purchase-orders','/quality','/shipments/my-shipments','/shipments/global-map','/shipments/alerts','/control-tower','/finance','/invoices','/money-accounts','/accounting','/inventory-intelligence','/inventory-intelligence?view=planning','/inventory-intelligence?view=decisions','/supply-optimizer','/financial-intelligence','/customer-sales-intelligence','/decision-learning','/intelligence-assistant','/analytics','/reports','/automation-studio','/documents','/users','/activity-logs','/cms','/system-integrity']
const shotName = route => route.slice(1).replace(/[/?=]/g, '-')
test.afterEach(async({page})=>{
  const token=await page.evaluate(()=>localStorage.getItem('api_token')).catch(()=>null)
  if(token)await page.request.put('/api/settings/preferences',{headers:{Authorization:`Bearer ${token}`},data:{theme:'light',language:'en'}})
})
async function preferences(page, theme, language) {
  // The preferences UI itself is exercised below; route matrix uses its existing API.
  const token = await page.evaluate(() => localStorage.getItem('api_token'))
  const response = await page.request.put('/api/settings/preferences', {headers:{Authorization:`Bearer ${token}`},data:{theme,language}})
  expect(response.ok()).toBeTruthy()
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme',theme)
  await expect(page.locator('html')).toHaveAttribute('lang',language)
}

test('all module routes, representative hovers, themes and languages at laptop size', async ({page}, testInfo) => {
  test.setTimeout(480_000)
  await page.setViewportSize({width:1366,height:768})
  await login(page)
  const errors=[]
  page.on('pageerror',error=>errors.push(error.message))
  for(const [theme,language] of [['light','en'],['dark','sq']]) {
    await preferences(page,theme,language)
    for(const route of routes) await test.step(`${theme} ${route}`,async()=>{
      await page.goto(route)
      await expect(page.locator('.main-content')).toBeVisible()
      await expect(page.locator('.main-content h1,.main-content h2').first()).toBeVisible()
      await expectNoDocumentOverflow(page)
      await expect(page.locator('body')).not.toContainText(/Cannot read properties|SQLSTATE|Stack trace|Access restricted/)
      const action=page.locator('.main-content button:visible:not(:disabled)').first()
      if(await action.count()) { await action.hover(); await expect(action).toBeVisible() }
      const link=page.locator('.main-content a:visible').first()
      if(await link.count()) await link.hover()
      const row=page.locator('.main-content tbody tr:visible').first()
      if(await row.count()) {await row.hover();expect(await row.evaluate(el=>getComputedStyle(el).cursor)).not.toBe('pointer')}
      await page.screenshot({path:testInfo.outputPath(`${shotName(route)}-${theme}.png`)})
    })
  }
  await page.setViewportSize({width:390,height:844})
  for(const route of ['/dashboard','/products','/warehouse-operations','/daily-sales','/finance','/inventory-intelligence?view=decisions','/supply-optimizer','/intelligence-assistant']) {
    await page.goto(route);await expectNoDocumentOverflow(page)
    await page.screenshot({path:testInfo.outputPath(`${shotName(route)}-mobile.png`)})
  }
  for(const width of [768,1024,1920]) {
    await page.setViewportSize({width,height:900})
    for(const route of ['/products','/supply-optimizer','/finance']) {await page.goto(route);await expectNoDocumentOverflow(page)}
  }
  expect(errors).toEqual([])
})

test('stable brand, chevrons, filters, action menu, product dialog, search and Ask AIMS', async ({page},testInfo)=>{
  test.setTimeout(120_000)
  await page.setViewportSize({width:1366,height:768});await login(page);await page.goto('/products')
  const logo=page.locator('.sidebar-logo-btn')
  const brandStyle=()=>logo.evaluate(el=>{const s=getComputedStyle(el);return{background:s.backgroundColor,transform:s.transform,shadow:s.boxShadow}})
  const before=await brandStyle();await logo.hover();await expect.poll(brandStyle).toEqual(before)
  await logo.focus();await expect(logo).toBeFocused();expect(await logo.evaluate(el=>getComputedStyle(el).outlineStyle)).not.toBe('none')
  const group=page.getByRole('button',{name:'Purchasing & suppliers',exact:true})
  await group.click();await expect(group).toHaveAttribute('aria-expanded','true')
  await expect.poll(()=>group.locator('svg').evaluate(el=>getComputedStyle(el).transform)).not.toBe('none')
  await group.click();await expect(group).toHaveAttribute('aria-expanded','false')
  const input=page.getByPlaceholder('Search by name or SKU');await input.fill('Laptop')
  await page.getByLabel('Category',{exact:true}).selectOption({label:'Electronics'})
  const filters=page.getByLabel('Active filters',{exact:true});await expect(filters).toContainText('Laptop')
  await filters.getByRole('button',{name:'Remove filter: Search: Laptop',exact:true}).click();await expect(input).toHaveValue('')
  await filters.getByRole('button',{name:'Clear all',exact:true}).click();await expect(filters).toHaveCount(0)
  await input.fill('Laptop Stand')
  const row=page.getByRole('row').filter({hasText:'Laptop Stand'}),more=row.getByRole('button',{name:/Actions for/})
  await more.click();const menu=page.getByRole('menu',{name:'Laptop Stand',exact:true});await expect(menu.getByRole('menuitem',{name:'Edit',exact:true})).toBeFocused()
  await page.keyboard.press('ArrowDown');await expect(menu.getByRole('menuitem',{name:'Delete',exact:true})).toBeFocused()
  await page.keyboard.press('Escape');await expect(menu).toHaveCount(0);await expect(more).toBeFocused()
  const view=row.getByRole('button',{name:'View',exact:true});await view.click();await expect(page.locator('.product-detail-modal')).toBeVisible()
  const badge=page.locator('.product-detail-title-row .product-lifecycle');await expect(badge).toBeVisible();expect((await badge.boundingBox()).height).toBeLessThan(40)
  await page.keyboard.press('Escape');await expect(view).toBeFocused();expect(await page.evaluate(()=>document.body.style.overflow)).not.toBe('hidden')
  await page.keyboard.press('Control+k');const search=page.getByRole('dialog',{name:'AIMS search and commands',exact:true})
  await expect(search).toBeVisible();await search.getByRole('combobox').fill('laptop')
  const result=search.getByRole('option').filter({hasText:'Laptop Stand'}).first();await expect(result).toBeVisible();await expect(result.locator('.command-result-icon')).toBeVisible();await expect(result.locator('.command-result-type')).toContainText('Products')
  await page.screenshot({path:testInfo.outputPath('command-center-laptop.png')})
  await page.keyboard.press('Escape');await page.getByRole('button',{name:'Ask AIMS',exact:true}).click()
  const assistant=page.locator('.aims-assistant');await expect(assistant).toBeVisible()
  const question=assistant.getByRole('textbox',{name:'Question for AIMS',exact:true});await question.fill('How do I use this page?');await question.press('Enter');await expect(assistant).toContainText('Choose Add product')
  await expect(assistant.getByRole('link',{name:/Warehouses & Transfers/}).first()).toBeVisible()
  await assistant.getByRole('button',{name:'Close assistant',exact:true}).click();await expect(assistant).toHaveCount(0)
  await page.getByRole('button',{name:'Settings',exact:true}).click();const settings=page.locator('.settings-modal')
  await settings.getByRole('combobox',{name:'Theme',exact:true}).selectOption('dark');await expect(page.locator('html')).toHaveAttribute('data-theme','dark')
  await settings.getByRole('combobox',{name:'Language',exact:true}).selectOption('sq');await expect(page.locator('html')).toHaveAttribute('lang','sq')
  await expect(settings.getByRole('status')).toContainText('ruajt')
  expect(await page.locator('.filters select').first().evaluate(el=>getComputedStyle(el).backgroundRepeat)).toBe('no-repeat')
  await page.keyboard.press('Escape');await expect(settings).toHaveCount(0)
  await page.emulateMedia({reducedMotion:'reduce'});expect(await page.locator('.page-transition').evaluate(el=>getComputedStyle(el).animationName)).toBe('none')
})

test('optimizer scope works on touch and preserves selected constraints',async({page},testInfo)=>{
  await login(page);await page.goto('/supply-optimizer')
  await expect(page.locator('.optimizer-primary').first()).toBeEnabled()
  await expect(page.locator('select[multiple]')).toHaveCount(0)
  const warehouses=page.locator('.scope-picker-field').filter({hasText:'Warehouses'})
  await warehouses.locator('summary').click();await warehouses.getByRole('checkbox').first().check();await expect(warehouses.locator('summary')).toContainText('1 selected')
  const advanced=page.locator('.optimizer-advanced');await advanced.locator(':scope > summary').click()
  const supplier=advanced.locator('.scope-picker-field').filter({hasText:'Eligible suppliers'});await supplier.locator('summary').click();await supplier.getByRole('checkbox').first().check()
  await advanced.locator(':scope > summary').click();await expect(advanced.locator(':scope > summary')).toContainText('Customized')
  await page.setViewportSize({width:390,height:844});await expectNoDocumentOverflow(page)
  await advanced.locator(':scope > summary').click();await expect(supplier.getByRole('checkbox').first()).toBeChecked()
  await page.screenshot({path:testInfo.outputPath('optimizer-scope-mobile.png')})
})

test('notification errors retain records and close restores focus',async({page})=>{
  await page.route('**/api/notifications',async route=>route.fulfill({json:{notifications:[{id:900001,title:'UI test alert',message:'Isolated notification fixture',created_at:new Date().toISOString(),read_at:null,data:{product_id:1}}]}}))
  await page.route('**/api/notifications/clear',async route=>route.fulfill({status:503,json:{message:'Notification service temporarily unavailable'}}))
  await login(page);const bell=page.getByRole('button',{name:'Open notifications',exact:true});await bell.click()
  const dialog=page.getByRole('dialog',{name:'Notifications',exact:true});await expect(dialog).toContainText('UI test alert')
  await dialog.getByRole('button',{name:'Clear notifications',exact:true}).click();await expect(dialog.getByRole('alert')).toContainText('Notification service temporarily unavailable');await expect(dialog).toContainText('UI test alert')
  await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0);await expect(bell).toBeFocused()
})

test('optional warehouse views and public entry screens remain usable',async({page},testInfo)=>{
  test.setTimeout(120000)
  const errors=[];page.on('pageerror',error=>errors.push(error.message))
  await login(page)
  const token=await page.evaluate(()=>localStorage.getItem('api_token'))
  const response=await page.request.put('/api/settings/preferences',{headers:{Authorization:`Bearer ${token}`},data:{enable_3d_map:true,theme:'dark',language:'sq'}})
  expect(response.ok()).toBeTruthy()
  await page.setViewportSize({width:1366,height:768})
  for(const route of ['/warehouse-layout','/warehouse-3d']) {
    await page.goto(route);await expectNoDocumentOverflow(page)
    if(route.endsWith('3d'))await expect(page.locator('canvas')).toBeVisible()
    else { await expect(page.locator('.warehouse-layout-page h1')).toBeVisible();expect(await page.locator('.warehouse-dim-summary').evaluate(el=>getComputedStyle(el).color)).toBe('rgb(167, 180, 199)') }
    await page.screenshot({path:testInfo.outputPath(shotName(route)+'-dark.png')})
  }
  await page.request.put('/api/settings/preferences',{headers:{Authorization:`Bearer ${token}`},data:{enable_3d_map:false,theme:'light',language:'en'}})
  await page.goto('/login');await page.evaluate(()=>localStorage.clear());await page.reload()
  for(const width of [1366,390]) {
    await page.setViewportSize({width,height:844})
    for(const route of ['/','/login','/register']) {
      await page.goto(route);await expectNoDocumentOverflow(page);await expect(page.locator('h1:visible,h2:visible').first()).toBeVisible()
      await page.screenshot({path:testInfo.outputPath((route==='/'?'landing':shotName(route))+'-'+width+'.png')})
    }
  }
  expect(errors).toEqual([])
})
