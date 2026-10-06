import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'

async function api(page,path,data){const headers={Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`};const r=data===undefined?await page.request.get(`/api${path}`,{headers}):await page.request.post(`/api${path}`,{headers,data});expect(r.ok(),await r.text()).toBeTruthy();return r.json()}

test('template → simulate → enable → low stock → action → outcome',async({page})=>{
  await login(page)
  await page.goto('/automation-studio')
  await page.getByRole('button',{name:/^Low stock Creates/}).click()
  await page.getByLabel('Name',{exact:true}).fill('Warehouse purchasing review')
  await page.getByRole('button',{name:'Save disabled draft'}).click()
  await expect(page.getByRole('heading',{name:'Warehouse purchasing review'})).toBeVisible()
  await page.getByRole('button',{name:'Test against recent history'}).click()
  await expect(page.getByRole('heading',{name:'Simulation — no actions performed'})).toBeVisible()
  await page.getByRole('button',{name:'Enable',exact:true}).click()
  await expect(page.getByRole('button',{name:'Disable',exact:true})).toBeVisible()
  const categories=await api(page,'/categories'), warehouses=await api(page,'/warehouses'), first=x=>(x.data||x)[0]
  const tag='Automation '+Date.now()
  await api(page,'/products',{name:tag,sku:'AUTO-'+Date.now(),category_id:first(categories).id,default_warehouse_id:first(warehouses).id,unit:'pcs',quantity:2,min_quantity:10,purchase_price:2,selling_price:5,price:5})
  await page.goto('/action-center')
  const task=page.locator('[data-task-id]').filter({hasText:tag})
  await expect(task).toBeVisible()
  await expect(task.getByRole('link',{name:'Open source / take action'})).toHaveAttribute('href',/products\?product=/)
  await task.getByRole('button',{name:'Start task'}).click()
  await task.getByRole('button',{name:'Complete task'}).click()
  await expect(task).toHaveCount(0)
})

test('custom builder, translations, themes, widths, and staff boundary',async({page})=>{
  await login(page)
  await page.goto('/automation-studio')
  await page.getByRole('button',{name:'Create automation'}).click()
  await page.getByLabel('Name',{exact:true}).fill('Custom stock warning')
  await page.getByRole('button',{name:'+ Condition',exact:true}).click()
  await page.getByLabel('Field',{exact:true}).selectOption('available')
  await page.getByLabel('Comparison',{exact:true}).selectOption('lt')
  await page.getByLabel('Comparison value').fill('5')
  await page.getByLabel('Title',{exact:true}).fill('Check product')
  for(const width of [390,768,1024,1366,1920]){
    await page.setViewportSize({width,height:900})
    await expect(page.getByRole('button',{name:'Save disabled draft'})).toBeVisible()
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()
  }
  await page.getByRole('button',{name:'Save disabled draft'}).click()
  await expect(page.getByRole('heading',{name:'Custom stock warning'})).toBeVisible()
  await page.evaluate(async()=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');useSettingsStore.getState().applyPreferences({theme:'dark',language:'sq'})})
  await expect(page.getByRole('heading',{name:'Studio e automatizimit',exact:true})).toBeVisible()
  await page.screenshot({path:'test-results/automation-dark.png',fullPage:true})
  await page.evaluate(async()=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');useSettingsStore.getState().applyPreferences({theme:'light',language:'en'})})
  await page.goto('/action-center')
  await page.getByRole('button',{name:'New task'}).click()
  await page.getByLabel('Title',{exact:true}).fill('Manual check')
  await page.getByRole('button',{name:'Save task'}).click()
  await expect(page.getByRole('heading',{name:'Manual check',exact:true})).toBeVisible()
})

test('staff cannot open Automation Studio',async({page})=>{
  await login(page,'staff')
  const r=await page.request.get('/api/automations',{headers:{Authorization:`Bearer ${await page.evaluate(()=>localStorage.getItem('api_token'))}`}})
  expect(r.status()).toBe(403)
  await page.goto('/action-center')
  await expect(page.getByRole('heading',{name:'Action Center',exact:true})).toBeVisible()
  await expect(page.getByRole('link',{name:'Automation Studio',exact:true})).toHaveCount(0)
})
