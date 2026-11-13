import test from 'node:test'
import assert from 'node:assert/strict'
import { focusedNavigation, suggestedWorkProfile } from '../src/config/everydayWorkspace.js'
import { permittedNavigation } from '../src/config/navigation.js'
import { answerView } from '../src/components/assistantAnswerView.js'

test('daily focus keeps frequent and occasional tools in the same functional group',()=>{
 const source=[{id:'work',items:[{id:'dashboard'},{id:'fulfillment'},{id:'automation-studio'},{id:'accounting'}]}]
 const r=focusedNavigation(source,[],'warehouse')
 assert.deepEqual(r.groups.map(g=>[g.id,g.items.map(e=>e.id)]),[['work',['dashboard','fulfillment','automation-studio','accounting']]])
 assert.equal(source[0].items.length,4)
})
test('job roles select their own daily workspace without requiring a nonexistent accounting permission',()=>{
 assert.equal(suggestedWorkProfile('accountant',[]),'finance')
 for(const role of ['sales','purchasing','warehouse','hr'])assert.equal(suggestedWorkProfile(role,[]),role)
})
test('role focus never adds finance, payroll or packages to permitted navigation',()=>{
 const permissions=['dashboard.view','inventory.view','tasks.view']
 const groups=permittedNavigation(permissions,false,'staff')
 for(const profile of ['owner','sales','warehouse','purchasing','finance','hr']){
  const r=focusedNavigation(groups,[],profile),ids=r.groups.flatMap(g=>g.items).map(e=>e.id)
  assert.equal(ids.includes('payroll'),false)
  assert.equal(ids.includes('accounting'),false)
 }
})
test('answer highlights copy stock authority and preserve zero; model text cannot define components',()=>{
 const r=answerView({text:'Available 999',cards:[{tool:'get_product_availability',url:'/products',evidence:{product:{name:'Fabric',unit:'m'},position:{available:0,physical:10,reserved:10,incoming:0}}}]})
 assert.equal(r.value,0);assert.equal(r.unit,'m');assert.equal(r.facts[0].value,10)
 assert.equal(answerView({text:'<script>bad()</script>',component:'inventory',value:999}).kind,'plain')
})
test('sales uses source period and currency without relabeling operational totals as profit',()=>{
 const r=answerView({cards:[{tool:'get_sales_analytics',currency:'EUR',evidence:{metrics:{revenue:120},period:{from:'2026-10-01',to:'2026-10-31'}}}]})
 assert.equal(r.value,120);assert.equal(r.unit,'EUR');assert.match(r.period,/2026-10-31/);assert.match(r.meaning,/not booked/)
})
test('unqualified plans never present a suggested quantity as approved evidence',()=>{
 const r=answerView({cards:[{tool:'get_inventory_plan',evidence:{plan:{base_quantity:999,coverage_supported:false,feasible:true,unit:'m'}}}]})
 assert.equal(r.kind,'recommendation');assert.equal(r.value,null);assert.match(r.headline,/not available/)
})
