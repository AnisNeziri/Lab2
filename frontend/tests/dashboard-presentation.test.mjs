import test from 'node:test'
import assert from 'node:assert/strict'
import { movementActivity, categoryActivity } from '../src/components/dashboardPresentation.js'

test('dashboard activity counts do not add metres and pieces or alter source data', () => {
  const rows = [{type:'out', quantity:'500', created_at:'2026-10-01T12:00:00',product:{unit:'pcs',category:{name:'Hardware'}}},{type:'out',quantity:'2.5',created_at:'2026-10-01T13:00:00',product:{unit:'m',category:{name:'Hardware'}}},{type:'in',quantity:'10000',created_at:'2026-10-01T15:00:00'}]
  const before = JSON.stringify(rows)
  assert.equal(movementActivity(rows)[0].Out,2)
  assert.equal(movementActivity(rows)[0].In,1)
  assert.deepEqual(categoryActivity(rows),[{name:'Hardware',value:2}])
  assert.equal(JSON.stringify(rows),before)
})

test('missing activity is empty, not invented stock or sales distribution', () => {
  assert.deepEqual(movementActivity([]),[])
  assert.deepEqual(categoryActivity([]),[])
  assert.equal(movementActivity([{type:'out',created_at:'invalid'}]).length,0)
})
