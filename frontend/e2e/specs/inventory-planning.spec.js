import {test,expect} from '@playwright/test'
import {login} from '../helpers/auth.mjs'
test('planning is integrated, read-only scenarios handle weak evidence and bilingual mobile dark mode works',async({page})=>{
 test.setTimeout(90000);const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page);await page.goto('/inventory-intelligence?view=planning')
 await expect(page.getByRole('heading',{name:'Inventory Planning',exact:true})).toBeVisible();await page.getByRole('button',{name:'Review product',exact:true}).first().click();await expect(page.getByText('What-if comparison · read-only')).toBeVisible()
 await expect(page.getByRole('heading',{name:'Supplier trade-offs',exact:true})).toBeVisible();await expect(page.getByRole('heading',{name:'Warehouse opportunities',exact:true})).toBeVisible()
 await page.getByRole('button',{name:'Compare scenarios',exact:true}).click();await expect(page.locator('.planning-scenarios')).toHaveCount(2)
 await page.getByRole('button',{name:'Save recommendation',exact:true}).click();await expect(page.locator('.intelligence-message')).toContainText('Plan saved')
 await page.getByText('Recommendation decision & history',{exact:true}).click();await page.getByRole('combobox',{name:'Decision',exact:true}).selectOption('postponed');await page.getByLabel('Review again on').fill(new Date(Date.now()+7*86400000).toISOString().slice(0,10));await page.getByRole('button',{name:'Record decision',exact:true}).click();await expect(page.locator('.intelligence-message')).toContainText('Decision recorded')
 for(const width of [390,768,1366]){await page.setViewportSize({width,height:900});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()}
 await page.evaluate(async()=>{const {useSettingsStore}=window.__aimsCertification;useSettingsStore.getState().applyPreferences({theme:'dark',language:'sq'})});await expect(page.getByRole('heading',{name:'Planifikimi i inventarit',exact:true})).toBeVisible();expect(await page.locator('.intelligence-detail').first().evaluate(e=>getComputedStyle(e).backgroundColor)).not.toBe('rgb(255, 255, 255)');expect(errors).toEqual([])
})
