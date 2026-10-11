import {test,expect} from '@playwright/test'
import {mkdirSync,writeFileSync,readFileSync} from 'node:fs'
import path from 'node:path'
test.setTimeout(300000)
const output=path.resolve('../output/pm4-extra')
const api=(page,url)=>page.evaluate(async url=>{const {apiRequest}=await import('/src/api/client.js');return apiRequest(url)},url)
const prefs=(page,theme,language)=>page.evaluate(async values=>{const {useSettingsStore}=await import('/src/store/settingsStore.js');await useSettingsStore.getState().savePreferences(values)},{theme,language})
async function login(page){await page.goto('/login');await page.locator('#email-address').fill('owner@aims-demo.test');await page.locator('input[type=password]').fill('AimsDemo.Test.2026!');await page.locator('button[type=submit]').click();await expect(page).toHaveURL(/dashboard$/)}
async function ask(page,question){const response=page.waitForResponse(r=>r.url().includes('/intelligence-assistant/ask')&&r.request().method()==='POST');await page.getByRole('textbox',{name:/Question for AIMS|Pyetje për AIMS/}).fill(question);await page.getByRole('button',{name:/Send question|Dërgo pyetjen/,exact:true}).click();const result=await response;expect(result.status()).toBe(200);await expect(page.locator('.assistant-working')).toHaveCount(0);return result.json()}
test('PM3 assistant typo, mixed language, ambiguity and continuous follow-ups',async({page})=>{
 mkdirSync(output,{recursive:true});const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page);await prefs(page,'light','en');await page.goto('/intelligence-assistant');await expect(page.locator('.assistant-composer input')).toBeVisible()
 const answers=[]
 for(const question of ['whats product most risk stock','why first one','which suplier can cover','what if shipment 10 days late','how much i need order']){const r=await ask(page,question);answers.push({question,intent:r.intent,context:r.context,choices:r.choices,sources:r.sources.map(s=>s.tool),state:r.state});if(question==='why first one')expect(r.context?.id).toBeTruthy();if(question==='which suplier can cover')expect(r.sources.map(s=>s.tool)).toContain('get_supplier_options');if(question==='how much i need order')expect(r.context?.type).toBe('product')}
 await expect(page.locator('.assistant-context')).toBeVisible()
 const replyVisible=await page.locator('.assistant-transcript').evaluate(log=>{const reply=log.querySelector('.assistant-message:last-child'),bounds=log.getBoundingClientRect(),top=reply.getBoundingClientRect().top;return top>=bounds.top-2&&top<bounds.bottom})
 expect(replyVisible,'New answers start in view, rather than at their feedback footer').toBe(true);await page.screenshot({path:path.join(output,'assistant-followups.png')})
 await page.getByRole('button',{name:'Clear conversation',exact:true}).click()
 for(const question of ['cilat produkte kan me met pa stock','show costumers with most borxh','where is shipemnt from china','milnao 04 status','show Milano']){const r=await ask(page,question);answers.push({question,intent:r.intent,context:r.context,choices:r.choices,sources:r.sources.map(s=>s.tool),state:r.state});if(question==='show Milano'){expect(r.choices.length).toBeGreaterThan(1);expect(r.cards).toHaveLength(0)}}
 await page.screenshot({path:path.join(output,'assistant-ambiguity.png')});expect(errors).toEqual([]);writeFileSync(path.join(output,'assistant-verification.json'),JSON.stringify({answers,errors},null,2))
})
test('PM4 visible-page visual pass uses the existing permitted navigation',async({page})=>{
 mkdirSync(output,{recursive:true});await login(page);await page.setViewportSize({width:1366,height:768})
 const routes=await page.evaluate(async()=>{const {permittedNavigation}=await import('/src/config/navigation.js');const {useAuthStore}=await import('/src/store/authStore.js');const {user,permissions}=useAuthStore.getState();return permittedNavigation(permissions,false,user.role).flatMap(g=>g.items.map(i=>({id:i.id,path:i.path})))})
 const checks=[],shots=[];let current='';page.on('pageerror',e=>checks.push({path:current,error:e.message}))
 try{for(const theme of ['light','dark']){await prefs(page,theme,theme==='dark'?'sq':'en');for(const route of routes){
  current=route.path;await page.goto(route.path);await page.waitForLoadState('networkidle');await expect(page.locator('.page-state').filter({hasText:/Something went wrong|unexpected error/})).toHaveCount(0);await expect(page.locator('#workspace-main')).toBeVisible();await expect(page.locator('#workspace-main h1').first()).toBeVisible();await expect(page.locator('.shipments-intro')).toHaveCount(0)
  const result=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth>window.innerWidth+1,title:document.querySelector('#workspace-main h1')?.textContent}));checks.push({path:route.path,theme,...result});expect(result.overflow,route.path).toBe(false)
  const file=path.join(output,`visual-${theme}-${route.id.replaceAll('/','-')}.png`);await page.screenshot({path:file});shots.push({file,label:theme+' · '+route.id})
 }}
 }finally{await prefs(page,'light','en')}
 writeFileSync(path.join(output,'visual-verification.json'),JSON.stringify(checks,null,2))
 expect(checks.filter(c=>c.error)).toEqual([])
 for(let i=0;i<shots.length;i+=8){const batch=shots.slice(i,i+8);await page.setViewportSize({width:1366,height:1656});await page.setContent('<html><body style="margin:0;background:#d9e1ec;display:grid;grid-template-columns:1fr 1fr;gap:8px;font:14px sans-serif">'+batch.map(s=>`<section style="background:white"><div style="padding:6px">${s.label}</div><img style="display:block;width:100%" src="data:image/png;base64,${readFileSync(s.file).toString('base64')}"></section>`).join('')+'</body></html>');await page.screenshot({path:path.join(output,`visual-sheet-${i/8+1}.png`)})}
})
test('PM3 linked vessel cargo, search, receipt links, impact and responsive EN/SQ themes',async({page})=>{
 mkdirSync(output,{recursive:true});const errors=[];page.on('pageerror',e=>errors.push(e.message));await login(page)
 const shipments=await api(page,'/shipments');const candidate=shipments.filter(s=>s.purchase_order_id).sort((a,b)=>(b.items_count||0)-(a.items_count||0))[0];expect(candidate).toBeTruthy()
 const detail=await api(page,'/shipments/'+candidate.id);expect(detail.cargo.groups.length).toBeGreaterThan(0);expect(detail.cargo.groups.flatMap(g=>g.items).length).toBeGreaterThan(1)
 const checks=[]
 try{for(const [width,theme,language] of [[1366,'light','en'],[1366,'dark','sq'],[390,'light','sq'],[390,'dark','en']]){
  await page.setViewportSize({width,height:768});await prefs(page,theme,language);await page.goto('/shipments/my-shipments?shipment='+candidate.id);await expect(page.locator('.shipment-workspace .cargo-heading')).toContainText(candidate.tracking_number);await expect(page.locator('.cargo-product').first()).toBeVisible();await expect(page.locator('.cargo-impact p').first()).not.toContainText(/Loading|Duke ngarkuar/)
  await expect(page.locator('.shipments-intro')).toHaveCount(0);const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+1);expect(overflow).toBe(false)
  expect(await page.locator('.shipments-list-item').evaluateAll(items=>items.every(item=>item.scrollHeight<=item.clientHeight+1)),'Shipment list text is not clipped').toBe(true)
  expect(await page.locator('.shipment-compact-map').evaluate(map=>{const message=map.querySelector('.map-pending-vessel-detail');if(!message)return true;const a=map.getBoundingClientRect(),b=message.getBoundingClientRect();return b.top>=a.top&&b.bottom<=a.bottom}),'Unknown AIS position message is inside the compact map').toBe(true)
  const first=detail.cargo.groups.flatMap(g=>g.items)[0];await page.getByRole('searchbox',{name:/Search cargo|Kërko ngarkesën/}).fill(first.sku||first.name);await expect(page.locator('.cargo-product')).toHaveCount(1);await page.getByRole('searchbox',{name:/Search cargo|Kërko ngarkesën/}).fill('')
  await page.locator('.cargo-heading').scrollIntoViewIfNeeded()
  if(width===1366)expect(await page.locator('.cargo-product').last().evaluate(el=>el.getBoundingClientRect().bottom<=window.innerHeight),'Representative three-PO cargo fits in the vessel workspace screen').toBe(true)
  await page.screenshot({path:path.join(output,`shipment-${width}-${theme}-${language}.png`)})
  if(width<600){await page.locator('.shipment-cargo').evaluate(el=>el.scrollIntoView({block:'start'}));await page.screenshot({path:path.join(output,`shipment-cargo-${width}-${theme}-${language}.png`)})}
  checks.push({width,theme,language,overflow,cargo:detail.cargo.product_count})
 }
 await page.locator('.shipments-list-item').nth(1).click();await expect(page).not.toHaveURL(new RegExp('shipment='+candidate.id+'$'));await expect(page.locator('.cargo-heading')).not.toContainText(candidate.tracking_number)
 await page.goBack();await expect(page.locator('.cargo-heading')).toContainText(candidate.tracking_number);await expect(page.locator('.cargo-product')).toHaveCount(detail.cargo.product_count)
 const po=page.locator('.cargo-po-group>header a').first();await po.click();await expect(page).toHaveURL(/purchase-orders\?po=/);expect(errors).toEqual([])
 }finally{await prefs(page,'light','en')}
 writeFileSync(path.join(output,'shipment-verification.json'),JSON.stringify({checks,errors,reference:candidate.tracking_number},null,2))
})
