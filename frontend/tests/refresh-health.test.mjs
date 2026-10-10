import test from 'node:test'
import assert from 'node:assert/strict'
import {refreshState} from '../src/lib/refreshHealth.js'
import {createDashboardResources} from '../src/components/dashboard/dashboardResources.js'
test('refresh indicator reports measured health, paused visibility and failures',()=>{
  const h={active:true,lastSuccess:1000,failures:0}
  assert.equal(refreshState(h,true,true,2000),'healthy')
  assert.equal(refreshState(h,false,true,2000),'paused')
  assert.equal(refreshState({...h,lastSuccess:0},true,true,2000),'connecting')
  assert.equal(refreshState({...h,failures:2},true,true,2000),'warning')
  assert.equal(refreshState(h,true,false,2000),'warning')
  assert.equal(refreshState(h,true,true,47000),'warning')
  assert.equal(refreshState(h,true,true,2000,false),'warning')
})
test('widget health reflects repeated failure and recovers without losing data',async()=>{
  let fails=false,healthy=true
  const resources=createDashboardResources(async()=>{if(fails)throw Error('Disconnected');return {value:12}})
  resources.subscribeHealth(value=>{healthy=value})
  await resources.load('/test');fails=true
  await resources.load('/test',true);await resources.load('/test',true)
  assert.equal(healthy,false);assert.deepEqual(resources.state('/test').data,{value:12})
  fails=false;await resources.load('/test',true);assert.equal(healthy,true);resources.dispose()
})
