import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
const src=fs.readFileSync(new URL('../src/components/decisionPresentation.js',import.meta.url),'utf8')
const {decisionLabel,decisionLabels,decisionScenario,draftable}=await import('data:text/javascript;base64,'+Buffer.from(src).toString('base64'))
test('all decision copy supports English and Albanian',()=>{for(const [key,pair] of Object.entries(decisionLabels)){assert.equal(pair.length,2);assert.ok(pair.every(x=>typeof x==='string'&&x.length>0));assert.equal(decisionLabel(key,'sq'),pair[1])}})
test('scenario input preserves zero and strips blanks without inventing values',()=>{assert.deepEqual(decisionScenario({base_quantity:'0',supplier_id:'',delay_days:'2',demand_multiplier:'1.2',type:'purchase'}),{base_quantity:0,delay_days:2,demand_multiplier:1.2,type:'purchase'})})
test('draft controls require a feasible priced purchase, never monitor or unknown price',()=>{assert.equal(draftable({feasible:true,base_quantity:15,supplier_id:2,unit_price:'0.00'}),true);for(const o of [{feasible:true,base_quantity:0,supplier_id:2,unit_price:'1.00'},{feasible:false,base_quantity:20,supplier_id:2,unit_price:'1.00'},{feasible:true,base_quantity:20,supplier_id:2,unit_price:null}])assert.equal(draftable(o),false)})
