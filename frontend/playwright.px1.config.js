import base from './playwright.config.js'
import {fileURLToPath} from 'node:url'
export default {
  ...base,
  globalSetup:'./e2e/px1-setup.mjs',testMatch:'px1-experience.spec.js',
  reporter:[['list'],['html',{outputFolder:fileURLToPath(new URL('../output/px1-browser-report',import.meta.url)),open:'never'}]],
  outputDir:fileURLToPath(new URL('../output/px1-browser-results',import.meta.url)),
  use:{...base.use,baseURL:'http://127.0.0.1:4175'},
  webServer:[
    {...base.webServer[0],command:'php artisan serve --no-reload --host=127.0.0.1 --port=8012',url:'http://127.0.0.1:8012/up',reuseExistingServer:!process.env.CI,env:{...base.webServer[0].env,DB_DATABASE:fileURLToPath(new URL('../backend/database/px1-preview.sqlite',import.meta.url))}},
    {...base.webServer[1],command:'node ../scripts/serve-px1-e2e.cjs',url:'http://127.0.0.1:4175/login',reuseExistingServer:!process.env.CI,env:{VITE_API_PROXY_TARGET:'http://127.0.0.1:8012'}}
  ]
}
