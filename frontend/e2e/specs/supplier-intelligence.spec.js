import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'
test('supplier evidence is usable, bilingual and responsive without synthetic accuracy',async({page})=>{
 test.setTimeout(90000);const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page);await page.goto('/suppliers')
 await page.getByText('Delivery intelligence and forecasting', {exact:true}).click()
 const panel=page.locator('.supplier-intelligence');await expect(panel.getByRole('heading',{name:'Supplier Intelligence'})).toBeVisible()
 await panel.locator('select').first().selectOption({index:1});await expect(panel.locator('.si-metrics')).toBeVisible()
 await panel.getByRole('button',{name:'Refresh delivery evidence'}).click();await expect(panel.getByRole('status')).toContainText('Saved')
 await panel.getByText('Forecast performance and model review',{exact:true}).click();await panel.getByRole('button',{name:'Prepare candidate from recorded history'}).click();await expect(panel.getByRole('status')).toContainText('Not enough')
 for(const width of [390,768,1366]){await page.setViewportSize({width,height:900});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()}
 await page.evaluate(async()=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');useSettingsStore.getState().applyPreferences({theme:'dark',language:'sq'})})
 await expect(panel.getByRole('heading',{name:'Inteligjenca e furnitorëve'})).toBeVisible();expect(await panel.evaluate(e=>getComputedStyle(e).backgroundColor)).not.toBe('rgb(255, 255, 255)');expect(errors).toEqual([])
})
