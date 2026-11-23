import { expect, test } from '@playwright/test'
import { login } from '../helpers/auth.mjs'

test('@smoke procurement preview displays Milano 04 before the catalogue and saves only after submission', async ({ page }) => {
  test.setTimeout(90_000) // Two sign-ins, fixture setup, the assistant and a persisted draft.
  await login(page)
  const authHeaders = async () => ({ Authorization: `Bearer ${await page.evaluate(() => localStorage.getItem('api_token'))}` })
  const headers = await authHeaders()
  const categories = await page.request.get('/api/categories', { headers })
  expect(categories.ok()).toBe(true)
  const categoryList = await categories.json()
  const category = (categoryList.data ?? categoryList)[0]
  const existing = await page.request.get('/api/products?search=Milano%2004', { headers })
  expect(existing.ok()).toBe(true)
  if (!(await existing.json()).data.some(product => product.name === 'Milano 04')) {
    const product = await page.request.post('/api/products', { headers, data: {
      name: 'Milano 04', sku: 'MILANO-E2E-PREVIEW', category_id: category.id,
      unit: 'm', quantity: 0, min_quantity: 0, price: 5, selling_price: 5, purchase_price: 3,
    } })
    expect(product.ok(), await product.text()).toBe(true)
  }

  await page.evaluate(() => localStorage.clear())
  await login(page, 'purchasing')
  const purchasingHeaders = await authHeaders()
  const requestCount = async () => {
    const response = await page.request.get('/api/purchase-requests', { headers: purchasingHeaders })
    expect(response.ok()).toBe(true)
    return (await response.json()).total
  }
  const before = await requestCount()
  expect(Number.isInteger(before)).toBe(true)
  await page.goto('/intelligence-assistant')
  const question = page.locator('.assistant-composer textarea')
  await question.fill('Prepare a purchase request for Milano 04.')
  await question.press('Enter')
  const preview = page.getByRole('link', { name: 'Open purchase request preview' }).first()
  await expect(preview).toBeVisible()

  let releaseCatalog
  const catalogReleased = new Promise(resolve => { releaseCatalog = resolve })
  let catalogRequested = false
  await page.route(/\/api\/products\?[^#]*reference_only=1/, async route => {
    catalogRequested = true
    await catalogReleased
    await route.continue()
  })
  const form = page.locator('#purchase-request-form')
  try {
    await preview.click()
    const product = form.getByRole('combobox')
    await expect(product).toBeVisible()
    await expect(product.locator('option:checked')).toHaveText('Milano 04')
    await expect(form.getByRole('spinbutton', { name: 'Quantity · m' })).toHaveValue('')
    await expect.poll(() => catalogRequested).toBe(true)
    expect(await requestCount()).toBe(before)
  } finally {
    releaseCatalog()
  }
  await form.getByRole('spinbutton', { name: 'Quantity · m' }).fill('12.5')
  const savedResponse = page.waitForResponse(response => response.url().endsWith('/api/purchase-requests') && response.request().method() === 'POST')
  await form.getByRole('button', { name: 'Save purchase request draft' }).click()
  const saved = await savedResponse
  expect(saved.ok(), await saved.text()).toBe(true)
  const record = await saved.json()
  expect(record.status).toBe('draft')
  expect(Number(record.items[0].quantity)).toBe(12.5)
  expect(await requestCount()).toBe(before + 1)

  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/purchasing-decisions')
  const productField = page.getByRole('combobox', { name: 'Product', exact: true })
  const milano = productField.getByRole('option', { name: /^Milano 04 ·/ })
  await productField.selectOption(await milano.getAttribute('value'))
  const quantity = page.getByRole('spinbutton', { name: 'Quantity', exact: true })
  const check = page.getByRole('button', { name: 'Check stock and quotes' })
  const nextAction = page.getByRole('link', { name: 'Prepare quote request', exact: true })
  await quantity.fill('500')
  await check.click()
  await expect(page.getByText('No current quote for this quantity', { exact: true })).toBeVisible()
  await expect(nextAction).toBeVisible()
  expect(await page.locator('main.purchasing-decisions').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true)
  await quantity.fill('320')
  await expect(nextAction).toHaveCount(0)
  await check.click()
  await nextAction.click()
  await expect(form).toBeInViewport()
  await expect(form.locator('option:checked')).toHaveText('Milano 04')
  await expect(form.getByRole('spinbutton', { name: 'Quantity · m' })).toHaveValue('320')
  expect(await requestCount()).toBe(before + 1)
})
