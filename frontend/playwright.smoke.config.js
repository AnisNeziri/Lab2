import base from './playwright.config.js'

export default {
  ...base,
  testMatch: ['**/auth.spec.js', '**/company-pilot.spec.js', '**/products.spec.js', '**/responsive.spec.js', '**/procurement-preview.spec.js', '**/workday-access.spec.js'],
  use: { ...base.use, trace: 'retain-on-failure-and-retries' },
}
