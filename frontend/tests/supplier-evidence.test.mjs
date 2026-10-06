import test from 'node:test'
import assert from 'node:assert/strict'
import {deliveryTrend} from '../src/components/supplierEvidence.js'
test('delivery trend excludes partial, unknown and invalid outcomes without changing input',()=>{
 const rows=[{complete:true,lead_days:5,completion_date:'2026-01-05'},{complete:false,lead_days:50,completion_date:'2026-01-02'},{complete:true,lead_days:null,completion_date:'2026-01-03'},{complete:true,lead_days:2,completion_date:'2026-01-01'}]
 const frozen=JSON.stringify(rows),t=deliveryTrend(rows);assert.equal(t.rows.length,2);assert.equal(t.rows[0].lead_days,2);assert.equal(t.maximum,5);assert.equal(JSON.stringify(rows),frozen)
})
test('empty and zero-day trend never produces invalid coordinates',()=>{assert.equal(deliveryTrend([]).points,'');assert.equal(deliveryTrend([{complete:true,lead_days:0,completion_date:'2026-01-01'}]).points,'10,90')})
