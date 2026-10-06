import test from 'node:test'
import assert from 'node:assert/strict'
import {customerSalesAccess,customerSalesLabel,customerSalesMoney,customerSalesTabs,filterCustomerProfiles} from '../src/pages/customerSalesPresentation.js'
test('seven focused sections and bilingual states',()=>{assert.equal(customerSalesTabs.length,7);assert.equal(customerSalesLabel('AT_RISK','sq'),'Nën aktivitetin e zakonshëm');assert.equal(customerSalesLabel('INSUFFICIENT_DATA','en'),'Collecting history')})
test('warehouse analytics access alone cannot open customer intelligence',()=>{assert.equal(customerSalesAccess(['analytics.view','inventory.view']),false);assert.equal(customerSalesAccess(['analytics.view','customers.manage','daily_sales.manage']),true)})
test('risk filter preserves seasonal and limited history without assigning churn',()=>{const p=[{name:'Seasonal',state:'SEASONAL_PAUSE'},{name:'Sparse',state:'INSUFFICIENT_DATA'},{name:'Buyer',state:'AT_RISK'}];assert.deepEqual(filterCustomerProfiles(p,'',true),[p[2]]);assert.equal(filterCustomerProfiles(p,'buy').length,1)})
test('unknown money is not fabricated as zero',()=>{assert.equal(customerSalesMoney(null),'—');assert.match(customerSalesMoney('900.00'),/900/);assert.ok(customerSalesMoney('900.00','EUR','sq'))})
