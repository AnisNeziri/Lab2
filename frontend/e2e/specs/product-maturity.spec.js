import { test, expect } from '@playwright/test'
import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { login } from '../helpers/auth.mjs'

const stage = process.env.PM1_STAGE || 'after'
const output = path.resolve('../output/pm1', stage)
const routes = ['dashboard','order-hub','products','stock','categories','suppliers','warehouse-operations','warehouse-mobile','daily-sales','customer-debts','purchase-orders','procurement','fulfillment','operations-center','quality','finance','invoices','money-accounts','accounting','shipments/my-shipments','shipments/alerts','shipments/global-map','control-tower','inventory-intelligence','inventory-intelligence?view=planning','inventory-intelligence?view=decisions','supply-optimizer','strategic-simulation','financial-intelligence','customer-sales-intelligence','decision-learning','analytics','reports','action-center','automation-studio','documents','users','activity-logs','cms','system-integrity']

async function preferences(page, theme, language) {
  await page.evaluate(async ({theme, language}) => {
    const { useSettingsStore } = await import('/src/store/settingsStore.js')
    useSettingsStore.getState().applyPreferences({theme, language})
  }, {theme, language})
}

test('PM1 rendered workspace review and essential interactions', async ({ page }) => {
  test.setTimeout(600000)
  mkdirSync(output, {recursive:true})
  const errors=[], findings=[]
  page.on('pageerror', error=>errors.push(error.message))
  await page.setViewportSize({width:1366,height:768})
  await login(page)
  const token=await page.evaluate(()=>localStorage.getItem('api_token'))
  const api=async (url,data)=>{
    const response=await page.request[data ? 'post' : 'get'](`/api${url}`,{headers:{Authorization:`Bearer ${token}`},...(data?{data}:{})})
    expect(response.ok(),await response.text()).toBeTruthy()
    return response.json()
  }
  const first=r=>(r.data||r)[0], category=first(await api('/categories')), warehouse=first(await api('/warehouses'))
  const product=await api('/products',{name:'Brushed brass door handle',sku:'PM1-HANDLE',category_id:category.id,default_warehouse_id:warehouse.id,unit:'pcs',quantity:1000,min_quantity:65,purchase_price:2,selling_price:5,price:5})
  const customer=await api('/customers',{name:'Balkan Interiors — wholesale',email:'pm1@example.test'})
  const intake=await api('/order-hub/orders',{idempotency_key:'pm1-visual-order',customer_id:customer.id,order_date:new Date().toLocaleDateString('en-CA'),payment_type:'cash',items:[{product_id:product.id,quantity:65}]})
  const capture=async(route,theme,language,width=1366,height=768)=>{
    await page.setViewportSize({width,height})
    await page.goto(`/${route}`)
    await expect(page.locator('.main-content')).toBeVisible()
    await page.waitForLoadState('networkidle',{timeout:5000}).catch(()=>{})
    await preferences(page,theme,language)
    // Settings updates can schedule translated data loads after the current render.
    await page.waitForTimeout(350)
    await page.waitForLoadState('networkidle',{timeout:5000}).catch(()=>{})
    await page.evaluate(()=>document.fonts.ready)
    await page.screenshot({path:path.join(output,`${route.replaceAll(/[/?=&]/g,'-')}-${theme}-${language}-${width}.png`),animations:'disabled'})
    const dimensions=await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,heading:document.querySelector('.page-transition h1')?.getBoundingClientRect().x,controls:[...document.querySelectorAll('.page-transition input:not([type=checkbox]):not([type=radio]),.page-transition select')].filter(e=>e.getBoundingClientRect().height).slice(0,12).map(e=>Math.round(e.getBoundingClientRect().height))}))
    findings.push({route,theme,language,...dimensions})
    if(stage==='after')expect.soft(dimensions.scroll,`${route} overflow at ${width} ${theme}`).toBeLessThanOrEqual(width+2)
  }
  for(const route of routes)for(const [theme,language]of[['light','en'],['dark','sq']])await capture(route,theme,language)
  for(const [theme,language]of[['light','en'],['dark','sq']])await capture(`order-hub?intake=${intake.id}`,theme,language)
  await capture('order-hub?new=1','light','en')
  await capture('products','dark','en')
  await page.locator('.sidebar-logo-btn').hover()
  const logo=await page.locator('.sidebar-logo-btn').evaluate(el=>({background:getComputedStyle(el).backgroundColor,shadow:getComputedStyle(el).boxShadow}))
  expect(logo.background).toBe('rgba(0, 0, 0, 0)');expect(logo.shadow).toBe('none')
  await page.keyboard.press('Control+k')
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.screenshot({path:path.join(output,'command-search.png')})
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).toHaveCount(0)
  if(stage==='after'){
    for(const {width,height}of[{width:1440,height:900},{width:1920,height:1080},{width:768,height:1024},{width:390,height:844}]){
      for(const route of ['dashboard',`order-hub?intake=${intake.id}`,'warehouse-operations','finance','inventory-intelligence','strategic-simulation'])await capture(route,width===1440?'light':'dark',width===1440?'sq':'en',width,height)
    }
    await page.setViewportSize({width:1366,height:768});await page.goto(`/order-hub?intake=${intake.id}`);await preferences(page,'light','en')
    await page.getByRole('button',{name:'Confirm & reserve',exact:true}).click()
    await expect(page.getByRole('button',{name:'Mark ready',exact:true})).toBeVisible()
    await page.goto('/products');await preferences(page,'light','en')
    await page.getByRole('button',{name:'View',exact:true}).first().click()
    const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible()
    const box=await dialog.boundingBox();expect(Math.abs(box.x+box.width/2-683)).toBeLessThan(4)
    await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0)
    expect(await page.evaluate(()=>getComputedStyle(document.body).overflow)).not.toBe('hidden')
  }
  writeFileSync(path.join(output,'review.json'),JSON.stringify({findings,errors,logo},null,2))
  expect(errors).toEqual([])
})
