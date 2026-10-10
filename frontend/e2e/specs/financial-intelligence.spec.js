import { test, expect } from '@playwright/test'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

test('V7 wholesale cash workflow stays advisory, explainable and responsive', async ({ page }, testInfo) => {
 test.setTimeout(90000)
 const database=fileURLToPath(new URL('../../../backend/database/e2e.sqlite',import.meta.url))
 const fixture=mode=>spawnSync('php',[fileURLToPath(new URL('../fixtures/financial-intelligence.php',import.meta.url)),...(mode?[mode]:[])],{env:{...process.env,APP_ENV:'e2e',DB_CONNECTION:'sqlite',DB_DATABASE:database,DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',BROADCAST_CONNECTION:'log'},encoding:'utf8'})
 const seeded=fixture();expect(seeded.status,seeded.stderr||seeded.stdout).toBe(0);expect(seeded.stdout.trim().startsWith('{'),seeded.stdout).toBeTruthy();const ids=JSON.parse(seeded.stdout)
 expect(ids.recommended_cost).toBe('24000.00')
 const before=fixture('verify').stdout,errors=[];page.on('pageerror',e=>errors.push(e.message))
 await page.goto('/login');await page.getByLabel('Email address').fill('finance-v7@enterprise.test');await page.getByLabel('Password',{exact:true}).fill('password');await page.getByRole('button',{name:'Enter workspace'}).click();await expect(page).toHaveURL(/dashboard/)
 await page.goto('/financial-intelligence');await page.getByRole('button',{name:'Refresh evidence',exact:true}).click()
 await expect(page.locator('.financial-intelligence')).toContainText('€80,000.00');await expect(page.locator('.financial-intelligence')).toContainText('€42,000.00');await expect(page.locator('.financial-intelligence')).toContainText('€63,000.00');await expect(page.locator('.financial-intelligence')).toContainText('€59,000.00')
 await page.getByRole('button',{name:'Customer collections',exact:true}).click();await expect(page.locator('.financial-intelligence')).toContainText('Watch');await expect(page.locator('.financial-intelligence')).toContainText('5 completed payments')
 await page.getByRole('button',{name:'Supplier commitments',exact:true}).click();await expect(page.locator('.financial-intelligence')).toContainText('€35,000.00');await expect(page.locator('.financial-intelligence')).toContainText('Documented arrival-dependent payment');await expect(page.locator('.financial-intelligence')).toContainText('€24,000.00');await expect(page.locator('.financial-intelligence')).toContainText('Recommendation — not a payable')
 await page.getByRole('button',{name:'Compare scenarios',exact:true}).click();await page.getByLabel('Additional purchase amount',{exact:true}).fill('24000');await page.getByRole('button',{name:'Compare cash impact',exact:true}).click();await expect(page.locator('.financial-intelligence')).toContainText('€35,000.00');await expect(page.locator('.financial-intelligence')).toContainText('Scenario comparison — not a commitment')
 await page.getByText('Product coverage and supplier constraints (optional)',{exact:true}).click();await page.getByLabel('Product',{exact:true}).selectOption(String(ids.product_id));await page.getByLabel('Supplier',{exact:true}).selectOption(String(ids.supplier_id));await page.getByLabel('Total quantity (inventory unit)',{exact:true}).fill('8000');await page.getByLabel('Second delivery quantity',{exact:true}).fill('4000');await page.getByLabel('Second delivery delay (days)',{exact:true}).fill('10');await page.getByRole('button',{name:'Compare cash impact',exact:true}).click();await expect(page.locator('.financial-intelligence')).toContainText('Inventory coverage cannot be forecast reliably with this history.')
 const decisions=await page.evaluate(async product=>{const {apiRequest}=window.__aimsCertification;return apiRequest('/analytics/decisions/products/'+product+'/refresh',{method:'POST',body:JSON.stringify({})})},ids.product_id)
 expect(decisions.decisions.length).toBeGreaterThan(0)
 await page.goto('/inventory-intelligence?view=decisions&product='+ids.product_id+'&decision='+decisions.decisions[0].id)
 await page.getByText('Purchase alternatives · cash and stock risk',{exact:true}).click();await expect(page.locator('.decision-financial-table')).toContainText('€24,000.00');await expect(page.locator('.decision-financial-table')).toContainText('€35,000.00');await expect(page.locator('.decision-financial-table')).toContainText('Insufficient inventory history')
 await page.goto('/financial-intelligence')
 for(const width of [1366,768,390]){await page.setViewportSize({width,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()}
 await page.getByRole('button',{name:'Data & model health',exact:true}).click();await expect(page.locator('.financial-intelligence')).toContainText('Recorded due-date baseline');await expect(page.getByRole('button',{name:'Promote median',exact:true})).toBeDisabled()
 for(const width of [1366,768,390]){await page.setViewportSize({width,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()}
 await page.evaluate(async()=>{const{useSettingsStore}=window.__aimsCertification;useSettingsStore.getState().applyPreferences({theme:'dark',language:'sq'})})
 await expect(page.getByRole('heading',{name:'Inteligjenca financiare',exact:true})).toBeVisible();expect(await page.locator('.fi-panel').first().evaluate(el=>getComputedStyle(el).backgroundColor)).not.toBe('rgb(255, 255, 255)');await page.screenshot({path:testInfo.outputPath('financial-intelligence-mobile-dark.png')})
 const after=fixture('verify');expect(after.status,after.stderr).toBe(0);expect(after.stdout).toBe(before);expect(errors).toEqual([])
})
