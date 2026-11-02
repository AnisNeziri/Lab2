import base from './playwright.px2.config.js'
import {fileURLToPath} from 'node:url'
export default {...base,testMatch:'review-feedback.spec.js',reporter:[['list'],['html',{outputFolder:fileURLToPath(new URL('../output/review-browser-report',import.meta.url)),open:'never'}]],outputDir:fileURLToPath(new URL('../output/review-browser-results',import.meta.url))}
