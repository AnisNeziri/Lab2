import {spawnSync} from 'node:child_process'
import {existsSync,writeFileSync} from 'node:fs'
import path from 'node:path'
export default function setup(){
 const backend=path.resolve('../backend'),db=path.join(backend,'database/e2e.sqlite')
 if(process.env.DB_URL||process.env.APP_ENV==='production')throw new Error('Production overrides rejected')
 if(!existsSync(db))writeFileSync(db,'')
 const env={...process.env,APP_ENV:'e2e',APP_KEY:'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=',APP_DEBUG:'false',DB_CONNECTION:'sqlite',DB_DATABASE:db,DB_URL:'',CACHE_STORE:'array',QUEUE_CONNECTION:'sync',SESSION_DRIVER:'array',BROADCAST_CONNECTION:'log'}
 for(const args of [['artisan','migrate','--force'],['tests/Support/dc3-browser-fixture.php']]){
  const r=spawnSync('php',args,{cwd:backend,env,encoding:'utf8',windowsHide:true});if(r.status!==0)throw new Error(r.stdout+'\n'+r.stderr)
 }
}
