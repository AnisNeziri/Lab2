import {test,expect} from '@playwright/test'
import {readFileSync} from 'node:fs'
import {fileURLToPath} from 'node:url'
import {spawnSync} from 'node:child_process'
import path from 'node:path'
test('Integrations: narrow credential → real MCP stdio read/rejected write → hybrid search → technical health',async({page})=>{
 test.setTimeout(180000)
 const f=JSON.parse(readFileSync(fileURLToPath(new URL('../../../output/dc7-fixture.json',import.meta.url)),'utf8')),errors=[]
 page.on('pageerror',e=>errors.push(e.message))
 await page.goto('/login');await page.getByLabel(/Email address|Adresa e emailit/).fill(f.email);await page.getByLabel(/^(Password|Fjalëkalimi)$/).fill('dc7-local-test-secret');await page.getByRole('button',{name:/Enter workspace|Hyr në sistem/}).click();await expect(page).toHaveURL(/dashboard$/)
 await page.evaluate(()=>window.__aimsCertification.useSettingsStore.getState().applyPreferences({language:'en',theme:'light'}))
 const loaded=page.waitForResponse(r=>r.url().endsWith('/api/platform'),{timeout:30000});await page.goto('/integrations');expect((await loaded).status()).toBe(200)
 await page.getByRole('button',{name:'API & MCP',exact:true}).click();await page.getByRole('checkbox',{name:'Enable MCP',exact:true}).check();await page.getByRole('checkbox',{name:'Enable versioned API',exact:true}).check();await page.getByRole('checkbox',{name:'Enable local technical tracing',exact:true}).check();await page.getByRole('button',{name:'Save access policy',exact:true}).click()
 await page.getByRole('button',{name:'Service accounts',exact:true}).click();await page.getByRole('textbox',{name:'Account name',exact:true}).fill('Warehouse read client');await page.getByRole('button',{name:'Create credential',exact:true}).click()
 const secret=page.getByRole('region',{name:'One-time credential'});await expect(secret).toBeVisible();const credential=await secret.locator('code').textContent();expect(credential).toMatch(/^aims_svc_/)
 const meta={'io.modelcontextprotocol/protocolVersion':'2026-07-28','io.modelcontextprotocol/clientInfo':{name:'Playwright stdio client',version:'1'},'io.modelcontextprotocol/clientCapabilities':{}}
 const messages=[{jsonrpc:'2.0',id:1,method:'server/discover',params:{_meta:meta}},{jsonrpc:'2.0',id:2,method:'tools/call',params:{_meta:meta,name:'get_inventory_status',arguments:{product_id:f.product}}},{jsonrpc:'2.0',id:3,method:'tools/call',params:{_meta:meta,name:'adjust_stock',arguments:{product_id:f.product,quantity:500}}}]
 const backend=fileURLToPath(new URL('../../../backend/',import.meta.url)),php=path.join(backend,'../desktop/resources/php/php.exe')
 const result=spawnSync(php,['artisan','aims:mcp'],{cwd:backend,input:messages.map(m=>JSON.stringify(m)).join('\n')+'\n',env:{...process.env,APP_ENV:'e2e',APP_KEY:'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=',DB_CONNECTION:'sqlite',DB_DATABASE:path.join(backend,'database/dc7-preview.sqlite'),DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',BROADCAST_CONNECTION:'log',AIMS_MCP_TOKEN:credential},encoding:'utf8',windowsHide:true,timeout:30000})
 expect(result.status,result.stderr).toBe(0);const replies=result.stdout.trim().split(/\r?\n/).map(s=>JSON.parse(s));expect(replies[1].result.structuredContent.data.position.available).toBe(1450);expect(replies[2].error).toBeTruthy();expect(replies[0].result.supportedVersions).toContain('2026-07-28')
 await secret.getByRole('button',{name:'Dismiss credential'}).click();await page.keyboard.press('Control+k');const search=page.getByRole('combobox');await search.fill('fabric coming from china for ABC customer');await expect(page.getByRole('listbox')).toContainText('ABC Milano fabric customs China shipment',{timeout:30000});await page.screenshot({path:fileURLToPath(new URL('../../../output/dc7-hybrid-search.png',import.meta.url))});await page.keyboard.press('Escape')
 await page.getByRole('button',{name:'Integration health',exact:true}).click();await expect(page.getByRole('heading',{name:'Integration health',exact:true})).toBeVisible();await page.setViewportSize({width:390,height:844});const closeNav=page.getByRole('button',{name:'Close navigation',exact:true});await expect(closeNav).not.toBeInViewport();await expect.poll(()=>page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);await page.screenshot({path:fileURLToPath(new URL('../../../output/dc7-mobile-integrations.png',import.meta.url)),fullPage:true});expect(errors).toEqual([])
})
