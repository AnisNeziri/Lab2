import test from 'node:test'
import assert from 'node:assert/strict'
import { pilotEntityContext } from '../src/components/pilotContext.js'

test('feedback context carries only a known numeric business identity', () => {
  assert.deepEqual(pilotEntityContext('/products','?product=42&token=secret&search=Private'),{entity_type:'product',entity_id:42})
  assert.deepEqual(pilotEntityContext('/invoices','?invoice=8'),{entity_type:'invoice',entity_id:8})
  assert.deepEqual(pilotEntityContext('/unrecognized','?product=42'),{})
  for (const value of ['0','-1','1.5','1e5','secret','9007199254740992']) assert.deepEqual(pilotEntityContext('/products',`?product=${value}`),{})
})
