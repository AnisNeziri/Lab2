import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { en } from '../src/locales/en.js'
import { sq } from '../src/locales/sq.js'
import { commandCatalog } from '../src/config/commandCatalog.js'
import { uiCopySq } from '../src/locales/uiCopy.js'

test('literal translation keys used in workspaces exist in English and Albanian', () => {
  const root=path.resolve('src'), missing=[]
  for(const file of fs.readdirSync(root,{recursive:true}).filter(file=>/\.(jsx|js)$/.test(file)&&!file.startsWith('locales'))) {
    const source=fs.readFileSync(path.join(root,file),'utf8')
    for(const match of source.matchAll(/\bt\(['"]([\w]+\.[\w.]+)['"]/g)) {
      if(!en[match[1]]||!sq[match[1]])missing.push(`${file}: ${match[1]}`)
    }
    for(const match of source.matchAll(/\btx\(['"]([^'"]+)['"]/g)) {
      if(!uiCopySq[match[1]])missing.push(`${file}: ${match[1]}`)
    }
  }
  assert.deepEqual(missing,[])
})

test('command destinations match declared routes and supported operations tabs', () => {
  const app=fs.readFileSync('src/App.jsx','utf8'), routes=[...app.matchAll(/path="([^"]+)"/g)].map(match=>match[1])
  assert.equal(new Set(routes).size,routes.length,'Duplicate frontend routes')
  for(const command of commandCatalog) {
    const url=new URL(command.path,'http://aims.local')
    assert.ok(routes.includes(url.pathname),command.path)
    if(url.pathname==='/operations-center')assert.ok(['locator','counts','expiry','replenish','replenishment','catalogue','landed','landed-costs','returns','matching'].includes(url.searchParams.get('tab')))
  }
})
