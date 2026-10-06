import test from 'node:test'
import assert from 'node:assert/strict'
import {shipmentIntelligenceLabel,shipmentTimeline,etaRange} from '../src/components/shipmentIntelligencePresentation.js'
test('risk and milestone labels switch language independently',()=>{assert.equal(shipmentIntelligenceLabel('CRITICAL','sq'),'Kritike');assert.equal(shipmentIntelligenceLabel('CRITICAL','en'),'Critical');assert.match(shipmentIntelligenceLabel('INTERNATIONAL_TRANSIT','sq'),/ndërkombëtar/);assert.match(shipmentIntelligenceLabel('stale'),/Stale/)})
test('ETA ranges never fabricate precision or missing predictions',()=>{assert.equal(etaRange({predicted:null}),'—');assert.equal(etaRange({predicted:'2026-10-10',range_start:'2026-10-08',range_end:'2026-10-12'}),'2026-10-08 – 2026-10-12')})
test('timeline preserves null requirements and separate product unit quantities',()=>{const p={stock:{available_to_promise:80},safety_stock:20,requirement_date:null,arrival:'2026-10-12',quantity:10000};const points=shipmentTimeline(p);assert.equal(points[2].date,null);assert.equal(points[0].quantity,80);assert.equal(points[3].quantity,10000)})
