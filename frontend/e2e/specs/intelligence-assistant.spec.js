import { test, expect } from '@playwright/test'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

test('V9 brief, evidence, follow-up scenario and explicit draft confirmation work locally',async({page},testInfo)=>{
  test.setTimeout(90000)
  const database=fileURLToPath(new URL('../../../backend/database/e2e.sqlite',import.meta.url)),fixture=mode=>spawnSync('php',[fileURLToPath(new URL('../fixtures/intelligence-assistant.php',import.meta.url)),...(mode?[mode]:[])],{env:{...process.env,APP_ENV:'e2e',DB_CONNECTION:'sqlite',DB_DATABASE:database,DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',BROADCAST_CONNECTION:'log'},encoding:'utf8'})
  const seeded=fixture();expect(seeded.status,seeded.stderr||seeded.stdout).toBe(0);const before=JSON.parse(fixture('verify').stdout),errors=[];page.on('pageerror',e=>errors.push(e.message))
  await page.goto('/login');await page.getByLabel('Email address').fill('copilot-v9@enterprise.test');await page.getByLabel('Password',{exact:true}).fill('password');await page.getByRole('button',{name:'Enter workspace'}).click();await expect(page).toHaveURL(/dashboard/)
  await page.goto('/intelligence-assistant');const panel=page.locator('.aims-assistant.is-embedded'),input=panel.getByLabel('Question for AIMS',{exact:true})
  const ask=async q=>{await input.fill(q);await input.press('Enter');await expect(panel.locator('.assistant-working')).toHaveCount(0)}
  await ask('Daily brief today');await expect(panel.locator('.assistant-evidence-card').first()).toContainText('Door handles');await panel.getByRole('button',{name:'Explain why',exact:true}).first().click();await expect(panel).toContainText('decision #')
  await ask('What if I buy only 20 pcs?');await expect(panel).toContainText('Hypothetical scenario');expect(JSON.parse(fixture('verify').stdout)).toEqual(before)
  await ask('Prepare the recommended option');await expect(panel.getByRole('heading',{name:'Review Purchase Request draft'})).toBeVisible();expect(JSON.parse(fixture('verify').stdout).requests).toBe(0)
  await ask('yes');await expect(panel).toContainText('a chat message “yes” never performs an action');expect(JSON.parse(fixture('verify').stdout).requests).toBe(0)
  const button=panel.getByRole('button',{name:'Confirm — create Purchase Request draft',exact:true});await button.evaluate(el=>{el.click();el.click()});await expect(panel.getByRole('link',{name:/Open Purchase Request/})).toBeVisible();const after=JSON.parse(fixture('verify').stdout);expect(after.requests).toBe(1);expect(after.business).toEqual(before.business)
  await panel.getByRole('link',{name:/Open Purchase Request/}).click();await expect(page).toHaveURL(/procurement\?request=\d+/)
  await page.goto('/intelligence-assistant');await expect(panel.getByLabel('Question for AIMS',{exact:true})).toBeVisible();for(const width of [1366,768,390]){await page.setViewportSize({width,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)).toBeTruthy()}
  await page.evaluate(async()=>{const{useSettingsStore}=await import('/src/store/settingsStore.js');useSettingsStore.getState().applyPreferences({theme:'dark',language:'sq'})});await expect(panel).toContainText('Asistenti inteligjent');await page.screenshot({path:testInfo.outputPath('copilot-dark-mobile.png')});expect(errors).toEqual([])
})
