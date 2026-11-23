import { test, expect } from '@playwright/test'
import { login, logout, users } from '../helpers/auth.mjs'

test.describe('Authentication and route protection', () => {
  test('@smoke admin can log in and log out', async ({ page }) => {
    await login(page)
    await logout(page)
  })

  test('login reuses its profile and a restored session verifies it once', async ({ page }) => {
    let profileReads = 0
    page.on('request', request => {
      if (new URL(request.url()).pathname === '/api/me') profileReads += 1
    })
    await login(page)
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible()
    expect(profileReads).toBe(0)

    const verification = page.waitForResponse(response => new URL(response.url()).pathname === '/api/me')
    await page.reload()
    expect((await verification).ok()).toBe(true)
    await expect(page.getByRole('heading', { name: 'Dashboard', exact: true })).toBeVisible()
    expect(profileReads).toBe(1)
  })

  test('@smoke invalid credentials show a useful error', async ({ page }) => {
    await page.goto('/login')
    await expect(page.getByLabel('Email address')).toHaveAttribute('placeholder', 'Enter your email')
    await page.getByLabel('Email address').fill(users.admin.email)
    await page.getByLabel('Password', { exact: true }).fill('incorrect-password')
    await page.getByRole('button', { name: 'Enter workspace' }).click()
    await expect(page.getByRole('alert')).toContainText(/incorrect|invalid|credentials/i)
    await expect(page).toHaveURL(/\/login$/)
    await page.goto('/register')
    await expect(page.getByLabel('Company name', { exact: true })).toHaveAttribute('placeholder', 'Company Name')
  })

  test('@smoke protected URLs redirect anonymous users', async ({ page }) => {
    await page.goto('/products')
    await expect(page).toHaveURL(/\/login$/)
    await page.goto('/change-password')
    await expect(page).toHaveURL(/\/login$/)
  })

  test('@smoke authenticated password page survives direct loading and refresh', async ({ page }) => {
    await login(page)
    await page.goto('/change-password')
    await expect(page.getByRole('heading', { name: 'Change password', exact: true })).toBeVisible()
    await expect(page.getByLabel('Current Password', { exact: true })).toBeVisible()
    await page.reload()
    await expect(page).toHaveURL(/\/change-password$/)
    await expect(page.getByRole('heading', { name: 'Change password', exact: true })).toBeVisible()
  })

  test('staff cannot open administrator routes directly', async ({ page }) => {
    await login(page, 'staff')
    await page.goto('/users')
    await expect(page.getByRole('heading', { name: 'Access restricted' })).toBeVisible()
  })

  test('staff restrictions apply to navigation, API calls, and global search', async ({ page }) => {
    await login(page, 'staff')
    await page.goto('/accounting')
    await expect(page.getByRole('heading', { name: 'Access restricted' })).toBeVisible()
    await expect(page.getByRole('link', { name: /Accounting/i })).toHaveCount(0)

    const apiStatus = await page.evaluate(async () => {
      const token = localStorage.getItem('api_token')
      const response = await fetch('/api/accounting/journals', {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      return response.status
    })
    expect(apiStatus).toBe(403)

    const searchResult = await page.evaluate(async () => {
      const token = localStorage.getItem('api_token')
      const response = await fetch('/api/search?q=journal', {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
      })
      return response.json()
    })
    expect(searchResult).not.toHaveProperty('journals')
  })
})
