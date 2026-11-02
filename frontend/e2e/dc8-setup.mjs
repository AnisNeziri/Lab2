import {spawnSync} from 'node:child_process'
import {existsSync,writeFileSync,mkdirSync} from 'node:fs'
import {fileURLToPath} from 'node:url'
import path from 'node:path'
export default function setup(){
 const backend=fileURLToPath(new URL('../../backend/',import.meta.url)),db=path.join(backend,'database/dc8-preview.sqlite')
 if(process.env.DB_URL||process.env.APP_ENV==='production')throw new Error('Production overrides rejected')
 if(!existsSync(db))writeFileSync(db,'')
 const env={...process.env,APP_ENV:'e2e',APP_KEY:'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=',APP_DEBUG:'false',DB_CONNECTION:'sqlite',DB_DATABASE:db,DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'array',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',BROADCAST_CONNECTION:'log',REDIS_ENABLED:'false',AIMS_PASSKEY_RP_ID:'localhost',AIMS_PASSKEY_ORIGINS:'http://localhost:4181'}
 for(const args of [['artisan','migrate','--force'],['tests/Support/dc8-browser-fixture.php']]){
  const r=spawnSync('php',args,{cwd:backend,env,encoding:'utf8',windowsHide:true});if(r.status!==0)throw new Error(r.stdout+'\n'+r.stderr)
  if(args[0].includes('fixture')){mkdirSync(fileURLToPath(new URL('../../output/',import.meta.url)),{recursive:true});writeFileSync(fileURLToPath(new URL('../../output/dc8-fixture.json',import.meta.url)),r.stdout)}
 }
}
