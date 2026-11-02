import test from 'node:test'
import assert from 'node:assert/strict'
import { permittedNavigation } from '../src/config/navigation.js'

test('personal security remains available while payroll needs effective server permissions', async () => {
  const pages=permittedNavigation(['dashboard.view','inventory.view','deployment.view'],false,'admin').flatMap(g=>g.items.map(i=>i.id))
  const limited=permittedNavigation(['dashboard.view','tasks.view','analytics.view'],false,'admin',['core','inventory']).flatMap(g=>g.items.map(i=>i.id));assert.ok(!limited.includes('intelligence-assistant'));assert.ok(!limited.includes('automation-studio'));
  assert.ok(pages.includes('security'));assert.ok(pages.includes('system-health'));assert.ok(!pages.includes('payroll'))
  assert.ok(permittedNavigation(['dashboard.view'],false,'staff').flatMap(g=>g.items).some(i=>i.id==='security'))
})
test('passkey binary transport preserves zero and non-ASCII bytes for real browser credentials', async () => {
  globalThis.window={__AIMS_API_BASE__:null}
  const {browserOptions,credentialPayload,encodeBase64Url}=await import('../src/lib/passkeys.js')
  const raw=Uint8Array.from([0,255,128,64,1]),encoded=encodeBase64Url(raw)
  const options=browserOptions({challenge:encoded,user:{id:encoded,name:'Test'},excludeCredentials:[{id:encoded,type:'public-key'}]})
  assert.deepEqual(new Uint8Array(options.challenge),raw);assert.deepEqual(new Uint8Array(options.user.id),raw)
  const payload=credentialPayload({rawId:raw,response:{clientDataJSON:raw,authenticatorData:raw,signature:raw,userHandle:raw}},'challenge-id')
  assert.equal(payload.rawId,encoded);assert.equal(payload.response.signature,encoded);assert.equal(payload.response.userHandle,encoded)
})
