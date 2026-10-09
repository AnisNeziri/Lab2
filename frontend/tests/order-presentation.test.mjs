import test from 'node:test'
import assert from 'node:assert/strict'
import { formatOrderMoney, formatOrderDate } from '../src/components/orderPresentation.js'

test('order amounts preserve missing values and display real zero', () => {
  for (const value of [null, undefined, '', 'not money', Infinity]) assert.equal(formatOrderMoney(value,'EUR'),'—')
  assert.equal(formatOrderMoney('0.00','EUR'),'0.00 EUR')
  assert.equal(formatOrderMoney('12500.75','EUR'),'12,500.75 EUR')
  assert.equal(formatOrderMoney('12500.75','EUR','sq'),`${new Intl.NumberFormat('sq-AL',{minimumFractionDigits:2,maximumFractionDigits:2}).format(12500.75)} EUR`)
})
test('order dates retain the calendar date, including timestamp responses', () => {
  assert.equal(formatOrderDate(null),'—')
  assert.equal(formatOrderDate('invalid'),'—')
  assert.equal(formatOrderDate('2026-10-06T00:00:00.000000Z'),'6 Oct 2026')
  assert.equal(formatOrderDate('2026-10-06'),formatOrderDate('2026-10-06T23:59:59Z'))
})
