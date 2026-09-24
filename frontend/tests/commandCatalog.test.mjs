import test from 'node:test'
import assert from 'node:assert/strict'
import { commandCatalog, commandLabelsSq, matchingCommands, permittedCommands } from '../src/config/commandCatalog.js'
import { canOpenPage } from '../src/config/pageAccess.js'

test('command IDs and paths are unique and directly navigable', () => {
  assert.equal(new Set(commandCatalog.map((item) => item.id)).size, commandCatalog.length)
  assert.equal(new Set(commandCatalog.map((item) => item.path)).size, commandCatalog.length)
  assert.ok(commandCatalog.every((item) => item.path.startsWith('/') && item.label && (item.permission || item.anyPermission)))
})

test('commands are permission-aware and searchable', () => {
  const staff = permittedCommands(['dashboard.view', 'debts.view'])
  assert.deepEqual(staff.map((item) => item.id), ['dashboard', 'debts'])
  assert.equal(matchingCommands('credit', ['debts.view'])[0].id, 'debts')
  assert.equal(matchingCommands('accounting', ['debts.view']).length, 0)
})

test('every command has Albanian copy and a permitted destination', () => {
  for (const command of commandCatalog) {
    assert.ok(commandLabelsSq[command.id], command.id)
    const permissions = [command.permission, ...(command.anyPermission || []), 'procurement.view', 'purchase_orders.view', 'transfers.view', 'accounting.reports.view', 'inventory.view', 'documents.view', 'fulfillment.view'].filter(Boolean)
    assert.equal(canOpenPage(command.path.slice(1), permissions), true, command.id)
  }
  assert.equal(matchingCommands('borxhet', ['debts.view'], 'sq')[0].id, 'debts')
  assert.equal(canOpenPage('accounting', ['debts.view']), false)
  assert.equal(permittedCommands(['purchase_orders.receive']).length, 0)
})
