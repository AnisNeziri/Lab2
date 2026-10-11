import { test, expect } from '@playwright/test'
import { mkdirSync } from 'node:fs'
import path from 'node:path'
import { login } from '../helpers/auth.mjs'

const output = path.resolve('../output/pm1/controls')
const preferences = (page, theme, language) => page.evaluate(async values => {
  const { useSettingsStore } = window.__aimsCertification
  useSettingsStore.getState().applyPreferences(values)
}, { theme, language })

test('public entry screens remain responsive with translated authentication and reduced motion', async ({ page }) => {
  test.setTimeout(90000)
  mkdirSync(output,{recursive:true})
  await page.emulateMedia({reducedMotion:'reduce'})
  const errors=[];page.on('pageerror',e=>errors.push(e.message))
  for (const width of [1366,390]) for (const route of ['/','/login','/register']) {
    await page.setViewportSize({width,height:width===390?844:768})
    await page.goto(route)
    await page.waitForLoadState('networkidle')
    for(const [theme,language] of [['light','en'],['dark','sq']]) {
      await preferences(page,theme,language)
      await expect(page.locator('h1').first()).toBeVisible()
      if (route==='/login') await expect(page.locator('h1')).toHaveText(language==='sq'?'Mirë se u ktheve':'Welcome back')
      if (route==='/register') await expect(page.locator('h1')).toHaveText(language==='sq'?'Regjistro kompaninë':'Register your company')
      await page.evaluate(()=>document.fonts.ready)
      await page.waitForTimeout(150)
      expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width+2)
      await page.screenshot({path:path.join(output,`${route.slice(1)||'landing'}-${theme}-${language}-${width}.png`),animations:'disabled'})
    }
  }
  expect(errors).toEqual([])
})

test('scope selection keeps its value across keyboard disclosure and aligns with sibling controls',async({page})=>{
  mkdirSync(output,{recursive:true})
  await page.setViewportSize({width:1366,height:768});await login(page);await page.goto('/supply-optimizer')
  const field=page.locator('.scope-picker-field').filter({hasText:'Warehouses'}).first(),trigger=field.locator('summary')
  await trigger.focus();await page.keyboard.press('Enter')
  const checkbox=field.getByRole('checkbox').first();await checkbox.check()
  await trigger.focus();await page.keyboard.press('Enter');await expect(checkbox).toBeHidden()
  await page.keyboard.press('Enter');await expect(checkbox).toBeChecked()
  await trigger.click()
  await expect(checkbox).toBeHidden()
  const controls=await page.locator('.optimizer-form').first().locator(':scope > label > select, :scope > label > input, :scope > .scope-picker-field > details > summary').evaluateAll(nodes=>nodes.map(n=>({top:n.getBoundingClientRect().top,height:n.getBoundingClientRect().height})))
  expect(controls).toHaveLength(3)
  for (const control of controls) expect(control.height).toBeCloseTo(40,0)
  expect(Math.max(...controls.map(c=>c.top))-Math.min(...controls.map(c=>c.top))).toBeLessThanOrEqual(2)
  await page.screenshot({path:path.join(output,'optimizer-scope.png'),animations:'disabled'})
  await page.goto('/decision-learning')
  const explanation=page.locator('.workspace-disclosure')
  await expect(explanation.locator('summary')).toContainText('How decisions are evaluated')
  await expect(explanation.locator('.learning-three')).toBeHidden()
  await explanation.locator('summary').focus();await page.keyboard.press('Enter')
  await expect(explanation.locator('.learning-three')).toBeVisible()
  await explanation.locator('summary').click()
  for (const route of ['/decision-learning','/invoices','/shipments/my-shipments']) {
    await page.goto(route)
    await page.waitForLoadState('networkidle')
    for (const [theme,language] of [['light','en'],['dark','sq']]) {
      await preferences(page,theme,language)
      await page.waitForTimeout(350)
      await expect(page.locator('h1').first()).toBeVisible()
      expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(1368)
      await page.screenshot({path:path.join(output,`${route.replaceAll('/','')}-${theme}-${language}-final.png`),animations:'disabled'})
    }
  }
})

test('product insight shows only supported evidence and warns for stale forecasts',async({page})=>{
  mkdirSync(output,{recursive:true})
  await login(page)
  let stale=false
  await page.route('**/api/analytics/intelligence/products/*',r=>r.fulfill({json:{stale,prediction:{value:{}},current:{coverage_supported:true,stockout_date:'2026-11-12'}}}))
  await page.goto('/products');await page.getByRole('button',{name:'View',exact:true}).first().click()
  await expect(page.locator('.product-context-insight')).toContainText('Possible stock shortage from 12 Nov 2026')
  await expect(page.locator('.product-context-insight a')).toHaveAttribute('href',/inventory-intelligence\?product=\d+/)
  await page.screenshot({path:path.join(output,'product-detail-insight.png'),animations:'disabled'})
  await page.keyboard.press('Escape');stale=true
  await page.getByRole('button',{name:'View',exact:true}).first().click()
  await expect(page.locator('.product-context-insight')).toContainText('More current evidence is needed')
  await expect(page.locator('.product-context-insight')).not.toContainText('12 Nov')
  await page.keyboard.press('Escape')
  expect(await page.evaluate(()=>getComputedStyle(document.body).overflow)).not.toBe('hidden')
})
