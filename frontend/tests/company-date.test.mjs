import assert from 'node:assert/strict'
import test from 'node:test'
import { addCalendarDays, companyDate } from '../src/utils/companyDate.js'

test('distribution dates follow the company on both sides of UTC midnight', () => {
  const instant = new Date('2026-11-24T23:30:00Z')
  assert.equal(companyDate('Europe/Belgrade', instant), '2026-11-25')
  assert.equal(companyDate('UTC', instant), '2026-11-24')
  assert.equal(companyDate('America/New_York', new Date('2026-11-25T02:00:00Z')), '2026-11-24')
  assert.equal(addCalendarDays('2026-10-24', 3), '2026-10-27')
})
