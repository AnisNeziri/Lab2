import test from 'node:test'
import assert from 'node:assert/strict'
import { formatOrderMoney, formatOrderDate } from '../src/components/orderPresentation.js'
import { orderFulfillmentLabel, orderConfirmationMessage } from '../src/components/orderStockPresentation.js'
import { attentionTitle, attentionDescription } from '../src/lib/actionCenterPresentation.js'

test('confirmation and order headers describe actual reservations and shortages in both languages',()=>{
 const t=(en,sq)=>en,label=x=>x,d={states:{fulfillment:'ready_to_allocate',backordered:true},order:{items:[{unit:'m',reserved_quantity:'0',backordered_quantity:'12.5'}]}}
 assert.equal(orderFulfillmentLabel(d,label,t),'Awaiting stock')
 assert.match(orderConfirmationMessage(d),/Reserved: 0 m\. Backordered: 12.5 m/)
 d.order.items[0].reserved_quantity='5'
 assert.equal(orderFulfillmentLabel(d,label,(en,sq)=>sq),'Rezervuar pjesërisht · mungesë stoku')
 assert.match(orderConfirmationMessage(d,'sq'),/Rezervuar: 5 m/)
 d.states={fulfillment:'delivered',backordered:false}
 assert.equal(orderFulfillmentLabel(d,label,t),'delivered')
 assert.match(orderConfirmationMessage({order:{items:[{unit:'kg',reserved_quantity:0.5,backordered_quantity:0.125}]}}),/Reserved: 0.5 kg\. Backordered: 0.125 kg/)
})

test('warehouse shortage issues explain their actual source instead of shipment jargon',()=>{
 const issue={kind:'order',title:'allocation_incomplete',exception_type:'allocation_incomplete'}
 assert.equal(attentionTitle(issue,'en'),'Not enough stock to reserve')
 assert.equal(attentionTitle(issue,'sq'),'Stok i pamjaftueshëm për rezervim')
 assert.match(attentionDescription(issue,'sq'),/porosinë/)
 assert.doesNotMatch(attentionDescription(issue),/shipment|purchasing/)
})

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
