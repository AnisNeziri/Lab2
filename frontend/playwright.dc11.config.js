import {defineConfig} from '@playwright/test'
import {fileURLToPath} from 'node:url'
export default defineConfig({testDir:'./e2e/specs',testMatch:'dc11-commissioning.spec.js',workers:1,retries:0,timeout:90000,use:{browserName:'chromium',baseURL:'http://127.0.0.1:4184',viewport:{width:1366,height:850},trace:'retain-on-failure',screenshot:'only-on-failure'},reporter:[['list']],outputDir:fileURLToPath(new URL('../output/dc11-browser-results',import.meta.url))})
