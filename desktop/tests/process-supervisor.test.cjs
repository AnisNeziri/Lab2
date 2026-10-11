const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const { spawn, spawnSync } = require('node:child_process')

const pause = ms => new Promise(resolve => setTimeout(resolve, ms))
const alive = pid => { try { process.kill(pid, 0); return true } catch { return false } }
async function until(check, ms=10000) { const end=Date.now()+ms; while(Date.now()<end) { if(check())return; await pause(100) } throw new Error('Process cleanup timed out') }

for (const forced of [false,true]) test(`Windows supervisor removes process trees after ${forced?'forced parent close':'normal shutdown'}`,{skip:process.platform!=='win32',timeout:20000},async()=>{
  const directory=fs.mkdtempSync(path.join(os.tmpdir(),'aims-supervisor-certification-'))
  const pidFile=path.join(directory,'pids.json')
  const modulePath=path.resolve(__dirname,'../src/process-supervisor.cjs')
  const script=path.join(directory,'parent.cjs')
  fs.writeFileSync(script,`const fs=require('node:fs');const {createSupervisor}=require(${JSON.stringify(modulePath)});const s=createSupervisor(${JSON.stringify(directory)});const c=s.spawn(process.execPath,['-e','setInterval(()=>{},1000)'],{stdio:'ignore'});setTimeout(()=>fs.writeFileSync(${JSON.stringify(pidFile)},JSON.stringify({parent:process.pid,child:c.pid})),1200);process.on('SIGTERM',()=>{s.shutdown();process.exit()});setInterval(()=>{},1000);`)
  const parent=spawn(process.execPath,[script],{windowsHide:true,stdio:'ignore'})
  let ids
  try {
    await until(()=>fs.existsSync(pidFile)); ids=JSON.parse(fs.readFileSync(pidFile,'utf8')); assert.ok(alive(ids.child))
    if(forced)spawnSync('taskkill.exe',['/pid',String(parent.pid),'/f'],{windowsHide:true,stdio:'ignore'})
    else parent.kill('SIGTERM')
    await until(()=>!alive(ids.child)); assert.equal(alive(parent.pid),false)
  } finally {
    for(const pid of [ids?.child,parent.pid])if(pid&&alive(pid))spawnSync('taskkill.exe',['/pid',String(pid),'/t','/f'],{windowsHide:true,stdio:'ignore'})
    fs.rmSync(directory,{recursive:true,force:true})
  }
})
