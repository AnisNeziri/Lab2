import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e/synthetic',
  testMatch: 'dc33-release.spec.js',
  workers: 1,
  reporter: 'list',
  use: { baseURL: 'http://127.0.0.1:4186', ...devices['Desktop Chrome'] },
})
