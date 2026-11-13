import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { expect, test } from '@playwright/test'

// Serve the isolated DC16 API on 8026 and proxy the production preview on 4186.
// The account file lives outside the repository and contains only synthetic users.
const accountPath = process.env.AIMS_DC33_TEST_ACCOUNTS
test.skip(!accountPath, 'Supply the isolated DC16 test-account file')

async function login(page, role) {
  const fixture = JSON.parse(readFileSync(join(dirname(accountPath), 'credentials.json'), 'utf8'))
  if (fixture.marker !== 'AIMS_DC16_ISOLATED_20261102' || fixture.database !== 'aims_dc16_20261102') {
    throw new Error('This suite requires the isolated DC16 fixture')
  }
  const account = JSON.parse(readFileSync(accountPath, 'utf8'))[role]
  await page.goto('/login')
  await page.locator('input[type=email]').fill(account.email)
  await page.locator('input[type=password]').fill(account.password ?? fixture.owner_password)
  await page.locator('button[type=submit]').click()
  await expect(page).toHaveURL(/\/dashboard(?:\?|$)/)
}

async function ask(page, question) {
  await page.goto('/intelligence-assistant')
  const response = page.waitForResponse(res => res.url().endsWith('/intelligence-assistant/ask') && res.request().method() === 'POST')
  const composer = page.locator('.assistant-composer textarea')
  await composer.fill(question)
  await composer.press('Enter')
  const result = await response
  expect(result.ok()).toBe(true)
  const reply = await result.json()
  expect(reply.read_only).toBe(true)
  return reply
}

test('CEO can review priorities and open the top debtor', async ({ page }) => {
  await login(page, 'primary')
  const brief = await ask(page, 'What needs my attention today?')
  expect(brief.tools.some(run => run.tool === 'get_operating_brief')).toBe(true)
  const debtor = await ask(page, 'Who owes us the most?')
  expect(debtor.next_action.url).toMatch(/^\/accounting\?tab=ar&customer=\d+$/)
  await page.locator('.assistant-next-action').last().click()
  await expect(page).toHaveURL(/customer=\d+/)
})

test('Purchasing opens a product-prefilled request preview', async ({ page }) => {
  await login(page, 'purchasing')
  await ask(page, 'Prepare a purchase request for Milano 04.')
  await page.locator('.assistant-next-action').last().click()
  await expect(page.locator('#purchase-request-form select option:checked')).toHaveText('Milano 04')
  await expect(page.locator('#purchase-request-form input[type=number]').first()).toHaveValue('')
})

test('Sales gets the authorized follow-up fallback', async ({ page }) => {
  await login(page, 'sales')
  const reply = await ask(page, 'Cilëve klientë duhet t’u bëjmë follow-up sot?')
  expect(reply.state).toBe('permission_denied')
  expect(reply.tools).toEqual([])
  await page.locator('.assistant-next-action').last().click()
  await expect(page).toHaveURL(/\/customers$/)
})

test('Accountant reaches period selection', async ({ page }) => {
  await login(page, 'accountant')
  const reply = await ask(page, 'What is blocking month close for the books?')
  expect(reply.next_action.url).toMatch(/^\/accounting\?tab=periods/)
  await page.locator('.assistant-next-action').last().click()
  await expect(page).toHaveURL(/tab=periods/)
})

test('Warehouse can read stock and cannot read payroll', async ({ page }) => {
  await login(page, 'warehouse')
  const stock = await ask(page, 'How much Milano 04 do we have available?')
  expect(stock.text).toContain('Milano 04')
  expect((await ask(page, 'Show payroll salaries and employee bank accounts.')).state).toBe('permission_denied')
})
