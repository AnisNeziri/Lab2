import test from 'node:test'
import assert from 'node:assert/strict'
import { commandCatalog, commandLabelsSq, matchingCommands, permittedCommands } from '../src/config/commandCatalog.js'
import { canOpenPage, pagePermissions } from '../src/config/pageAccess.js'
import { navigationContext } from '../src/config/navigation.js'

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
    const route = command.path.split('?')[0].slice(1)
    const permissions = [command.permission, ...(command.anyPermission || []), ...(pagePermissions[route] || []), ...(navigationContext(command.path)?.entry.allPermissions || []), 'procurement.view', 'purchase_orders.view', 'transfers.view', 'accounting.reports.view', 'inventory.view', 'documents.view', 'fulfillment.view'].filter(Boolean)
    assert.equal(canOpenPage(command.path.slice(1), permissions), true, command.id)
  }
  assert.equal(matchingCommands('borxhet', ['debts.view'], 'sq')[0].id, 'debts')
  assert.equal(canOpenPage('accounting', ['debts.view']), false)
  assert.equal(permittedCommands(['purchase_orders.receive']).length, 0)
  assert.equal(permittedCommands(['analytics.view']).some(command => command.id === 'supply-optimizer'), false)
})
