import { defineConfig, devices } from '@playwright/test'

// Uses an already-generated, running synthetic company. NO database reset or normal E2E seeder.
export default defineConfig({
  testDir:'./e2e/synthetic', outputDir:'./test-results/synthetic', workers:1, fullyParallel:false,
  reporter:[['list']], timeout:600000, expect:{timeout:30000},
  use:{baseURL:'http://127.0.0.1:5174',trace:'retain-on-failure',screenshot:'only-on-failure'},
  projects:[{name:'chromium',use:{...devices['Desktop Chrome']}}],
})
