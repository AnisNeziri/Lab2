const test=require('node:test'),assert=require('node:assert/strict'),crypto=require('node:crypto')
const {verifyLicenceDocument}=require('../src/license.cjs')
test('a signed expired desktop license preserves records and exposes restricted-write policy; tampering still fails',()=>{
 const {privateKey,publicKey}=crypto.generateKeyPairSync('rsa',{modulusLength:2048})
 const payload=Buffer.from(JSON.stringify({schema:1,product:'AIMS',machine_id:'synthetic-installation',license_id:'synthetic-license',expires_at:'2020-01-01T00:00:00Z'}))
 const document={payload:payload.toString('base64url'),signature:crypto.sign('RSA-SHA256',payload,privateKey).toString('base64')}
 const result=verifyLicenceDocument(document,publicKey,'synthetic-installation');assert.equal(result.valid,true);assert.equal(result.status,'expired');assert.equal(result.writeAllowed,false)
 assert.throws(()=>verifyLicenceDocument({...document,payload:Buffer.from('{}').toString('base64url')},publicKey,'synthetic-installation'),/signature/)
 assert.throws(()=>verifyLicenceDocument(document,publicKey,'foreign-installation'),/another computer/)
})
