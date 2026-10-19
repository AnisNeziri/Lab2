import base from './playwright.config.js'
export default {...base,globalSetup:'./e2e/dc3-setup.mjs',testMatch:'dc3-tax-fiscalization.spec.js',reporter:[['list'],['html',{outputFolder:'../output/dc3-browser-report',open:'never'}]],outputDir:'../output/dc3-browser-results'}
