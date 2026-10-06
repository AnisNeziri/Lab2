import test from 'node:test'
import assert from 'node:assert/strict'
import { assistantIntent, recordCapability, safeRecordPath, recordAnswer, guideAnswer } from '../src/components/assistantPresentation.js'

test('English and Albanian product questions preserve the actual search term', () => {
  assert.deepEqual(assistantIntent('stock for Laptop Stand'), {kind:'record',mode:'stock',term:'Laptop Stand'})
  assert.deepEqual(assistantIntent('lëvizjet e Doreza'), {kind:'record',mode:'movements',term:'Doreza'})
  assert.equal(assistantIntent('how much stock do I have for Door Handles?').term, 'Door Handles')
  assert.equal(assistantIntent('forecast for "Door Handles"').mode, 'forecast')
})
test('Workflow questions route to guidance, write requests cannot execute actions', () => {
  assert.equal(assistantIntent('How do forecasts work?').page, 'inventory-intelligence')
  assert.equal(assistantIntent('Si përdoret kjo faqe?').kind, 'guide')
  assert.equal(assistantIntent('Delete Laptop Stand').kind, 'read_only')
  assert.equal(assistantIntent('Create purchase order').kind, 'read_only')
  assert.equal(guideAnswer('/inventory-intelligence?view=planning','sq').steps.length,3)
})
test('Assistant only exposes known read-only capabilities', () => {
  assert.deepEqual(recordCapability('products','movements'),{name:'get_stock_movements',key:'product_id'})
  assert.equal(recordCapability('customers','forecast'),null)
  assert.equal(recordCapability('users','record'),null)
})
test('Source navigation cannot leave AIMS or execute scripts', () => {
  for (const url of ['https://example.com','//evil.test','javascript:alert(1)','/\\evil','/unknown']) assert.equal(safeRecordPath(url),null)
  assert.equal(safeRecordPath('/products?product=12'),'/products?product=12')
  assert.equal(safeRecordPath('/control-tower/12'),'/control-tower/12')
})
test('Product answer uses server availability rather than calculating it from stock', () => {
  const answer=recordAnswer('products','stock',{product:{quantity:100,available_quantity:65,unit:'pcs'},warehouses:[]})
  assert.equal(answer.facts.find(([key])=>key==='Available to sell')[1],'65 pcs')
  assert.equal(answer.rows.length,0)
  assert.match(answer.text,/No warehouse allocation/)
})
test('Missing data remains unknown and unmeasured forecasts are not invented', () => {
  const answer=recordAnswer('products','stock',{product:{unit:'m'}})
  assert.equal(answer.facts.find(([key])=>key==='On hand')[1],'— m')
  assert.match(recordAnswer('products','forecast',{prediction:null}).text,/No usable forecast/)
  assert.deepEqual(recordAnswer('products','movements',[]).rows,[])
})
test('Credit facts reuse debt and advance separately without recalculating exposure', () => {
  const answer=recordAnswer('customers','record',{credit:{current_debt:'100.00',advance:'20.00',total_exposure:'80.00',credit_limit:null}})
  assert.equal(answer.facts.find(([key])=>key==='Exposure')[1],'80')
  assert.equal(answer.facts.find(([key])=>key==='Advance')[1],'20')
  assert.equal(answer.facts.find(([key])=>key==='Credit limit')[1],'No limit')
})
