import test from 'node:test'
import assert from 'node:assert/strict'
import { orderNextAction } from '../src/components/orderNextAction.js'
import { payrollStage } from '../src/pages/payrollWorkflow.js'
import { attentionPreview } from '../src/lib/actionCenterPresentation.js'

test('order hints prioritize source issues, approval and stock review before confirmation',()=>{
  const d={order:{status:'draft',items:[{product_id:1,base_quantity:10}],availability:{1:{available:4}}},channel:{oversale_policy:'require_review'}}
  assert.equal(orderNextAction(d),'backorder')
  assert.equal(orderNextAction({...d,issues:[{code:'price_variance'}]}),'review')
  assert.equal(orderNextAction({...d,acceptance_approval_required:true}),'approval')
  assert.equal(orderNextAction({...d,resolution:{stock_review:{reason:'Agreed'}}}),'confirm')
  assert.equal(orderNextAction({...d,order:{...d.order,status:'cancelled'}}),'none')
  assert.equal(orderNextAction({...d,order:{...d.order,confirmed_at:'2026-10-19'},connections:{next_action:'stock_review'}}),'stock')
  assert.equal(orderNextAction({...d,order:{...d.order,confirmed_at:'2026-10-19'},connections:{next_action:'future_unknown'}}),'review')
  assert.equal(orderNextAction({}),'review')
})
test('payroll display retains posting and settlement as separate steps; critical issues precede approvals',()=>{
  assert.ok(payrollStage('approved')<payrollStage('posted'))
  assert.ok(payrollStage('posted')<payrollStage('paid'))
  assert.ok(payrollStage('paid')<payrollStage('closed'))
  const issues=[{kind:'approval',title:'Approval'},{kind:'finance',title:'GL mismatch',severity:'critical'},{kind:'customer',due_at:'2026-01-01',title:'Overdue'}]
  assert.deepEqual(attentionPreview(issues).map(i=>i.title),['GL mismatch','Overdue','Approval'])
  assert.equal(issues.length,3)
})
test('shared API validation fields do not expose database errors',async()=>{
  globalThis.window={__AIMS_API_BASE__:'/api'};globalThis.document={documentElement:{lang:'en'}}
  const {parseApiResponse}=await import('../src/api/client.js')
  const response=new Response(JSON.stringify({message:'SQLSTATE: private database',errors:{amount:['SQLSTATE: select secret from private_table'],reason:['A reason is required.']}}),{status:422,headers:{'Content-Type':'application/json'}})
  await assert.rejects(()=>parseApiResponse(response,'Unable to save.'),e=>{
    assert.doesNotMatch(e.message,/SQLSTATE|private_table/)
    assert.doesNotMatch(e.errors.amount[0],/SQLSTATE|private_table/)
    assert.equal(e.errors.reason[0],'A reason is required.')
    return true
  })
})
