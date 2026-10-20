const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto')
const {spawn,spawnSync,execFile}=require('node:child_process')
const {performance}=require('node:perf_hooks')
const root=path.resolve(__dirname,'../..'),php=process.env.AIMS_PERFORMANCE_PHP||'php'
const target=process.argv[2],workers=Number(process.env.AIMS_PERF_WEB_WORKERS||8)
if(!target||workers<1||workers>8)throw Error('Owned fixture and 1..8 web workers required.')
const prepared=spawnSync(php,[path.join(__dirname,'prepare.php'),target,'--migrate'],{cwd:root,env:process.env,windowsHide:true,encoding:'utf8',maxBuffer:1024*1024})
if(prepared.status!==0)throw Error('Owned fixture preparation failed: '+prepared.stdout.slice(-3000)+prepared.stderr.slice(-1000))
if(!prepared.stdout.trim().startsWith('{'))throw Error('Fixture setup did not return JSON: '+prepared.stdout.slice(0,1500))
const fixture=JSON.parse(prepared.stdout),env={...process.env,...fixture.environment}
const children=[],samples=[],resourceSamples=[]
const report={method:'25 distinct JWT sessions; eight independent loopback PHP HTTP workers share one marked database. Not PHP-FPM or a VPS/SLA test.',sessions:fixture.sessions.map(({id,role})=>({id,role})),workers,engine:fixture.state.engine,status:'FAIL',errors:[],checks:{},resource_samples:resourceSamples,requests:samples,recorded_at:new Date().toISOString()}
let monitor
async function observe(){
 const ids=children.map(c=>c.pid).concat(process.env.AIMS_PERF_DB_PID?[Number(process.env.AIMS_PERF_DB_PID)]:[])
 if(process.platform!=='win32'||!ids.length)return
 const command='Get-Process -Id '+ids.join(',')+' -ErrorAction SilentlyContinue | Select-Object Id,CPU,WorkingSet64,PrivateMemorySize64,HandleCount | ConvertTo-Json -Compress'
 await new Promise(resolve=>execFile('powershell.exe',['-NoProfile','-Command',command],{windowsHide:true},(error,stdout)=>{try{resourceSamples.push({at:new Date().toISOString(),processes:JSON.parse(stdout)})}catch{}resolve()}))
}
function databaseStatus(){
 if(fixture.state.engine!=='mysql'||!process.env.AIMS_PERF_MYSQL_CLI)return null
 const result=spawnSync(process.env.AIMS_PERF_MYSQL_CLI,['--host=127.0.0.1','--port='+fixture.environment.DB_PORT,'--user='+fixture.environment.DB_USERNAME,'--batch','--skip-column-names','-e',"SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_deadlocks','Innodb_row_lock_waits','Innodb_row_lock_time','Threads_connected','Threads_running','Max_used_connections')"],{windowsHide:true,encoding:'utf8'})
 return result.status===0?Object.fromEntries(result.stdout.trim().split(/\r?\n/).map(line=>line.split('\t'))):{unavailable:true}
}
async function request(label,actor,method,route,body,index=0,accepted=[200]){
 const start=performance.now();let status=0,bytes=0,result
 try{
  const response=await fetch('http://127.0.0.1:'+(8100+index%workers)+'/api'+route,{method,headers:{Authorization:'Bearer '+actor.token,Accept:'application/json','Content-Type':'application/json'},body:body?JSON.stringify(body):undefined,signal:AbortSignal.timeout(60000)})
  status=response.status;const text=await response.text();bytes=Buffer.byteLength(text)
  try{result=JSON.parse(text)}catch{result={}}
  if(!accepted.includes(status))report.errors.push({label,status,message:String(result.message||'Unexpected response').slice(0,300)})
 }catch(error){report.errors.push({label,status,message:error.message})}
 samples.push({label,actor:actor.id||0,status,ms:Math.round((performance.now()-start)*100)/100,bytes})
 if(!accepted.includes(status))throw Error(label+' failed '+status)
 return result
}
const actor={id:fixture.sessions[0].id,token:fixture.owner_token}
const uuid=()=>crypto.randomUUID()
async function race(label,method,route,body,accepted=[200]){
 const results=await Promise.allSettled(fixture.writers.map((u,i)=>request(label,u,method,route,typeof body==='function'?body(i):body,i,accepted)))
 const failed=results.filter(r=>r.status==='rejected')
 if(failed.length)throw Error(label+' had '+failed.length+' failed requests')
 return results.map(r=>r.value)
}
async function warehouseRaces(s){
 const product=()=>request('Warehouse balance',actor,'GET','/products/'+s.product_id).then(r=>r.product)
 async function count(){
  const c=await request('Create count',actor,'POST','/inventory-counts',{warehouse_id:s.warehouse_id,location_id:s.location_id,stock_states:['available'],product_ids:[s.product_id]},0,[201])
  const item=c.items.find(i=>i.product_id===s.product_id)
  await request('Record count',actor,'POST','/inventory-counts/'+c.id+'/record',{items:[{count_item_id:item.id,counted_quantity:Number(item.expected_quantity)-2}]})
  await request('Submit count',actor,'POST','/inventory-counts/'+c.id+'/submit',{})
  return c
 }
 let before=Number((await product()).quantity),c=await count()
 await Promise.all([
  request('Count approval during movement',fixture.writers[1],'POST','/inventory-counts/'+c.id+'/approve',{reason:'PR2 concurrent count verification'},1,[200,422]),
  request('Movement during count approval',actor,'POST','/stock-movements',{product_id:s.product_id,warehouse_id:s.warehouse_id,location_id:s.location_id,type:'out',stock_state:'available',quantity:1,reason:'PR2 count race',idempotency_key:uuid()},2,[201])
 ])
 let approved=samples.findLast(r=>r.label==='Count approval during movement').status===200
 report.checks.count_movement_exact=Number((await product()).quantity)===before-1-(approved?2:0)
 before=Number((await product()).quantity);c=await count()
 await Promise.all([
  request('Count approval during receipt',fixture.writers[1],'POST','/inventory-counts/'+c.id+'/approve',{reason:'PR2 concurrent receipt verification'},1,[200,422]),
  request('Receipt during count approval',actor,'POST','/purchase-orders/'+s.po_id+'/receive',{warehouse_id:s.warehouse_id,location_id:s.location_id,idempotency_key:uuid(),items:[{id:s.po_item_id,quantity:1}]},2,[200])
 ])
 approved=samples.findLast(r=>r.label==='Count approval during receipt').status===200
 report.checks.count_receipt_exact=Number((await product()).quantity)===before+1-(approved?2:0)
 const destination=await request('Transfer destination',actor,'POST','/warehouses',{name:'PR2 destination '+uuid(),code:'D-'+crypto.randomBytes(4).toString('hex')},0,[201])
 let parent=null
 for(const type of ['zone','rack','shelf','bin']){const loc=await request('Transfer bin',actor,'POST','/warehouse-locations',{warehouse_id:destination.id,parent_id:parent,type,code:type[0].toUpperCase(),name:'PR2 '+type,is_active:true},0,[201]);parent=loc.id}
 const transfer=await request('Transfer draft',actor,'POST','/stock-transfers',{source_warehouse_id:s.warehouse_id,destination_warehouse_id:destination.id,source_location_id:s.location_id,destination_location_id:parent,items:[{product_id:s.product_id,quantity:5}]},0,[201])
 before=Number((await product()).quantity)
 await Promise.all([
  request('Transfer during sale',actor,'POST','/stock-transfers/'+transfer.id+'/dispatch',{idempotency_key:uuid()},1),
  request('Sale during transfer',fixture.writers[1],'POST','/daily-sales',{sale_date:s.date,customer_name:'PR2 concurrent walk-in',idempotency_key:uuid(),items:[{product_id:s.product_id,product_name:'PR2 integrity product',unit:'pcs',quantity:1,unit_price:5,warehouse_id:s.warehouse_id,location_id:s.location_id}]},2,[201])
 ])
 await request('Receive transfer',actor,'POST','/stock-transfers/'+transfer.id+'/receive',{idempotency_key:uuid(),location_id:parent,items:[{id:transfer.items[0].id,accepted_quantity:5,damaged_quantity:0}]})
 const final=await product();report.checks.transfer_sale_company_quantity_exact=Number(final.quantity)===before-1
 report.warehouse={count_movement_approval_status:samples.findLast(r=>r.label==='Count approval during movement').status,count_receipt_approval_status:samples.findLast(r=>r.label==='Count approval during receipt').status,transfer_id:transfer.id,final_company_quantity:Number(final.quantity),qualification:'The 72-unit base integrity check runs before these additional count/receipt/transfer/sale races. A stale count must be rejected or post before the concurrent movement.'}
}

async function main(){
 for(let i=0;i<workers;i++){
  const child=spawn(php,['-S','127.0.0.1:'+(8100+i),'-t',path.join(root,'backend/public'),path.join(root,'backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'backend/public'),env,windowsHide:true,stdio:'ignore'})
  children.push(child)
 }
 for(let attempt=0;attempt<120;attempt++){
  try{const results=await Promise.all(children.map((_,i)=>fetch('http://127.0.0.1:'+(8100+i)+'/up')));if(results.every(r=>r.ok))break}catch{}
  if(attempt===119)throw Error('HTTP worker pool unavailable')
  await new Promise(r=>setTimeout(r,250))
 }
 report.db_before=databaseStatus();await observe();monitor=setInterval(()=>observe().catch(()=>{}),5000)
 const routes=[['Dashboard','/dashboard'],['Products','/products?per_page=50'],['Stock movements','/stock-movements?page=1&per_page=50'],['Global search','/search?q=Performance'],['Product detail','/products/'+fixture.product_detail_id],['Orders','/order-hub']]
 await Promise.all(fixture.sessions.map(async(u,i)=>{
  for(let round=0;round<2;round++)for(const [label,route] of routes)await request(label,u,'GET',route,null,i)
 }))
 await race('Finance summary','GET','/financial-intelligence?view=overview',null)
 await race('Customer summary','GET','/customer-sales-intelligence?view=overview',null)
 const s=fixture.state
 const stock={product_id:s.product_id,warehouse_id:s.warehouse_id,location_id:s.location_id,type:'out',stock_state:'available',quantity:1,reason:'PR2 concurrent idempotency',idempotency_key:s.stock_key}
 const same=await race('Stock same key','POST','/stock-movements',stock,[201])
 report.checks.same_stock_response_id=new Set(same.map(r=>r.id)).size===1
 await race('Stock independent keys','POST','/stock-movements',i=>({...stock,idempotency_key:s.unique_stock_keys[i]}),[201])
 const created=await race('Order same key','POST','/sales-orders',{customer_id:s.customer_id,warehouse_id:s.warehouse_id,order_date:s.date,payment_type:'credit',idempotency_key:s.order_key,items:[{product_id:s.product_id,quantity:4,unit:'pcs',unit_price:5}]},[201])
 const orderId=created[0].id;report.checks.same_order_response_id=new Set(created.map(r=>r.id)).size===1;s.order_id=orderId
 const orderRoute='/sales-orders/'+orderId
 const act=async(action,body={})=>request('Prepare '+action,actor,'POST',orderRoute+'/'+action,{idempotency_key:uuid(),...body})
 await act('confirm')
 await race('Reserve same order','POST',orderRoute+'/reserve',{idempotency_key:uuid()})
 const allocated=await act('allocate'),allocation=allocated.allocations[0]
 const picks=await Promise.all(fixture.writers.slice(0,2).map((u,i)=>request('Two pickers same allocation',u,'POST',orderRoute+'/pick',{idempotency_key:uuid(),allocation_id:allocation.id,location_id:allocation.location_id,inventory_lot_id:allocation.inventory_lot_id,quantity:4,manual_verification:true},i,[200,422])))
 report.checks.two_pickers_one_effect=picks.every(r=>r.allocations?.filter(a=>a.id===allocation.id).every(a=>Number(a.picked_quantity)===4))
 const packed=await act('pack',{items:[{allocation_id:allocation.id,quantity:4}]})
 s.dispatch_key=uuid()
 await Promise.all([
  race('Dispatch same package','POST',orderRoute+'/dispatch',{idempotency_key:s.dispatch_key,package_ids:[packed.packages[0].id]}),
  race('Payment during dispatch','POST','/customers/'+s.customer_id+'/payments',{amount:3,transaction_date:s.date,payment_method:'cash',idempotency_key:s.payment_key},[201])
 ])
 const po=await request('PO edit baseline',actor,'GET','/purchase-orders/'+s.po_id)
 const poPayload={supplier_id:po.supplier_id,warehouse_id:s.warehouse_id,ordered_at:s.date,currency:'EUR',status:'ordered',revision:po.revision,items:po.items.map(item=>({id:item.id,product_id:item.product_id,description:item.description,quantity:item.quantity,unit:item.unit,unit_price:item.unit_price}))}
 await Promise.all(fixture.writers.slice(0,2).map((u,i)=>request('PO revision conflict',u,'PUT','/purchase-orders/'+s.po_id,{...poPayload,notes:'Concurrent PR2 edit '+i},i,[200,409])))
 report.checks.po_one_edit=samples.filter(r=>r.label==='PO revision conflict'&&r.status===200).length===1
 report.checks.po_one_conflict=samples.filter(r=>r.label==='PO revision conflict'&&r.status===409).length===1
 await Promise.all([
  race('Receive same receipt','POST','/purchase-orders/'+s.po_id+'/receive',{warehouse_id:s.warehouse_id,location_id:s.location_id,idempotency_key:s.receipt_key,items:[{id:s.po_item_id,quantity:2}]}),
  race('PO payment during receipt','POST','/purchase-orders/'+s.po_id+'/payments',{amount:4,payment_date:s.date,payment_method:'cash',idempotency_key:s.po_payment_key},[201])
 ])
 const workspace=await request('Workspace baseline',actor,'GET','/settings/workspace')
 const updated=await Promise.all(fixture.writers.map((u,i)=>request('Workspace conflict',actor,'PUT','/settings/workspace',{revision:workspace.revision,dashboard:{version:1,widgets:[{id:'product-count',position:0,size:'small',settings:{}}]}},i,[200,409])))
 report.checks.workspace_one_success=samples.filter(r=>r.label==='Workspace conflict'&&r.status===200).length===1
 report.checks.workspace_24_conflicts=samples.filter(r=>r.label==='Workspace conflict'&&r.status===409).length===24
 await request('Tenant boundary',{token:fixture.foreign_token},'GET','/products/'+s.product_id,null,0,[404])
 fs.writeFileSync(fixture.state_path,JSON.stringify(s))
 const verified=spawnSync(php,[path.join(__dirname,'verify.php'),target,fixture.state_path],{cwd:root,env:process.env,windowsHide:true,encoding:'utf8',maxBuffer:1024*1024})
 if(verified.status!==0)throw Error('Integrity verification failed: '+verified.stdout.slice(-3000)+verified.stderr.slice(-1000))
 report.integrity=JSON.parse(verified.stdout)
 await warehouseRaces(s)
 await observe();report.db_after=databaseStatus()
 report.status=report.errors.length||Object.values(report.checks).some(v=>v!==true)||report.integrity.status!=='PASS'?'FAIL':'PASS'
}
main().catch(error=>{report.failure=error.message;process.exitCode=1}).finally(async()=>{
 clearInterval(monitor);for(const c of children){if(process.platform==='win32')spawnSync('taskkill.exe',['/pid',String(c.pid),'/t','/f'],{windowsHide:true,stdio:'ignore'});else c.kill('SIGTERM')}
 report.db_after=report.db_after||databaseStatus()
 report.summary={}
 for(const label of new Set(samples.map(r=>r.label))){const rows=samples.filter(r=>r.label===label),times=rows.map(r=>r.ms).sort((a,b)=>a-b),quantile=p=>times[Math.min(times.length-1,Math.floor(times.length*p))];report.summary[label]={requests:rows.length,p50_ms:quantile(.5),p95_ms:quantile(.95),max_ms:times.at(-1),response_bytes_max:Math.max(...rows.map(r=>r.bytes)),statuses:rows.reduce((counts,r)=>({...counts,[r.status]:(counts[r.status]||0)+1}),{})}}
 const output=path.join(root,'output/pr2-concurrency-'+fixture.state.engine+'.json');fs.writeFileSync(output,JSON.stringify(report,null,2))
 console.log(JSON.stringify({status:report.status,failure:report.failure,errors:report.errors,checks:report.checks,integrity:report.integrity,db_before:report.db_before,db_after:report.db_after,summary:report.summary}))
})
