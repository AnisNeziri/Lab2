import test from 'node:test'
import assert from 'node:assert/strict'
import { systemStatus } from '../src/components/systemStatus.js'

test('desktop mode is intentional even when Redis is unavailable', () => {
  const status = systemStatus({ mode: 'offline', redis: { status: 'unavailable' } })
  assert.equal(status.title, 'Desktop offline mode')
  assert.match(status.message, /Redis is not required/)
})
test('Redis failures identify connectivity and preserve actionable text', () => {
  const status = systemStatus({ mode: 'online', redis: { status: 'unavailable', message: 'Start Redis' } })
  assert.equal(status.title, 'Redis connection problem')
  assert.equal(status.message, 'Start Redis')
})
test('disabled, connected, cache failure and endpoint failure remain distinct', () => {
  assert.equal(systemStatus({ mode: 'online', redis: { status: 'disabled' } }).title, 'Redis optional: disabled')
  assert.equal(systemStatus({ mode: 'online', redis: { status: 'connected' } }), null)
  assert.equal(systemStatus({ cache_available: false }).title, 'Cache connection problem')
  assert.equal(systemStatus(null, true).title, 'System status unavailable')
  assert.equal(systemStatus(null), null)
})
