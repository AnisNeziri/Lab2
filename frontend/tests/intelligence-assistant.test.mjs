import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { safeRecordPath } from '../src/components/assistantPresentation.js'
import { navigationContext } from '../src/config/navigation.js'

test('assistant navigation and internal evidence links use existing routes',()=>{
  assert.equal(navigationContext('/intelligence-assistant').entry.en,'Intelligence Assistant')
  for(const path of ['/intelligence-assistant','/procurement?request=42','/inventory-intelligence?view=decisions&decision=5','/shipments/my-shipments?shipment=8'])assert.equal(safeRecordPath(path),path)
  for(const path of ['https://evil.example','//evil.example','/unknown-route','/products\\evil'])assert.equal(safeRecordPath(path),null)
})
test('chat uses server orchestration and explicit action controls, never direct business writes',()=>{
  const assistant=readFileSync(new URL('../src/components/AimsAssistant.jsx',import.meta.url),'utf8'),reply=readFileSync(new URL('../src/components/IntelligenceReply.jsx',import.meta.url),'utf8')
  assert.match(assistant,/askIntelligence/);assert.match(assistant,/ticket===sequence.current/);assert.match(assistant,/slice\(-40\)/)
  assert.match(reply,/confirmAssistantDraft/);assert.match(reply,/latch.current/);assert.match(reply,/confirm:true/)
  assert.doesNotMatch(assistant+reply,/dangerouslySetInnerHTML|\/journal-entries|\/stock-adjustments/)
})
