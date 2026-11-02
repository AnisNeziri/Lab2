import {test,expect} from '@playwright/test'
import {readFileSync} from 'node:fs'
import {fileURLToPath} from 'node:url'

test('Owner passkey → replay rejection → device revoke → package boundaries → sanitized support under CSP',async({page,context})=>{
 test.setTimeout(180000)
 const f=JSON.parse(readFileSync(fileURLToPath(new URL('../../../output/dc8-fixture.json',import.meta.url)),'utf8')),errors=[]
 page.on('pageerror',e=>errors.push(e.message))
 await page.addInitScript(()=>{window.__cspViolations=[];document.addEventListener('securitypolicyviolation',e=>window.__cspViolations.push({directive:e.violatedDirective,blocked:e.blockedURI}))})
 const cdp=await context.newCDPSession(page);await cdp.send('WebAuthn.enable');await cdp.send('WebAuthn.addVirtualAuthenticator',{options:{protocol:'ctap2',transport:'internal',hasResidentKey:true,hasUserVerification:true,isUserVerified:true,automaticPresenceSimulation:true}})
 await page.goto('/login');await page.getByLabel('Email address',{exact:true}).fill(f.email);await page.getByLabel('Password',{exact:true}).fill('dc8-synthetic-browser-secret');await page.getByRole('button',{name:'Enter workspace',exact:true}).click();await expect(page).toHaveURL(/dashboard$/)
 await page.evaluate(()=>window.__aimsCertification.useSettingsStore.getState().applyPreferences({language:'en',theme:'light'}))
 await page.goto('/security');await page.getByRole('tab',{name:'Passkeys',exact:true}).click();await expect(page.getByRole('heading',{name:'Passkeys',exact:true})).toBeVisible()
 await page.getByLabel('Passkey name',{exact:true}).fill('Warehouse owner passkey')
 const registration=page.waitForRequest(r=>r.url().endsWith('/api/security/passkeys/register/verify'));await page.getByRole('button',{name:'Add passkey',exact:true}).click();const replay=(await registration).postDataJSON();await expect(page.getByRole('cell',{name:'Warehouse owner passkey',exact:true})).toBeVisible()
 const api=async(path,method='GET',body)=>page.evaluate(async({path,method,body})=>{const r=await fetch('/api'+path,{method,headers:{'Content-Type':'application/json',Authorization:'Bearer '+localStorage.getItem('api_token')},...(body?{body:JSON.stringify(body)}:{})});return {status:r.status,data:await r.json().catch(()=>null)}},{path,method,body})
 await api('/logout','POST');await page.evaluate(()=>{localStorage.clear();sessionStorage.clear()});await page.goto('/login');await page.getByRole('button',{name:'Sign in with passkey',exact:true}).click();await expect(page).toHaveURL(/dashboard$/)
 expect((await api('/security/passkeys/register/verify','POST',replay)).status).toBe(422)
 const second=await page.evaluate(async({email})=>{const r=await fetch('/api/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password:'dc8-synthetic-browser-secret'})});return r.json()},f)
 await page.goto('/security');await page.getByRole('tab',{name:'Sessions',exact:true}).click();const other=page.getByRole('row').filter({has:page.getByRole('button',{name:'Revoke session',exact:true})});await expect(other).toHaveCount(1);await other.getByRole('button',{name:'Revoke session',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:'Revoke session',exact:true}).click();await expect(other).toHaveCount(0)
 const denied=await page.evaluate(async token=>(await fetch('/api/me',{headers:{Authorization:'Bearer '+token}})).status,second.access_token);expect(denied).toBe(401)
 await page.goto('/system-health');await page.getByRole('tab',{name:'Package & license',exact:true}).click();await page.getByRole('combobox',{name:'Package template',exact:true}).selectOption('core-accounting');await page.getByRole('button',{name:'Save company capabilities',exact:true}).click();await expect(page.getByRole('status')).toContainText('saved')
 expect((await api('/payroll')).status).toBe(403)
 expect(await page.evaluate(()=>window.__aimsCertification.useAuthStore.getState().permissions.includes('payroll.view'))).toBe(false)
 await page.getByRole('checkbox',{name:'Payroll',exact:true}).check();await page.getByRole('button',{name:'Save company capabilities',exact:true}).click();await expect.poll(async()=>(await api('/payroll')).status).toBe(200)
 await page.getByRole('tab',{name:'Support',exact:true}).click();const downloadPromise=page.waitForEvent('download');await page.getByRole('button',{name:'Generate Support Bundle',exact:true}).click();const download=await downloadPromise;const bundle=readFileSync(await download.path(),'utf8');expect(bundle).toContain('aims-support-v1');expect(bundle).not.toContain(second.access_token);expect(bundle).not.toContain('dc8-synthetic-browser-secret');expect(bundle).not.toContain('private_key')
 await page.setViewportSize({width:390,height:844});await expect(page.getByRole('button',{name:'Close navigation',exact:true})).not.toBeInViewport();await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:fileURLToPath(new URL('../../../output/dc8-mobile-security.png',import.meta.url)),fullPage:true})
 expect(errors).toEqual([]);expect(await page.evaluate(()=>window.__cspViolations)).toEqual([])
})
