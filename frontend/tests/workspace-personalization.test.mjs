import test from 'node:test'
import assert from 'node:assert/strict'
import {widgetCatalog,availableWidgets,defaultDashboard,normalizeDashboard,moveWidget} from '../src/config/dashboardWidgets.js'
import {navigationGroups,permittedNavigation} from '../src/config/navigation.js'
import {defaultNavigation,normalizeNavigation,personalizedNavigation} from '../src/config/workspaceNavigation.js'
import {matchingCommands} from '../src/config/commandCatalog.js'
import {createDashboardResources} from '../src/components/dashboard/dashboardResources.js'

const all=[...new Set(widgetCatalog.flatMap(w=>w.permissions).concat(['products.manage','analytics.ml_datasets','replenishment.view']))]
const inventory=['dashboard.view','inventory.view','transfers.view','tasks.view']

test('registry is unique, permission-aware and provides bounded sizes and role defaults',()=>{
  assert.equal(widgetCatalog.length,new Set(widgetCatalog.map(w=>w.id)).size)
  for(const w of widgetCatalog) {
    assert.ok(w.minColumns>=3&&w.maxColumns<=12&&w.minColumns<=w.maxColumns)
    assert.ok(w.sizes.includes(w.defaultSize));assert.ok(w.minHeight>=140)
  }
  assert.ok(availableWidgets(all).some(w=>w.id==='cash-outlook'))
  assert.equal(availableWidgets(inventory).some(w=>w.id==='cash-outlook'||w.id==='inventory-value'),false)
  assert.equal(availableWidgets([]).length,0)
  const owner=defaultDashboard(all,'admin').widgets.map(w=>w.id)
  for(const id of ['today-sales','orders-overview','low-stock','receivables','shipments','action-center']) assert.ok(owner.includes(id),id)
  assert.ok(owner.indexOf('sales-trend')<owner.indexOf('orders-overview'))
  for(const id of ['stock-turnover','warehouse-sections','stock-alert-count','stock-movements','category-activity','warehouse-stock','quick-actions','activity'])assert.ok(owner.includes(id),id)
  assert.ok(defaultDashboard(inventory,'staff').widgets.some(w=>w.id==='warehouse-stock'))
})

test('layout migration deduplicates, rejects arbitrary coordinates and unsupported configuration',()=>{
  const layout=normalizeDashboard({widgets:[null,{id:'unknown'},{id:'product-count',x:222,y:99,size:'tiny',settings:{period:'anything'}},{id:'product-count'},
    {id:'sales-trend',position:100,size:'large',settings:{period:'year',x:33}}]},all)
  assert.equal(layout.version,1)
  assert.deepEqual(layout.widgets,[{id:'product-count',position:0,size:'small',settings:{}},{id:'sales-trend',position:1,size:'large',settings:{period:'year'}}])
  assert.equal(normalizeDashboard({version:90,widgets:[]},all),null)
  assert.deepEqual(normalizeDashboard({version:1,widgets:[]},all).widgets,[])
  assert.equal(normalizeDashboard(layout,inventory).widgets.length,1)
  assert.deepEqual(moveWidget(layout,0,1).widgets.map(w=>w.id),['sales-trend','product-count'])
  assert.deepEqual(moveWidget(layout,0,1).widgets.map(w=>w.position),[0,1])
  assert.equal(moveWidget(layout,-1,0),layout)
})

test('sidebar hides and restores without changing permissions or logical groups',()=>{
  const pref=normalizeNavigation({hidden:['dashboard','action-center','analytics','analytics'],favorites:['products'],order:{inventory:['stock','products','finance']}})
  assert.deepEqual(pref.hidden,['analytics'])
  const custom=personalizedNavigation(all,true,'admin',pref)
  assert.deepEqual(custom.favorites.map(i=>i.id),['products'])
  assert.equal(custom.groups.flatMap(g=>g.items).some(i=>i.id==='analytics'),false)
  assert.ok(custom.groups.find(g=>g.id==='inventory').items[0].id==='stock')
  assert.equal(custom.groups.find(g=>g.id==='inventory').items.some(i=>i.id==='finance'),false)
  const restored=personalizedNavigation(all,true,'admin',{...pref,hidden:[]})
  assert.ok(restored.groups.flatMap(g=>g.items).some(i=>i.id==='analytics'))
  assert.equal(personalizedNavigation(inventory,false,'staff',pref).favorites[0].id,'products')
  assert.deepEqual(normalizeNavigation({version:99,hidden:['products']}).hidden,[])
  assert.equal(normalizeNavigation({favorites:Array.from({length:10},(_,i)=>String(i))}).favorites.length,6)
  assert.ok(defaultNavigation('staff').hidden.includes('automation-studio'))
})

test('all permitted navigation remains searchable in both languages even when hidden',()=>{
  const hidden=normalizeNavigation({hidden:['analytics','decision-learning','automation-studio']})
  assert.equal(personalizedNavigation(all,false,'admin',hidden).groups.flatMap(g=>g.items).some(i=>hidden.hidden.includes(i.id)),false)
  const commands=matchingCommands('',all,'en',{role:'admin',enable3dMap:true})
  for(const p of permittedNavigation(all,true,'admin').flatMap(g=>g.items)) assert.ok(commands.some(c=>c.path===p.path),p.id)
  assert.ok(matchingCommands('analytics',all).some(c=>c.path==='/analytics'))
  assert.ok(matchingCommands('mësimi i vendimeve',all,'sq').some(c=>c.path==='/decision-learning'))
  assert.equal(matchingCommands('financial',inventory).length,0)
  assert.equal(navigationGroups.flatMap(g=>g.items).length,new Set(navigationGroups.flatMap(g=>g.items).map(i=>i.id)).size)
})

test('widget reads deduplicate and cap concurrency at four',async()=>{
  let active=0,peak=0;const pending=[],calls=[]
  const client=createDashboardResources(key=>new Promise(resolve=>{active++;peak=Math.max(peak,active);calls.push(key);pending.push(()=>{active--;resolve({key})})}))
  const first=client.load('/a');assert.equal(client.load('/a'),first)
  const rest=Array.from({length:8},(_,i)=>client.load('/'+i))
  assert.equal(calls.length,4)
  while(pending.length) {pending.shift()();await new Promise(resolve=>setImmediate(resolve))}
  await Promise.all([first,...rest]);assert.equal(peak,4);assert.equal(calls.length,9)
  await client.load('/a');assert.equal(calls.length,9)
  client.dispose()
})

test('errors remain local, refresh retains data, and only subscribed widgets refresh',async()=>{
  let fail=false,calls=[]
  const client=createDashboardResources(async key=>{calls.push(key);if(fail&&key==='/a')throw new Error('Widget unavailable');return {key}})
  const states=[],unsubscribe=client.subscribe('/a',s=>states.push(s))
  await client.load('/a');await client.load('/b');fail=true
  client.refresh();await client.load('/a')
  assert.equal(client.state('/a').error,'Widget unavailable')
  assert.deepEqual(client.state('/a').data,{key:'/a'})
  assert.equal(client.state('/b').error,'');assert.equal(calls.filter(x=>x==='/b').length,1)
  assert.ok(states.find(s=>s.loading&&s.data?.key==='/a'))
  fail=false;await client.load('/a',true);assert.equal(client.state('/a').error,'')
  unsubscribe();client.dispose()
})

test('unmount settles queued reads and aborts active reads without publishing late data',async()=>{
  let published=0
  const client=createDashboardResources((key,{signal})=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new Error('Aborted')))))
  const requests=Array.from({length:6},(_,i)=>client.load('/'+i))
  client.subscribe('/0',()=>published++);client.dispose()
  assert.deepEqual(await Promise.all(requests),[null,null,null,null,null,null])
  assert.equal(published,0);assert.equal(await client.load('/new'),null)
})
