import base from './playwright.config.js'
import {fileURLToPath} from 'node:url'
export default {
 ...base,globalSetup:'./e2e/dc8-setup.mjs',testMatch:'dc8-security.spec.js',
 reporter:[['list'],['html',{outputFolder:fileURLToPath(new URL('../output/dc8-browser-report',import.meta.url)),open:'never'}]],
 outputDir:fileURLToPath(new URL('../output/dc8-browser-results',import.meta.url)),use:{...base.use,baseURL:'http://localhost:4181'},
 webServer:[
  {...base.webServer[0],command:'php artisan serve --no-reload --host=127.0.0.1 --port=8018',url:'http://127.0.0.1:8018/up',reuseExistingServer:false,env:{...base.webServer[0].env,DB_DATABASE:fileURLToPath(new URL('../backend/database/dc8-preview.sqlite',import.meta.url)),AIMS_PASSKEY_RP_ID:'localhost',AIMS_PASSKEY_ORIGINS:'http://localhost:4181'}},
  {...base.webServer[1],command:'node ../scripts/serve-dc8-e2e.cjs',url:'http://localhost:4181/login',reuseExistingServer:false,env:{VITE_API_PROXY_TARGET:'http://127.0.0.1:8018',VITE_REVERB_HOST:'localhost',VITE_REVERB_PORT:'4181',VITE_REVERB_SCHEME:'http'}}
 ]
}
