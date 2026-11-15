import { spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, lstatSync, realpathSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

export default function globalSetup() {
  const e2eDir = path.dirname(fileURLToPath(import.meta.url))
  const backendDir = path.resolve(e2eDir, '../../backend')
  const databasePath = path.join(backendDir, 'database', 'e2e.sqlite')
  if (process.env.DB_URL || (process.env.APP_ENV === 'production')) throw new Error('Browser certification requires its isolated E2E database; production overrides are rejected.')
  const configCache = process.env.APP_CONFIG_CACHE || path.join('bootstrap', 'cache', 'config.php')
  if (existsSync(path.resolve(backendDir, configCache))) {
    throw new Error('Browser certification requires an uncached Laravel configuration so SQLite isolation is applied.')
  }

  if (realpathSync(path.dirname(databasePath)) !== path.join(realpathSync(backendDir), 'database')) {
    throw new Error('Browser certification database must stay inside the checkout.')
  }
  try {
    const database = lstatSync(databasePath)
    if (!database.isFile() || database.nlink !== 1) throw new Error('Browser certification requires a dedicated SQLite file.')
  } catch (error) {
    if (error.code !== 'ENOENT') throw error
    writeFileSync(databasePath, '', { flag: 'wx' })
  }

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
  })

  if (result.status !== 0) {
    throw new Error(`Could not prepare the isolated E2E database.\n${result.stdout}\n${result.stderr}`)
  }

  const password = randomBytes(24).toString('hex')
  const accounts = spawnSync('php', [path.join(e2eDir, 'fixtures/workday-accounts.php')], {
    cwd: backendDir, env: { ...environment, AIMS_E2E_WORKDAY_PASSWORD: password }, encoding: 'utf8',
  })
  if (accounts.status !== 0) throw new Error(`Could not create synthetic workday users.\n${accounts.stdout}\n${accounts.stderr}`)
  process.env.AIMS_E2E_WORKDAY_PASSWORD = password
}
