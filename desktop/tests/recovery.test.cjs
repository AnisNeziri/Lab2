const { test } = require('node:test')
const assert = require('node:assert/strict')
const path = require('node:path')
const { spawnSync } = require('node:child_process')
const { sanitizeInheritedEnvironment } = require('../src/runtime-env.cjs')

test('desktop excludes inherited server database settings and recovery credentials', () => {
  const env = { PATH: 'runtime', SystemRoot: 'C:\\Windows', DB_URL: 'server-database', DB_HOST: 'remote',
    db_password: 'private', MYSQL_DATABASE: 'web', APP_KEY: 'web-key', AIMS_DESKTOP_RECOVERY_PASSWORD: 'private',
    MAIL_PASSWORD: 'private', aisstream_api_key: 'private' }
  assert.deepEqual(sanitizeInheritedEnvironment(env), { PATH: 'runtime', SystemRoot: 'C:\\Windows' })
  assert.equal(env.DB_URL, 'server-database', 'caller environment must not be mutated')
})

test('legacy publishing command cannot bypass CI or run a publisher', () => {
  const result = spawnSync(process.execPath, [path.join(__dirname, '../scripts/release-via-ci.cjs')], { encoding: 'utf8', windowsHide: true })
  assert.equal(result.status, 1)
  assert.match(result.stderr, /Direct publishing is disabled/)
  const scripts = require('../package.json').scripts
  assert.equal(scripts['release:win'], 'node scripts/release-via-ci.cjs')
  assert.doesNotMatch(JSON.stringify(scripts), /--publish always/)
})
