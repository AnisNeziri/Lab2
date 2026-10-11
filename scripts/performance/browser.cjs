const fs=require('node:fs'),path=require('node:path'),{pathToFileURL}=require('node:url')
const {spawn,spawnSync}=require('node:child_process'),{performance}=require('node:perf_hooks')
const {chromium}=require('../../frontend/node_modules/playwright')
const root=path.resolve(__dirname,'../..'),php=process.env.AIMS_PERFORMANCE_PHP||'php',target=process.argv[2]
const setup=spawnSync(php,[path.join(__dirname,'prepare.php'),target],{cwd:root,windowsHide:true,encoding:'utf8',maxBuffer:1024*1024})
if(setup.status||!setup.stdout.trim().startsWith('{'))throw Error('Guarded browser fixture setup failed: '+setup.stdout.slice(0,2000))
const fixture=JSON.parse(setup.stdout),env={...process.env,...fixture.environment}
const children=[],report={status:'FAIL',data:'PERFORMANCE / TEST DATA; 1,000 products, 2,000 customers, 50,000 orders, 5,000 shipments. Frozen mock read shapes, not prediction certification.',method:'Headless Chromium, normal minified production build, one loopback PHP HTTP worker; real responses and rendered DOM. Not a VPS/SLA test.',routes:{},tabs:{},dashboard:{},memory:[],runtime_errors:[],http_errors:[],recorded_at:new Date().toISOString()}
let browser,page,cdp
async function api(method,route,body){
 const r=await fetch('http://127.0.0.1:8036/api'+route,{method,headers:{Authorization:'Bearer '+fixture.owner_token,Accept:'application/json','Content-Type':'application/json'},body:body?JSON.stringify(body):undefined})
 if(!r.ok)throw Error('Fixture API '+route+' failed '+r.status)
 return r.json()
}
async function ready(url){for(let n=0;n<120;n++){try{if((await fetch(url)).ok)return}catch{}await new Promise(r=>setTimeout(r,250))}throw Error('Browser test server unavailable')}
async function goto(label,route,predicate,render){
 console.log('Browser '+label)
 const response=page.waitForResponse(r=>r.url().includes('/api/')&&predicate(new URL(r.url())));response.catch(()=>{})
 const start=performance.now();await page.goto('http://127.0.0.1:4196'+route);const r=await response
 if(!r.ok())throw Error(label+' API failed '+r.status())
 await r.finished();if(render)await render()
 await page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))))
 report.routes[label]={ms:Math.round((performance.now()-start)*100)/100,response_bytes:Buffer.byteLength(await r.body()),status:r.status()}
}
async function heap(label){await cdp.send('HeapProfiler.collectGarbage');const metrics=await cdp.send('Performance.getMetrics');report.memory.push({label,js_heap_used_bytes:metrics.metrics.find(m=>m.name==='JSHeapUsedSize').value,nodes:metrics.metrics.find(m=>m.name==='Nodes').value,documents:metrics.metrics.find(m=>m.name==='Documents').value})}
async function main(){
 children.push(spawn(php,['-S','127.0.0.1:8036','-t',path.join(root,'backend/public'),path.join(root,'backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'backend/public'),env,windowsHide:true,stdio:'ignore'}))
 children.push(spawn(process.execPath,[path.join(root,'frontend/node_modules/vite/bin/vite.js'),'preview','--host','127.0.0.1','--port','4196','--strictPort'],{cwd:path.join(root,'frontend'),env:{...process.env,VITE_API_PROXY_TARGET:'http://127.0.0.1:8036'},windowsHide:true,stdio:'ignore'}))
 await Promise.all([ready('http://127.0.0.1:8036/up'),ready('http://127.0.0.1:4196/login')])
 browser=await chromium.launch({headless:true});page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(60000);cdp=await page.context().newCDPSession(page);await cdp.send('Performance.enable')
 page.on('pageerror',e=>report.runtime_errors.push(e.message))
 page.on('response',r=>{if(r.url().includes('/api/')&&r.status()>=400)report.http_errors.push({path:new URL(r.url()).pathname,status:r.status()})})
 const start=performance.now();await page.goto('http://127.0.0.1:4196/login');await page.getByLabel('Email address').waitFor()
 report.login_load_ms=Math.round(performance.now()-start)
 report.login_assets=await page.evaluate(()=>performance.getEntriesByType('resource').map(r=>({url:new URL(r.name).pathname,bytes:r.transferSize})))
 await page.getByLabel('Email address').fill('actor0@performance.aims.test');await page.getByLabel('Password',{exact:true}).fill('Aims.Performance.2026!');await page.getByRole('button',{name:'Enter workspace'}).click();await page.waitForURL('**/dashboard')
 await goto('Dashboard','/dashboard',u=>u.pathname==='/api/settings/workspace',()=>page.locator('.workspace-widget-grid').waitFor())
 await goto('Products','/products?product='+fixture.product_detail_id,u=>u.pathname==='/api/products/'+fixture.product_detail_id,()=>page.getByRole('dialog').waitFor())
 await goto('Order Hub','/order-hub',u=>u.pathname==='/api/order-hub')
 await goto('Stock movements','/stock',u=>u.pathname==='/api/stock-movements',()=>page.locator('table tbody tr').first().waitFor())
 if(await page.getByRole('button',{name:'Next',exact:true}).count()){const waiting=page.waitForResponse(r=>r.url().includes('/stock-movements?')&&new URL(r.url()).searchParams.get('page')==='2');await page.getByRole('button',{name:'Next',exact:true}).click();await waiting;report.stock_pagination='PASS'}
 await goto('Shipment detail','/shipments/my-shipments?shipment='+fixture.shipment_id,u=>u.pathname==='/api/shipments/'+fixture.shipment_id)
 const financial=['Overview','Cash forecast','Customer collections','Supplier commitments','Inventory capital','Compare scenarios','Data & model health'],financeViews=['overview','cash','receivables','commitments','inventory','scenarios','health']
 const customerViews=['overview','opportunities','atRisk','trends','affinity','concentration','health'],{customerSalesLabel}=await import(pathToFileURL(path.join(root,'frontend/src/pages/customerSalesPresentation.js')).href),customer=customerViews.map(v=>customerSalesLabel(v,'en'))
 for(const [label,route,selector,names,views] of [['Finance','/financial-intelligence','.fi-tabs',financial,financeViews],['Customers','/customer-sales-intelligence','.csi-tabs',customer,customerViews]]){
  await goto(label,route,u=>u.pathname==='/api'+route,()=>page.locator(selector).waitFor())
  for(let i=1;i<views.length;i++){
   const waiting=page.waitForResponse(r=>new URL(r.url()).searchParams.get('view')===views[i]&&r.url().includes('/api'+route));waiting.catch(()=>{});console.log('Tab '+label+' '+views[i]);const t=performance.now()
   await page.locator(selector).getByRole('button').nth(i).click();const r=await waiting;await r.finished()
   await page.waitForTimeout(150);report.tabs[label+' '+views[i]]={ms:Math.round(performance.now()-t),bytes:Buffer.byteLength(await r.body()),status:r.status()}
   if(!r.ok())throw Error(label+' tab '+views[i]+' failed')
   if(i===2&&await page.locator('.evidence-pagination').getByRole('button',{name:'Next',exact:true}).count()){
    const next=page.waitForResponse(r=>new URL(r.url()).searchParams.get('page')==='2'&&r.url().includes('/api'+route));next.catch(()=>{});await page.locator('.evidence-pagination').getByRole('button',{name:'Next',exact:true}).click();await next
   }
  }
 }
 const {widgetCatalog}=await import(pathToFileURL(path.join(root,'frontend/src/config/dashboardWidgets.js')).href)
 for(const count of [5,10,18,25]){
  const workspace=await api('GET','/settings/workspace'),widgets=widgetCatalog.slice(0,count).map((w,position)=>({id:w.id,position,size:w.defaultSize,settings:w.settings}))
  await api('PUT','/settings/workspace',{revision:workspace.revision,dashboard:{version:1,widgets}})
  let inflight=0,peak=0;const reads=[],pending=new Set()
  const requested=r=>{if(r.url().includes('/api/')){pending.add(r);inflight++;peak=Math.max(peak,inflight)}}
  const finished=r=>{if(pending.delete(r))inflight--}
  const responded=async r=>{if(r.url().includes('/api/')){let bytes=0;try{bytes=Buffer.byteLength(await r.body())}catch{}reads.push({path:new URL(r.url()).pathname+new URL(r.url()).search,bytes,status:r.status()})}}
  page.on('request',requested);page.on('requestfinished',finished);page.on('requestfailed',finished);page.on('response',responded)
  const workspaceRead=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/settings/workspace');workspaceRead.catch(()=>{})
  const t=performance.now();await page.goto('http://127.0.0.1:4196/dashboard');await (await workspaceRead).finished()
  await page.waitForFunction(n=>document.querySelectorAll('[data-widget]').length===n,count)
  await page.locator('.workspace-widget-grid').waitFor();const gridMs=performance.now()-t
  for(const widget of widgets){
   const item=page.locator('[data-widget="'+widget.id+'"]');await item.evaluate(el=>el.scrollIntoView({block:'center'}))
   await item.locator('.widget-skeleton').waitFor({state:'hidden',timeout:60000})
  }
  for(let n=0;n<180&&pending.size;n++)await page.waitForTimeout(200)
  await page.waitForTimeout(300);page.off('request',requested);page.off('requestfinished',finished);page.off('requestfailed',finished);page.off('response',responded)
  report.dashboard[count]={widgets:await page.locator('[data-widget]').count(),grid_ready_ms:Math.round(gridMs),all_requested_widgets_ms:Math.round(performance.now()-t),api_reads:reads.length,peak_api_inflight:peak,payload_bytes:reads.reduce((n,r)=>n+r.bytes,0),reads,widget_errors:await page.locator('.widget-error').count(),remaining_skeletons:await page.locator('.widget-skeleton').count()}
 }
 await page.getByRole('button',{name:'Search AIMS Ctrl K',exact:true}).click();const search=page.getByRole('combobox',{name:'Search AIMS'});const searched=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/search');searched.catch(()=>{});await search.fill('Performance Product 19');await searched;await page.keyboard.press('Escape')
 await page.getByRole('button',{name:'Ask AIMS',exact:true}).click();const asked=page.waitForResponse(r=>new URL(r.url()).pathname==='/api/intelligence-assistant/ask');asked.catch(()=>{});await page.locator('.aims-assistant').getByRole('textbox',{name:'Question for AIMS',exact:true}).fill('Who owes us the most?');await page.locator('.aims-assistant').getByRole('button',{name:'Send question',exact:true}).click();const answer=await asked;await answer.finished();report.ask_status=answer.status()
 await heap('Warm all routes and widgets')
 for(let cycle=1;cycle<=5;cycle++){
  for(const route of ['/products?product='+fixture.product_detail_id,'/shipments/my-shipments?shipment='+fixture.shipment_id,'/order-hub','/financial-intelligence','/customer-sales-intelligence','/dashboard']){
   await page.evaluate(route=>{history.pushState({},'',route);window.dispatchEvent(new PopStateEvent('popstate'))},route)
   await page.waitForTimeout(1000)
   await page.locator('main').first().waitFor()
   if(route.startsWith('/products')){await page.getByRole('dialog').waitFor();await page.getByRole('dialog').getByRole('button',{name:/close/i}).first().click().catch(()=>{})}
   if(route==='/dashboard'){await page.locator('.workspace-widget-grid').waitFor();await page.waitForTimeout(2000)}
  }await heap('Navigation cycle '+cycle)
 }
 if(report.runtime_errors.length||report.http_errors.length||Object.values(report.dashboard).some(r=>r.widget_errors||r.remaining_skeletons))throw Error('Browser errors occurred')
 report.status='PASS'
}
main().catch(async e=>{report.failure=e.message;process.exitCode=1;if(page){report.failed_url=page.url();report.failed_text=(await page.locator('body').innerText().catch(()=>'' )).slice(-4000);await page.screenshot({path:path.join(root,'output/pr2-browser-failure.png')}).catch(()=>{})}}).finally(async()=>{if(browser)await browser.close();for(const c of children){if(process.platform==='win32')spawnSync('taskkill.exe',['/pid',String(c.pid),'/t','/f'],{windowsHide:true,stdio:'ignore'});else c.kill('SIGTERM')}fs.writeFileSync(path.join(root,'output/pr2-browser-'+fixture.state.engine+'.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,failure:report.failure,errors:report.runtime_errors,http_errors:report.http_errors,routes:report.routes,tabs:report.tabs,dashboard:report.dashboard,memory:report.memory}))})

