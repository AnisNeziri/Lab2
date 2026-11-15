import { expect } from '@playwright/test'

export const users = {
  admin: { email: 'admin@enterprise.com', password: 'password' },
  manager: { email: 'manager@enterprise.com', password: 'password' },
  staff: { email: 'staff@enterprise.com', password: 'password' },
}

export async function login(page, role = 'admin') {
  const user = users[role] ?? {
    email: `${role}@workday.e2e.example`, password: process.env.AIMS_E2E_WORKDAY_PASSWORD,
  }
  if (!user.password) throw new Error('No synthetic credentials were provisioned for the requested role.')
  await page.goto('/login')
  await page.getByLabel('Email address').fill(user.email)
  await page.getByLabel('Password', { exact: true }).fill(user.password)
  await page.getByRole('button', { name: 'Enter workspace' }).click()
  await expect(page).toHaveURL(role === 'superadmin' ? /\/superadmin$/ : /\/dashboard$/)
}

export async function logout(page) {
  const logoutButton = page.getByRole('button', { name: /log\s*out/i }).first()
  await expect(logoutButton).toBeVisible()
  await logoutButton.click()
  await expect(page).toHaveURL(/\/login$/)
}
