import test from 'node:test'
import assert from 'node:assert/strict'
import { navigationGroups, navigationContext, navigationKey, permittedNavigation } from '../src/config/navigation.js'
import { pagePermissions, canOpenPage } from '../src/config/pageAccess.js'
import { matchingCommands } from '../src/config/commandCatalog.js'

test('workflow navigation preserves all protected workspaces without duplicate entries', () => {
  const ids = navigationGroups.flatMap(group => group.items.map(entry => entry.id))
  assert.equal(ids.length, new Set(ids).size)
  for (const page of Object.keys(pagePermissions)) assert.ok(ids.includes(page), page)
  assert.equal(navigationContext('/suppliers').group.id, 'purchasing')
  assert.equal(navigationContext('/daily-sales').group.id, 'sales')
  assert.equal(navigationContext('/customer-debts').group.id, 'sales')
  assert.equal(navigationContext('/action-center').group.id, 'overview')
  assert.equal(navigationContext('/automation-studio').group.id, 'operations')
  const intelligence = navigationGroups.find(group => group.id === 'intelligence').items.map(entry => entry.id)
  assert.ok(intelligence.indexOf('inventory-planning') < intelligence.indexOf('decision-center'))
  assert.ok(intelligence.indexOf('decision-center') < intelligence.indexOf('supply-optimizer'))
})

test('deep views select the right navigation item and inherit route permissions', () => {
  assert.equal(navigationKey('/inventory-intelligence?view=planning&product=7'), 'inventory-planning')
  assert.equal(navigationKey('/inventory-intelligence?view=decisions'), 'decision-center')
  assert.equal(navigationKey('/control-tower/11'), 'control-tower')
  assert.equal(canOpenPage('/control-tower/11?shipment=11', []), false)
  assert.equal(canOpenPage('/inventory-intelligence?view=planning', []), false)
  assert.equal(canOpenPage('/products?product=4', ['inventory.view']), true)
})

test('navigation honors permissions, 3D setting and platform-admin separation', () => {
  const admin = [...new Set(Object.values(pagePermissions).flat())]
  const disabled = permittedNavigation(admin, false, 'admin').flatMap(group => group.items)
  assert.equal(disabled.some(entry => entry.requires3d), false)
  assert.ok(permittedNavigation(admin, true, 'admin').flatMap(group => group.items).some(entry => entry.id === 'warehouse-3d'))
  const staff = permittedNavigation(['dashboard.view', 'inventory.view'], false, 'staff').flatMap(group => group.items)
  assert.equal(staff.some(entry => entry.id === 'users' || entry.id === 'procurement' || entry.id === 'inventory-planning'), false)
  assert.deepEqual(permittedNavigation([], false, 'superadmin').flatMap(group => group.items.map(entry => entry.id)), ['superadmin'])
  assert.equal(permittedNavigation(admin, true, 'manager').flatMap(group => group.items).some(entry => entry.id === 'users'),false)
})

test('empty search prioritizes everyday work, with bilingual permission-aware actions', () => {
  assert.equal(matchingCommands('', ['inventory.view', 'analytics.view'])[0].id, 'products')
  assert.equal(matchingCommands('shto produkt', ['products.manage'], 'sq')[0].id, 'add-product')
  assert.equal(matchingCommands('shto produkt', ['inventory.view'], 'sq').length, 0)
})
