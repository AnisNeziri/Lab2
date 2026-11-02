import base from './playwright.config.js'
import {fileURLToPath} from 'node:url'
export default {
  ...base,
  globalSetup:'./e2e/dc6-setup.mjs',testMatch:'dc6-automation.spec.js',
  reporter:[['list'],['html',{outputFolder:fileURLToPath(new URL('../output/dc6-browser-report',import.meta.url)),open:'never'}]],
  outputDir:fileURLToPath(new URL('../output/dc6-browser-results',import.meta.url)),
  use:{...base.use,baseURL:'http://127.0.0.1:4179'},
  webServer:[
    {...base.webServer[0],command:'php artisan serve --no-reload --host=127.0.0.1 --port=8016',url:'http://127.0.0.1:8016/up',reuseExistingServer:!process.env.CI,env:{...base.webServer[0].env,DB_DATABASE:fileURLToPath(new URL('../backend/database/dc6-preview.sqlite',import.meta.url))}},
    {...base.webServer[1],command:'node ../scripts/serve-dc6-e2e.cjs',url:'http://127.0.0.1:4179/login',reuseExistingServer:!process.env.CI,env:{VITE_API_PROXY_TARGET:'http://127.0.0.1:8016'}}
  ]
}
