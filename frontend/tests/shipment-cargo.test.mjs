import test from 'node:test'
import assert from 'node:assert/strict'
import {cargoGroups,logisticsProgress,projectedReceiptBalance} from '../src/components/shipmentCargoPresentation.js'
import {etaRange} from '../src/components/shipmentIntelligencePresentation.js'
import {businessDate} from '../src/utils/businessFormat.js'
test('shipment ETA ranges use the same calendar format as recorded ETA without changing evidence dates',()=>{
 const eta={predicted:'2026-10-19',range_start:'2026-10-18',range_end:'2026-10-20'}
 assert.equal(etaRange(eta),'2026-10-18 – 2026-10-20')
 assert.equal(etaRange(eta,value=>businessDate(value,'en')),'18 Oct 2026 – 20 Oct 2026')
 assert.equal(eta.predicted,'2026-10-19');assert.equal(etaRange(null),'—')
 assert.ok(!etaRange(eta,value=>businessDate(value,'sq')).includes('2026-10-'))
})
test('projected receipt adds only the selected allocation to the V6 excluding-shipment baseline',()=>{
 const p={arrival_target:'warehouse',forecast_available:true,arrival:'2026-10-16',quantity:10.125,timeline:[{date:'2026-10-16',baseline:3.625}]}
 assert.equal(projectedReceiptBalance(p),13.75)
 assert.equal(projectedReceiptBalance({...p,arrival_target:'port'}),null)
 assert.equal(projectedReceiptBalance({...p,forecast_available:false}),null)
 assert.equal(projectedReceiptBalance({...p,timeline:[]}),null)
})
test('cargo search retains PO grouping and searches names, SKUs and destination',()=>{
 const cargo={groups:[{reference:'PO-1',destination:'Ferizaj',items:[{name:'Milano 04',sku:'MIL-04'},{name:'Handles',sku:'HW'}]},{reference:'PO-2',items:[{name:'Roma'}]}]}
 assert.equal(cargoGroups(cargo,'milano')[0].items.length,1)
 assert.equal(cargoGroups(cargo,'hw')[0].items[0].name,'Handles')
 assert.equal(cargoGroups(cargo,'ferizaj')[0].items.length,2)
 assert.equal(cargoGroups(cargo,'missing').length,0)
})
test('timeline never infers completed stages or accepts future actuals',()=>{
 const now=new Date('2026-10-09T12:00:00Z')
 const shipment={departed_at:'2026-10-01',milestones:[{milestone_type:'origin_port',actual_at:'2026-09-30'},{milestone_type:'destination_port',planned_at:'2026-10-08',actual_at:'2026-10-12'}]}
 const timeline=logisticsProgress(shipment,null,now)
 assert.equal(timeline.find(s=>s.key==='supplier_confirmation').state,'upcoming')
 assert.equal(timeline.find(s=>s.key==='origin_port').state,'completed')
 assert.equal(timeline.find(s=>s.key==='sea_transit').state,'current')
 assert.equal(timeline.find(s=>s.key==='destination_port').state,'delayed')
})
