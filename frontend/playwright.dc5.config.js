import base from './playwright.config.js'
import {fileURLToPath} from 'node:url'
export default {
  ...base,
  globalSetup:'./e2e/dc5-setup.mjs',testMatch:'dc5-inventory.spec.js',
  reporter:[['list'],['html',{outputFolder:fileURLToPath(new URL('../output/dc5-browser-report',import.meta.url)),open:'never'}]],
  outputDir:fileURLToPath(new URL('../output/dc5-browser-results',import.meta.url)),
  use:{...base.use,baseURL:'http://127.0.0.1:4178'},
  webServer:[
    {...base.webServer[0],command:'php artisan serve --no-reload --host=127.0.0.1 --port=8015',url:'http://127.0.0.1:8015/up',reuseExistingServer:!process.env.CI,env:{...base.webServer[0].env,DB_DATABASE:fileURLToPath(new URL('../backend/database/dc5-preview.sqlite',import.meta.url))}},
    {...base.webServer[1],command:'node ../scripts/serve-dc5-e2e.cjs',url:'http://127.0.0.1:4178/login',reuseExistingServer:!process.env.CI,env:{VITE_API_PROXY_TARGET:'http://127.0.0.1:8015'}}
  ]
}
