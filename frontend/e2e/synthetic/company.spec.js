import { test, expect } from '@playwright/test'
import { widgetCatalog } from '../../src/config/dashboardWidgets.js'
import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'

const output=path.resolve('../output/pm3')
const api=(page,url)=>page.evaluate(async url=>{const {apiRequest}=await import('/src/api/client.js');return apiRequest(url)},url)
const preferences=(page,theme,language)=>page.evaluate(async values=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');await useSettingsStore.getState().savePreferences(values)},{theme,language})

async function login(page) {
  await page.goto('/login')
  await page.getByLabel('Email address').fill('owner@aims-demo.test')
  await page.getByLabel('Password',{exact:true}).fill('AimsDemo.Test.2026!')
  await page.getByRole('button',{name:'Enter workspace'}).click()
  await expect(page).toHaveURL(/\/dashboard$/)
}

test('PM3 connected-company journeys, API timing and all dashboard widgets',async({page},testInfo)=>{
  mkdirSync(output,{recursive:true})
  const consoleErrors=[], failures=[], timings=[]
  page.on('pageerror',e=>consoleErrors.push(e.message))
  page.on('response',r=>{if(r.url().includes('/api/')&&r.status()>=500)failures.push(`${r.status()} ${r.url()}`)})
  await login(page)
  await expect(page.getByText('SYNTHETIC / TEST DATA — isolated PM3 company',{exact:true})).toBeVisible()
  for(const route of ['/products','/stock','/order-hub','/fulfillment','/daily-sales?date=2026-10-06','/customer-debts','/inventory-intelligence','/inventory-intelligence?view=planning','/inventory-intelligence?view=decisions','/procurement','/purchase-orders','/suppliers','/shipments/my-shipments','/control-tower','/warehouse-operations','/warehouse-mobile','/financial-intelligence','/customer-sales-intelligence','/decision-learning','/supply-optimizer','/strategic-simulation','/documents','/accounting','/finance','/money-accounts','/invoices','/reports','/analytics','/system-integrity','/operations-center','/quality','/action-center','/automation-studio']) {
    const at=Date.now();await page.goto(route)
    await expect(page.locator('#workspace-main')).toBeVisible()
    await expect(page.locator('#workspace-main')).not.toBeEmpty()
    await page.waitForTimeout(500)
    await page.waitForLoadState('networkidle',{timeout:7000}).catch(()=>{})
    timings.push({route,dom_ready_ms:Date.now()-at})
    await expect(page.locator('body')).not.toContainText('Something went wrong')
    await page.screenshot({path:path.join(output,`journey-${route.slice(1).replaceAll(/[/?=&]/g,'-')}.png`),animations:'disabled'})
  }
  // Build and save the full real widget catalogue through the existing backend, not a frontend mock.
  await page.goto('/dashboard')
  const saved=await page.evaluate(async()=>{
    const {apiRequest}=await import('/src/api/client.js')
    return apiRequest('/settings/workspace')
  })
  const widgets=widgetCatalog.map((w,position)=>({id:w.id,position,size:w.defaultSize,settings:w.id==='supplier-reliability'?{supplier_id:'1'}:w.settings}))
  await page.evaluate(async({saved,widgets})=>{
    const {apiRequest}=await import('/src/api/client.js')
    await apiRequest('/settings/workspace',{method:'PUT',body:JSON.stringify({revision:saved.revision,dashboard:{version:1,widgets}})})
  },{saved,widgets})
  await page.reload()
  for (const w of widgets) {
    const element=page.locator(`[data-widget="${w.id}"]`)
    await expect(element).toBeVisible();await element.scrollIntoViewIfNeeded()
    await expect(element.locator('.widget-skeleton')).toHaveCount(0)
    await expect(element.locator('.widget-error')).toHaveCount(0)
  }
  const apiTimings=[]
  for(const endpoint of ['/dashboard','/dashboard/sales-analytics?period=month','/analytics/intelligence','/order-hub?per_page=20','/financial-intelligence?horizon=30','/customer-sales-intelligence','/search?q=Milano','/supply-optimizer','/strategic-simulation']) {
    const at=Date.now(),result=await api(page,endpoint)
    apiTimings.push({endpoint,milliseconds:Date.now()-at,bytes:JSON.stringify(result).length})
  }
  const responsive=[]
  for(const width of [1920,1366,768,390]) {
    await page.setViewportSize({width,height:width===390?844:900})
    await preferences(page,width===1920?'light':'dark',width===768?'sq':'en')
    for(const route of ['/dashboard','/order-hub','/warehouse-operations','/customer-debts','/financial-intelligence','/inventory-intelligence?view=decisions','/supply-optimizer','/strategic-simulation']) {
      await page.goto(route);await expect(page.locator('#workspace-main')).toBeVisible()
      await page.waitForLoadState('networkidle',{timeout:5000}).catch(()=>{})
      await expect(page.locator('html')).toHaveAttribute('data-theme',width===1920?'light':'dark')
      await expect(page.locator('html')).toHaveAttribute('lang',width===768?'sq':'en')
      const scroll=await page.evaluate(()=>document.documentElement.scrollWidth)
      responsive.push({route,width,scroll,theme:width===1920?'light':'dark',language:width===768?'sq':'en'})
      expect.soft(scroll,`${route} at ${width}`).toBeLessThanOrEqual(width+2)
      if(['/dashboard','/warehouse-operations','/supply-optimizer'].includes(route))await page.screenshot({path:path.join(output,`${route.slice(1)}-${width}.png`),animations:'disabled'})
    }
  }
  await preferences(page,'light','en')
  const report={timings,apiTimings,responsive,widgets:widgets.map(w=>w.id),consoleErrors,failures}
  writeFileSync(path.join(output,'browser-validation.json'),JSON.stringify(report,null,2))
  await testInfo.attach('pm3-journey-performance',{body:JSON.stringify(report,null,2),contentType:'application/json'})
  // Do not leave the owner with the deliberately overloaded 25-widget stress layout.
  await page.evaluate(async saved=>{
    const {apiRequest}=await import('/src/api/client.js')
    const {defaultDashboard}=await import('/src/config/dashboardWidgets.js')
    const {useAuthStore}=await import('/src/store/authStore.js')
    const current=await apiRequest('/settings/workspace'),auth=useAuthStore.getState()
    await apiRequest('/settings/workspace',{method:'PUT',body:JSON.stringify({revision:current.revision,dashboard:saved.dashboard||defaultDashboard(auth.permissions,auth.role)})})
  },saved)
  expect(consoleErrors).toEqual([]);expect(failures).toEqual([])
})

test('PM3 product modal, populated search, source links and assistant',async({page})=>{
  await login(page);await page.setViewportSize({width:1366,height:900});await page.goto('/products')
  await page.getByPlaceholder('Search by name or SKU').fill('Milano')
  await expect(page.getByRole('button',{name:'View',exact:true}).first()).toBeVisible()
  await page.getByRole('button',{name:'View',exact:true}).first().click()
  const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible()
  await expect(dialog).toContainText('Available to sell')
  const box=await dialog.boundingBox();expect(Math.abs(box.x+box.width/2-683)).toBeLessThan(5)
  await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0)
  expect(await page.evaluate(()=>getComputedStyle(document.body).overflow)).not.toBe('hidden')
  const search=await api(page,'/search?q=Milano')
  expect(search.products.length).toBeGreaterThan(0);expect(search.decisions.length).toBeGreaterThan(0)
  await page.keyboard.press('Control+k');await expect(page.getByRole('dialog')).toBeVisible();await page.keyboard.press('Escape')
  await page.goto(search.decisions[0].url)
  await expect(page.getByRole('region',{name:'Decision details',exact:true})).toContainText('Milano')
  const orders=await api(page,'/order-hub?per_page=5');await page.goto(`/order-hub?intake=${orders.data[0].id}`)
  await expect(page.locator('#workspace-main')).toContainText(orders.data[0].order.order_number)
  const purchases=await api(page,'/purchase-orders?per_page=5');await page.goto(`/purchase-orders?po=${purchases.data[0].id}`)
  await expect(page.locator('#workspace-main')).toContainText(purchases.data[0].po_number)
  await page.goto('/intelligence-assistant')
  await page.getByLabel('Question for AIMS').fill('Which customers owe the most?')
  await page.getByRole('button',{name:'Send question',exact:true}).click()
  await expect(page.locator('.assistant-working')).toHaveCount(0)
  await expect(page.locator('.assistant-message').last()).toBeVisible()
  await expect(page.locator('.assistant-message').last()).not.toHaveClass(/is-error/)
  await page.screenshot({path:path.join(output,'assistant-receivables.png'),animations:'disabled'})
})
