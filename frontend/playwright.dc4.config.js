import base from './playwright.config.js';
import path from 'node:path';
export default {
  ...base,
  globalSetup: './e2e/dc4-setup.mjs',
  testMatch: 'dc4-payroll.spec.js',
  reporter: [['list'], ['html', {
    outputFolder: '../output/dc4-browser-report',
    open: 'never'
  }]],
  outputDir: '../output/dc4-browser-results',
  use: {
    ...base.use,
    baseURL: 'http://127.0.0.1:4174'
  },
  webServer: [{
    ...base.webServer[0],
    command: 'php artisan serve --no-reload --host=127.0.0.1 --port=8011',
    url: 'http://127.0.0.1:8011/up',
    env: {
      ...base.webServer[0].env,
      DB_DATABASE: path.resolve('../backend/database/dc4-preview.sqlite')
    }
  }, {
    ...base.webServer[1],
    command: 'node ../scripts/serve-dc4-e2e.cjs',
    url: 'http://127.0.0.1:4174/login',
    env: {
      VITE_API_PROXY_TARGET: 'http://127.0.0.1:8011'
    }
  }]
};
