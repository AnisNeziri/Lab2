import test from 'node:test'
import assert from 'node:assert/strict'
import qrcode from '../src/vendor/qrcode.mjs'
import {canOpenPage} from '../src/config/pageAccess.js'
import {matchingCommands} from '../src/config/commandCatalog.js'
test('tax and fiscal workspaces enforce independent permissions in search and direct navigation',()=>{
 assert.equal(canOpenPage('/tax',[]),false);assert.equal(canOpenPage('/fiscalization',[]),false)
 assert.equal(canOpenPage('/tax',['tax.view']),true)
 assert.equal(matchingCommands('fiscalization',['tax.view']).some(c=>c.path==='/fiscalization'),false)
 assert.equal(matchingCommands('fiscalization',['fiscalization.view']).some(c=>c.path==='/fiscalization'),true)
})
test('receipt QR renderer supports the signed protobuf separator without a remote image service',()=>{
 const c=qrcode(0,'M');c.addData('CIfq6YIDSAE=|'+Buffer.alloc(64,8).toString('base64'));c.make()
 assert.ok(c.getModuleCount()>0);assert.ok(c.createDataURL(4,16).startsWith('data:image/gif;base64,'))
})
