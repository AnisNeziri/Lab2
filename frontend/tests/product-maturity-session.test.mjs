import test from 'node:test'
import assert from 'node:assert/strict'
import {readSession,writeSession,sessionKey,recentDestination} from '../src/lib/sessionWorkspace.js'
import {businessDate,businessMoney,businessNumber} from '../src/utils/businessFormat.js'
import {businessError} from '../src/utils/businessErrors.js'
import {businessStatus} from '../src/utils/businessStatus.js'
import {profileDashboard,profileNavigation,workProfiles} from '../src/config/workProfiles.js'
import {canOpenPage} from '../src/config/pageAccess.js'
import {searchSections} from '../src/lib/searchPresentation.js'
import {financeWarning} from '../src/utils/financePresentation.js'
import {attentionPreview,attentionDescription} from '../src/lib/actionCenterPresentation.js'
import {businessActivity} from '../src/utils/businessActivity.js'
test('record history presents money, quantities and events in business language without rewriting records',()=>{
 const movement={title:'Stock issued',kind:'stock_movement',movement_code:'daily_sale',quantity:'12.5',unit:'m',detail:'daily_sale · 12.5'}
 assert.equal(businessActivity(movement,'en').detail,'Daily sale · 12.5 m');assert.equal(businessActivity(movement,'sq').title,'Stoku doli');assert.equal(movement.detail,'daily_sale · 12.5')
 assert.equal(businessActivity({title:'Payment',kind:'customer_transaction',amount:0,currency:'EUR'},'en').detail,'0.00 EUR')
 assert.equal(businessActivity({title:'Shipment Updated',event_type:'shipment.updated'},'sq').title,'Dërgesa u përditësua')
 const internal=businessActivity({title:'Internal event',event_type:'intelligence.special_event'},'sq');assert.equal(internal.title,'Aktivitet i regjistruar');assert.equal(internal.technical,'intelligence.special_event')
})

test('Action Center groups identical exceptions without deleting records and surfaces approvals and overdue work',()=>{
 const items=[{kind:'stock',title:'Watch',url:'/stock'},{kind:'order',title:'short_pick',description:'Quantity short',url:'/fulfillment?order=1'},{kind:'order',title:'short_pick',description:'Quantity short',url:'/fulfillment?order=1'},{kind:'customer',title:'Buyer',due_at:'2026-09-01',url:'/customer-debts?customer=1'},{kind:'approval',title:'Approval',url:'/procurement'}]
 const result=attentionPreview(items,new Date('2026-10-10').getTime());assert.equal(items.length,5);assert.equal(result.length,4);assert.equal(result[0].kind,'approval');assert.equal(result[1].kind,'customer');assert.equal(result.find(r=>r.title==='short_pick').occurrences,2)
 assert.equal(attentionDescription({description:'Review enterprise decision'},'en'),'Review the recommended stock action');assert.match(attentionDescription({description:'Customer payment overdue'},'sq'),/Pagesa/)
})

test('global search ranks an exact record before related tasks and shares visible/keyboard order',()=>{
 const result={tasks:[{title:'Review available transfers · Milano 01',url:'/action-center'}],products:[{title:'Milano 01',url:'/products?product=1'}],suppliers:[{title:'Foreign',url:'/forbidden'}]}
 const sections=searchSections(result,'Milano 01',url=>url!=='/forbidden');assert.deepEqual(sections.map(([type])=>type),['products','tasks']);assert.equal(sections.flatMap(([,items])=>items)[0].url,'/products?product=1')
})
test('finance warnings are business-readable in both languages without concealing source separation',()=>{
 assert.match(financeWarning({code:'daily_sales_not_in_vat_book'}, {},'en'),/not in the VAT/);assert.match(financeWarning({code:'daily_sales_not_in_vat_book'}, {},'sq'),/jo në librin/)
 assert.match(financeWarning({code:'missing_expense_proof'},{expenses_missing_proof_count:2},'sq'),/^2 /);assert.match(financeWarning({code:'sales_sources_unreconciled'}),/same sales/)
 assert.equal(businessStatus('short_pick','sq'),'Sasi e pamjaftueshme për mbledhje')
})
test('work-session memory expires, isolates identities and tolerates blocked/corrupt storage',()=>{
 const data=new Map(),storage={getItem:k=>data.get(k),setItem:(k,v)=>data.set(k,v)},a=sessionKey('company:1','products.search'),b=sessionKey('company:2','products.search')
 writeSession(storage,a,'Milano',1000);assert.equal(readSession(storage,a,'',1001),'Milano');assert.equal(readSession(storage,b,'',1001),'');assert.equal(readSession(storage,a,'',9*3600000),'');assert.equal(readSession(storage,a,'',1),'')
 data.set(a,'invalid');assert.equal(readSession(storage,a,'',1001),'');assert.doesNotThrow(()=>writeSession({setItem(){throw Error()}},a,'test'))
})
test('recent destinations are bounded, deduplicated and internal',()=>{
 let rows=[];for(let i=0;i<8;i++)rows=recentDestination(rows,{path:'/products?product='+i,label:'Product '+i});assert.equal(rows.length,5)
 rows=recentDestination(rows,rows[2]);assert.equal(new Set(rows.map(r=>r.path)).size,5);assert.equal(recentDestination(rows,{path:'//evil.test'}),rows)
})
test('business presentation preserves unknown, true zero, units and calendar dates',()=>{
 for(const value of [undefined,null,'','invalid',Infinity]){assert.equal(businessMoney(value),'—');assert.equal(businessNumber(value),'—')}
 assert.equal(businessMoney(0),'0.00 EUR');assert.equal(businessMoney(-100),'−100.00 EUR'.replace('−','-'));assert.equal(businessDate('2026-10-06'),'6 Oct 2026');assert.equal(businessDate('2026-10-06T23:59:59Z'),businessDate('2026-10-06'));assert.equal(businessDate('invalid'),'—')
 assert.notEqual(businessStatus('validated','sq'),'validated');assert.equal(businessStatus('accepted'),'Confirmed')
 assert.equal(businessStatus('REPLENISHMENT_DECISION'),'Restocking review');assert.equal(businessStatus('SUPPLIER_SELECTION','sq'),'Zgjedhja e furnitorit')
})
test('technical errors are readable while useful validation reasons survive',()=>{
 for(const value of ['SQLSTATE[HY000] failed','PDOException','Failed to fetch','TypeError undefined']){assert.equal(businessError(value),'The request could not be completed. Please try again.');assert.match(businessError(value,undefined,'sq'),/Provo përsëri/)}
 assert.equal(businessError('Quantity must be greater than 0.'),'Quantity must be greater than 0.')
})
test('all six work profiles are distinct and never grant permissions',()=>{
 const permissions=['dashboard.view','inventory.view','transfers.view','tasks.view'];assert.equal(Object.keys(workProfiles).length,6)
 assert.notDeepEqual(workProfiles.sales.widgets,workProfiles.warehouse.widgets)
 for(const profile of Object.keys(workProfiles)){const layout=profileDashboard(profile,permissions);for(const w of layout.widgets)assert.notEqual(w.id,'cash-outlook');const nav=profileNavigation(profile,permissions,'staff',false);assert.ok(!nav.hidden.includes('dashboard'));for(const id of nav.favorites){const page=id==='inventory-planning'?'/inventory-intelligence?view=planning':'/'+id;assert.equal(canOpenPage(page,permissions),true)}}
})
