import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'

test('local inventory intelligence review, honest sparse-data fallback and responsive themes',async({page})=>{
  test.setTimeout(90000)
  const errors=[];page.on('pageerror',e=>errors.push(e.message))
  await login(page)
  await page.goto('/inventory-intelligence')
  await expect(page.getByRole('heading',{name:'Inventory Intelligence',exact:true})).toBeVisible()
  await page.getByRole('button',{name:'Review',exact:true}).first().click()
  await expect(page.locator('.intelligence-detail h2')).toBeVisible()
  await expect(page.getByText('Forecast setup & accuracy',{exact:true})).toBeVisible()
  await expect(page.locator('.intelligence-performance')).not.toHaveAttribute('open','')
  await page.getByRole('button',{name:'Update forecast',exact:true}).click()
  await expect(page.locator('.intelligence-message')).toContainText('Insufficient data')
  await expect(page.locator('.intelligence-detail')).toContainText('does not have a usable forecast yet')
  await expect(page.getByRole('button',{name:'Create Purchase Request Draft',exact:true})).toHaveCount(0)
  for(const width of [390,768,1366]){
    await page.setViewportSize({width,height:900})
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()
  }
  await page.evaluate(async()=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');useSettingsStore.getState().applyPreferences({theme:'dark',language:'sq'})})
  await expect(page.getByRole('heading',{name:'Inteligjenca e inventarit',exact:true})).toBeVisible()
  await expect(page.locator('.intelligence-message')).toContainText('Të dhëna të pamjaftueshme')
  await expect(page.getByText('Konfigurimi dhe saktësia e parashikimit',{exact:true})).toBeVisible()
  expect(await page.locator('.intelligence-detail').evaluate(e=>getComputedStyle(e).backgroundColor)).not.toBe('rgb(255, 255, 255)')
  await page.screenshot({path:'test-results/inventory-intelligence-dark.png',fullPage:true})
  expect(errors).toEqual([])
})
