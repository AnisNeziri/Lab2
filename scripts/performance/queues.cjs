const fs=require('node:fs'),path=require('node:path'),{spawn,spawnSync,execFileSync}=require('node:child_process')
const root=path.resolve(__dirname,'../..'),php=process.env.AIMS_PERFORMANCE_PHP||'php',target=process.argv[2],children=[],jobChildren=[],samples=[]
function bridge(mode){const r=spawnSync(php,[path.join(__dirname,'queues.php'),target,mode],{cwd:root,env:process.env,windowsHide:true,encoding:'utf8',maxBuffer:1024*1024});if(r.status||!r.stdout.startsWith('{'))throw Error('Guarded queue bridge failed '+r.stderr.slice(0,200));return JSON.parse(r.stdout)}
const report={status:'FAIL',method:'Two normal database queue workers, one optimizer worker, one simulation worker. Five synthetic 90-day product histories. No operational preparation and no accuracy claim.',recorded_at:new Date().toISOString(),samples,workers:[]}
let timer
async function main(){
 const prepared=bridge('setup');const env={...process.env,...prepared.environment,AIMS_ML_PYTHON:process.env.AIMS_ML_PYTHON,DB_QUEUE_RETRY_AFTER:'300'}
 report.before=bridge('status');report.initial_queue_depth=prepared.queue_depth
 const started=Date.now()
 const server=spawn(php,['-S','127.0.0.1:8120','-t',path.join(root,'backend/public'),path.join(root,'backend/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],{cwd:path.join(root,'backend/public'),env,windowsHide:true,stdio:'ignore'});children.push(server)
 for(const queue of ['default','default','supply-optimizer','strategic-simulation']){
  const child=spawn(php,['artisan','queue:work','database','--queue='+queue,...(queue==='default'?['--max-time=20']:['--stop-when-empty','--max-time=240']),'--sleep=1','--tries=1','--timeout=240'],{cwd:path.join(root,'backend'),env,windowsHide:true,stdio:['ignore','pipe','pipe']})
  children.push(child);jobChildren.push(child);let output='';child.stdout.on('data',b=>{output=(output+b.toString()).slice(-5000)});child.stderr.on('data',b=>{output=(output+b.toString()).slice(-5000)})
  report.workers.push({queue,pid:child.pid})
  child.on('exit',code=>{Object.assign(report.workers.find(w=>w.pid===child.pid),{exit_code:code,elapsed_ms:Date.now()-started,output})})
 }
 function observe(){
  if(process.platform!=='win32')return
  const ids=children.map(c=>c.pid).concat(Number(process.env.AIMS_PERF_DB_PID)||[])
  const cmd="Get-CimInstance Win32_Process | Where-Object { $_.ProcessId -in @("+ids.join(',')+") -or ($_.ParentProcessId -in @("+children.map(c=>c.pid).join(',')+") -and $_.Name -eq 'python.exe') } | ForEach-Object { Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue } | Select-Object Id,ProcessName,CPU,WorkingSet64,PrivateMemorySize64,HandleCount | ConvertTo-Json -Compress"
  try{const raw=execFileSync('powershell.exe',['-NoProfile','-Command',cmd],{windowsHide:true,encoding:'utf8',timeout:10000});samples.push({elapsed_ms:Date.now()-started,processes:JSON.parse(raw)})}catch{}
 }
 observe();timer=setInterval(observe,500)
 report.web_during_jobs=[]
 for(let attempt=0;attempt<30;attempt++){try{if((await fetch('http://127.0.0.1:8120/up')).ok)break}catch{}await new Promise(r=>setTimeout(r,100))}
 for(let i=0;i<6;i++){const start=Date.now();const r=await fetch('http://127.0.0.1:8120/up');await r.text();report.web_during_jobs.push({status:r.status,ms:Date.now()-start});await new Promise(r=>setTimeout(r,100))}
 await Promise.all(jobChildren.map(c=>new Promise(resolve=>c.exitCode!==null?resolve():c.once('exit',resolve))))
 clearInterval(timer);report.after=bridge('status')
 report.checks={no_failed_jobs:report.after.failed_jobs===report.before.failed_jobs,normal_queue_drained:!report.after.queues.some(q=>q.queue==='default'),heavy_queues_drained:!report.after.queues.some(q=>['supply-optimizer','strategic-simulation'].includes(q.queue)),optimizer_completed:report.after.plans.some(p=>p.id===prepared.optimizer_id&&['OPTIMAL','FEASIBLE','INFEASIBLE'].includes(p.status)),simulation_completed:report.after.simulations.some(p=>p.id===prepared.simulation_id&&p.status==='COMPLETED'),cancelled_optimizer_preserved:report.after.plans.some(p=>p.id===prepared.cancelled_optimizer_id&&p.status==='CANCELLED'),cancelled_simulation_preserved:report.after.simulations.some(p=>p.id===prepared.cancelled_simulation_id&&p.status==='CANCELLED'),no_operational_effects:JSON.stringify(report.before.operational_counts)===JSON.stringify(report.after.operational_counts)}
 report.elapsed_ms=Date.now()-started;report.status=Object.values(report.checks).every(Boolean)&&report.workers.every(w=>w.exit_code===0)?'PASS':'FAIL'
}
main().catch(e=>{report.failure=e.message;process.exitCode=1}).finally(()=>{clearInterval(timer);for(const c of children)if(c.exitCode===null){if(process.platform==='win32')spawnSync('taskkill.exe',['/pid',String(c.pid),'/t','/f'],{windowsHide:true,stdio:'ignore'});else c.kill()}fs.writeFileSync(path.join(root,'output/pr2-queues.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,failure:report.failure,checks:report.checks,before:report.initial_queue_depth,workers:report.workers,after:report.after,elapsed_ms:report.elapsed_ms}))})
