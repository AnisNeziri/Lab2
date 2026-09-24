import { test, expect } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { login } from '../helpers/auth.mjs'

test.describe('Portable disaster recovery', () => {
  test('encrypted company backup downloads, validates, restores, and records history', async ({ page }) => {
    await login(page)
    await page.goto('/reports')

    const passphrase = 'E2E-backup-passphrase-2026'
    const passphraseFields = page.locator('.backup-passphrase-fields input')
    await passphraseFields.nth(0).fill(passphrase)
    await passphraseFields.nth(1).fill(passphrase)

    const downloadPromise = page.waitForEvent('download')
    await page.getByRole('button', { name: /Download full backup/i }).click()
    const download = await downloadPromise
    const backupPath = await download.path()
    expect(backupPath).toBeTruthy()
    await expect(page.locator('.backup-notice')).toContainText(/downloaded/i)

    await page.locator('input[type="file"][accept*="aimsbackup"]').setInputFiles({
      name: download.suggestedFilename(),
      mimeType: 'application/octet-stream',
      buffer: readFileSync(backupPath),
    })
    await expect(page.locator('.backup-file-details')).toBeVisible()
    await page.locator('.backup-restore-passphrase input').fill(passphrase)
    await page.locator('input[type="radio"][value="replace"]').check()
    await page.locator('.backup-danger-confirm input[type="checkbox"]').check()

    const restoreResponse = page.waitForResponse((response) => response.url().endsWith('/api/backup/import'))
    await page.getByRole('button', { name: /Validate and restore/i }).click()
    const restored = await restoreResponse
    const restoreBody = await restored.text()
    expect(restored.ok(), `${restored.status()} ${restoreBody}`).toBeTruthy()
    await expect(page.locator('.backup-notice')).toContainText(/restored/i)
    await expect(page.locator('.backup-result')).toContainText(/Restore summary/i)

    await page.goto('/system-integrity')
    await expect(page.getByRole('heading', { name: /Backup history/i })).toBeVisible()
    await expect(page.locator('.integrity-history')).toContainText(/export/i)
    await expect(page.locator('.integrity-history')).toContainText(/restore/i)
    await expect(page.locator('.integrity-history')).toContainText(/completed/i)
  })
})
