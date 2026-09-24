import { test, expect } from '@playwright/test'
import { login, logout, users } from '../helpers/auth.mjs'

test.describe('Authentication and route protection', () => {
  test('@smoke admin can log in and log out', async ({ page }) => {
    await login(page)
    await logout(page)
  })

  test('@smoke invalid credentials show a useful error', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email address').fill(users.admin.email)
    await page.getByLabel('Password', { exact: true }).fill('incorrect-password')
    await page.getByRole('button', { name: 'Enter workspace' }).click()
    await expect(page.getByRole('alert')).toContainText(/incorrect|invalid|credentials/i)
    await expect(page).toHaveURL(/\/login$/)
  })

  test('@smoke protected URLs redirect anonymous users', async ({ page }) => {
    await page.goto('/products')
    await expect(page).toHaveURL(/\/login$/)
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
