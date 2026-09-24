import { spawnSync } from 'node:child_process'
import { existsSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

export default function globalSetup() {
  const e2eDir = path.dirname(fileURLToPath(import.meta.url))
  const backendDir = path.resolve(e2eDir, '../../backend')
  const databasePath = path.join(backendDir, 'database', 'e2e.sqlite')

  if (!existsSync(databasePath)) writeFileSync(databasePath, '')

  const environment = {
    ...process.env,
    APP_ENV: 'e2e',
    APP_KEY: 'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=',
    APP_DEBUG: 'false',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    DB_URL: '',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    BROADCAST_CONNECTION: 'log',
  }

  const result = spawnSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], {
    cwd: backendDir,
    env: environment,
    encoding: 'utf8',
    shell: process.platform === 'win32',
  })

  if (result.status !== 0) {
    throw new Error(`Could not prepare the isolated E2E database.\n${result.stdout}\n${result.stderr}`)
  }
}
