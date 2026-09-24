import test from 'node:test'
import assert from 'node:assert/strict'
import { collectPages } from '../src/lib/collectPages.js'

test('selector options include later pages without changing filters or order', async () => {
  const calls = []
  const result = await collectPages(async (params) => {
    calls.push(params)
    return { data: [{ id: params.page }], last_page: 3 }
  }, { status: 'received' })
  assert.deepEqual(result, [{ id: 1 }, { id: 2 }, { id: 3 }])
  assert.deepEqual(calls, [1, 2, 3].map(page => ({ per_page: 50, status: 'received', page })))
})

test('selector pagination supports resource metadata and plain arrays', async () => {
  assert.deepEqual(await collectPages(async ({ page }) => ({ data: [page], meta: { last_page: 2 } })), [1, 2])
  assert.deepEqual(await collectPages(async () => []), [])
  assert.deepEqual(await collectPages(async () => [{ id: 7 }]), [{ id: 7 }])
})

test('selector loading does not silently return an incomplete list on failure', async () => {
  await assert.rejects(collectPages(async ({ page }) => {
    if (page === 2) throw new Error('Network unavailable')
    return { data: [1], last_page: 2 }
  }), /Network unavailable/)
})
