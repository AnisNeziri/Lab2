import { defineConfig, devices } from '@playwright/test'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const frontendDir = path.dirname(fileURLToPath(import.meta.url))
const backendDir = path.resolve(frontendDir, '../backend')
const databasePath = path.join(backendDir, 'database', 'e2e.sqlite')
const appKey = 'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0='
const backendEnvironment = {
  APP_ENV: 'e2e',
  APP_KEY: appKey,
  APP_DEBUG: 'false',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: databasePath,
  DB_URL: '',
  APP_OPERATION_MODE: 'online',
  REDIS_ENABLED: 'false',
  APP_URL: 'http://127.0.0.1:8010',
  FRONTEND_URL: 'http://127.0.0.1:4173',
  TRACKING_EXTERNAL_ENABLED: 'false',
  ASSISTANT_PROVIDER: 'deterministic',
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  MAIL_MAILER: 'array',
  BROADCAST_CONNECTION: 'log',
}

export default defineConfig({
  testDir: './e2e/specs',
  // Archived phase witnesses have dedicated configs and require separately provisioned fixtures.
  testMatch: /(?:^|[/\\])(?!(?:dc(?:[3-9]|1[0-5])|px[12])-|ui-review\.spec\.)[^/\\]+\.spec\.js$/,
  outputDir: './test-results',
  globalSetup: './e2e/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI
    ? [['line'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
    : [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:4173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
  },
  expect: { timeout: 10_000 },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: [
    {
      command: 'php artisan serve --no-reload --host=127.0.0.1 --port=8010',
      cwd: backendDir,
      url: 'http://127.0.0.1:8010/up',
      env: backendEnvironment,
      reuseExistingServer: false,
      timeout: 120_000,
    },
    {
      command: 'node ../scripts/serve-e2e.cjs',
      cwd: frontendDir,
      url: 'http://127.0.0.1:4173/api/status',
      env: { VITE_API_PROXY_TARGET: 'http://127.0.0.1:8010', VITE_ASSISTANT_PROXY_TARGET: '' },
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
})
