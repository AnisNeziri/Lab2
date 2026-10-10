const fs=require('node:fs'),path=require('node:path'),{spawn,spawnSync}=require('node:child_process'),{performance}=require('node:perf_hooks')
const {chromium}=require('../frontend/node_modules/playwright')
const root=path.resolve(__dirname,'..'),backend=path.join(root,'backend')
const evidence=JSON.parse(fs.readFileSync(path.join(root,'output/pr1-independent-restore.json'),'utf8'))
if(evidence.status!=='PASS')throw Error('A verified independent synthetic restore is required first.')
const install=path.resolve(evidence.installation_b),ownedRoot=path.join(root,'output')+path.sep
if(!install.startsWith(ownedRoot)||!fs.existsSync(path.join(install,'aims.sqlite')))throw Error('Owned certification installation required.')
const php=process.env.AIMS_CERTIFICATION_PHP || (process.platform==='win32'?path.join(root,'desktop/runtime/php/php.exe'):'php')
const env={...process.env,APP_ENV:'e2e',APP_DEBUG:'false',APP_KEY:'base64:'+require('node:crypto').randomBytes(32).toString('base64'),DB_CONNECTION:'sqlite',DB_DATABASE:path.join(install,'aims.sqlite'),DB_URL:'',CACHE_STORE:'file',SESSION_DRIVER:'file',QUEUE_CONNECTION:'database',MAIL_MAILER:'array',REDIS_ENABLED:'false',BROADCAST_CONNECTION:'log',TRACKING_EXTERNAL_ENABLED:'false',APP_CONFIG_CACHE:path.join(install,'unused-config.php'),LARAVEL_STORAGE_PATH:path.join(install,'storage'),AIMS_DOCUMENT_ROOT:path.join(install,'documents'),AIMS_BACKUP_ROOT:path.join(install,'backups'),AIMS_COMPANY_TIMEZONE:'Europe/Budapest',APP_TIMEZONE:'UTC'}
for(const dir of ['framework/cache/data','framework/sessions','framework/views','logs'])fs.mkdirSync(path.join(install,'storage',dir),{recursive:true})
const api=spawn(php,['artisan','serve','--no-reload','--host=127.0.0.1','--port=8032'],{cwd:backend,env,windowsHide:true,stdio:'ignore'})
const web=spawn(process.execPath,[path.join(root,'frontend/node_modules/vite/bin/vite.js'),'preview','--host','127.0.0.1','--port','4192','--strictPort'],{cwd:path.join(root,'frontend'),env:{...process.env,VITE_API_PROXY_TARGET:'http://127.0.0.1:8032'},windowsHide:true,stdio:'ignore'})
let browser
const report={release:JSON.parse(fs.readFileSync(path.join(root,'RELEASE.json'))).version,data:'verified PM3 synthetic restore',build:'normal minified React production build',backend:'single-process loopback PHP; synthetic E2E environment',method:'three warm browser actions, measured until required HTTP response completed and UI was painted; milliseconds; baseline, not a load/SLA test',timings:{},status:'FAIL'}
async function ready(url){for(let i=0;i<120;i++){try{if((await fetch(url)).ok)return}catch{}await new Promise(r=>setTimeout(r,500))}throw Error('Performance test server unavailable')}
async function main(){
 await Promise.all([ready('http://127.0.0.1:8032/up'),ready('http://127.0.0.1:4192/login')])
 browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(60000)
 const errors=[];page.on('pageerror',e=>errors.push(e.message))
 await page.goto('http://127.0.0.1:4192/login');await page.getByLabel('Email address').fill('owner@aims-demo.test');await page.getByLabel('Password',{exact:true}).fill('AimsDemo.Test.2026!');await page.getByRole('button',{name:'Enter workspace'}).click();await page.waitForURL('**/dashboard')
 const token=await page.evaluate(()=>localStorage.getItem('api_token'))
 async function get(endpoint){const response=await fetch('http://127.0.0.1:8032/api'+endpoint,{headers:{Authorization:'Bearer '+token,Accept:'application/json'}});if(!response.ok)throw Error('Fixture API failed '+endpoint);return response.json()}
 const products=await get('/products?per_page=1'),shipments=await get('/shipments');const product=(products.data||products)[0],shipment=(shipments.data||shipments)[0]
 if(!product||!shipment)throw Error('Representative product and shipment required')
 async function measure(label,action,predicate,render){report.current_measurement=label;console.log('Measuring '+label);const samples=[];for(let i=0;i<3;i++){const response=page.waitForResponse(r=>r.url().includes('/api/')&&predicate(new URL(r.url()).pathname));const start=performance.now();await action();const result=await response;if(!result.ok())throw Error(label+' returned '+result.status());await result.finished();if(render)await render();await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));samples.push(Math.round((performance.now()-start)*100)/100)}const sorted=[...samples].sort((a,b)=>a-b);report.timings[label]={samples_ms:samples,median_ms:sorted[1],max_ms:sorted[2]}}
 const go=route=>()=>page.goto('http://127.0.0.1:4192'+route)
 await measure('Dashboard',go('/dashboard'),p=>p==='/api/settings/workspace',()=>page.locator('.workspace-widget-grid').waitFor())
 await measure('Order Hub',go('/order-hub'),p=>p==='/api/order-hub')
 await measure('Product detail',go('/products?product='+product.id),p=>p==='/api/products/'+product.id,()=>page.getByRole('dialog').waitFor())
 await measure('Inventory planning',go('/inventory-intelligence?view=planning&product='+product.id),p=>p==='/api/analytics/planning/products/'+product.id)
 await measure('Shipment detail',go('/shipments/my-shipments?shipment='+shipment.id),p=>p==='/api/shipments/'+shipment.id)
 await measure('Financial intelligence',go('/financial-intelligence'),p=>p==='/api/financial-intelligence')
 await measure('Customer intelligence',go('/customer-sales-intelligence'),p=>p==='/api/customer-sales-intelligence')
 await page.goto('http://127.0.0.1:4192/dashboard');const search=page.getByRole('combobox',{name:'Search AIMS'});let query=0
 await measure('Global search',async()=>{await search.fill('');await search.fill(query++%2?'Milano':'Milano ')},p=>p==='/api/search')
 await page.keyboard.press('Escape');await page.getByRole('button',{name:'Ask AIMS',exact:true}).click();const panel=page.locator('.aims-assistant'),question=panel.getByRole('textbox',{name:'Question for AIMS',exact:true})
 await measure('Ask AIMS',async()=>{await panel.getByRole('button',{name:'Send question',exact:true}).isEnabled().catch(()=>{});await question.fill('Who owes us the most?');await panel.getByRole('button',{name:'Send question',exact:true}).click()},p=>p==='/api/intelligence-assistant/ask',()=>panel.getByRole('button',{name:'Send question',exact:true}).waitFor())
 if(errors.length)throw Error('Browser runtime errors: '+errors.join(', '));report.status='PASS'
}
main().catch(e=>{report.failure=e.message;process.exitCode=1}).finally(async()=>{if(browser)await browser.close();for(const child of [web,api]){if(process.platform==='win32')spawnSync('taskkill.exe',['/pid',String(child.pid),'/t','/f'],{windowsHide:true,stdio:'ignore'});else child.kill('SIGTERM')}fs.writeFileSync(path.join(root,'output/pr1-production-browser-performance.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,failure:report.failure,timings:report.timings}))})
