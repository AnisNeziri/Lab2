import base from './playwright.px2.config.js'
import {fileURLToPath} from 'node:url'
export default {...base,globalSetup:'./e2e/dc9-setup.mjs',testMatch:'dc9-workflow.spec.js',reporter:[['list'],['html',{outputFolder:fileURLToPath(new URL('../output/dc9-browser-report',import.meta.url)),open:'never'}]],outputDir:fileURLToPath(new URL('../output/dc9-browser-results',import.meta.url))}
