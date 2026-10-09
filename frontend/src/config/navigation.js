import { canOpenPage } from './pageAccess.js'
import { simulationPermissions } from '../pages/strategicSimulationPresentation.js'

const item = (id, label, icon, extra = {}) => ({ id, path: `/${id}`, label, icon, ...extra })
// Navigation is a workflow map, not a second permission system.
export const navigationGroups = [
  { id: 'overview', en: 'Workspace', sq: 'Hapësira e punës', items: [item('dashboard', 'nav.dashboard', 'LayoutDashboard'), item('action-center', 'nav.actionCenter', 'ClipboardList')] },
  { id: 'sales', en: 'Sales & customers', sq: 'Shitjet dhe klientët', items: [item('order-hub', 'nav.orderHub', 'Package'), item('daily-sales', 'nav.dailySales', 'ClipboardList'), item('customer-debts', 'nav.customerDebts', 'Users'), item('fulfillment', 'nav.fulfillment', 'Boxes')] },
  { id: 'inventory', en: 'Inventory & warehouses', sq: 'Inventari dhe depot', items: [item('products', 'nav.products', 'Package'), item('stock', 'nav.stock', 'TrendingUp'), item('warehouse-operations', 'nav.warehouseOperations', 'Warehouse'), item('warehouse-mobile', 'nav.mobileWarehouse', 'ScanLine'), item('operations-center', 'nav.operationsCenter', 'Boxes'), item('categories', 'nav.categories', 'FolderTree'), item('warehouse-3d', 'nav.warehouse3d', 'Box', { requires3d: true }), item('warehouse-layout', 'nav.warehouseLayout', 'LayoutGrid', { requires3d: true })] },
  { id: 'purchasing', en: 'Purchasing & suppliers', sq: 'Blerjet dhe furnitorët', items: [item('purchase-orders', 'nav.purchaseOrders', 'Truck'), item('procurement', 'nav.procurement', 'ClipboardList'), item('suppliers', 'nav.suppliers', 'Truck')] },
  { id: 'shipments', en: 'Shipments & logistics', sq: 'Dërgesat dhe logjistika', items: [item('shipments/my-shipments', 'nav.myShipments', 'Ship'), item('shipments/global-map', 'nav.globalMap', 'Globe'), item('shipments/alerts', 'nav.shipmentAlerts', 'Bell'), item('control-tower', 'nav.controlTower', 'Radar')] },
  { id: 'finance', en: 'Finance', sq: 'Financat', items: [item('finance', 'nav.financeCenter', 'Landmark'), item('invoices', 'nav.invoices', 'FileText'), item('money-accounts', 'nav.moneyAccounts', 'Landmark'), item('accounting', 'nav.accounting', 'BookOpen')] },
  { id: 'intelligence', en: 'Intelligence & reports', sq: 'Inteligjenca dhe raportet', items: [item('inventory-intelligence', 'nav.inventoryIntelligence', 'HeartPulse', { allPermissions: ['inventory.view'] }), item('inventory-planning', null, 'Boxes', { path: '/inventory-intelligence?view=planning', en: 'Inventory planning', sq: 'Planifikimi i inventarit', allPermissions: ['inventory.view'] }), item('decision-center', null, 'Radar', { path: '/inventory-intelligence?view=decisions', en: 'Decision Center', sq: 'Qendra e vendimeve', allPermissions: ['inventory.view'] }), item('analytics', 'nav.analytics', 'TrendingUp'), item('reports', 'nav.inventoryReports', 'FileText')] },
  { id: 'operations', en: 'Tools & operations', sq: 'Mjetet dhe operacionet', items: [item('quality', 'nav.quality', 'ShieldCheck'), item('documents', 'nav.documents', 'FileText'), item('automation-studio', 'nav.automationStudio', 'HeartPulse')] },
  { id: 'settings', en: 'Administration', sq: 'Administrimi', items: [item('users', 'nav.users', 'Users', { roles: ['admin'] }), item('activity-logs', 'nav.activityLogs', 'Activity', { roles: ['admin'] }), item('cms', 'nav.systemSettings', 'FileEdit', { roles: ['admin'] }), item('system-integrity', 'nav.systemIntegrity', 'HeartPulse')] },
]

// Finance evidence is intentionally not exposed to inventory-only roles.
navigationGroups.find(g => g.id === 'intelligence').items.splice(3, 0,
  item('customer-sales-intelligence', null, 'Users', { en: 'Customer & Sales', sq: 'Klientët dhe shitjet', allPermissions: ['analytics.view', 'customers.manage', 'daily_sales.manage'] }))
navigationGroups.find(g => g.id === 'intelligence').items.splice(3, 0,
  item('financial-intelligence', null, 'Landmark', { en: 'Financial Intelligence', sq: 'Inteligjenca financiare', allPermissions: ['analytics.finance', 'finance.view', 'financial_accounts.view'] }))
navigationGroups.find(g => g.id === 'intelligence').items.unshift(item('intelligence-assistant', null, 'Radar', {en:'Intelligence Assistant',sq:'Asistenti inteligjent'}))
navigationGroups.find(g => g.id === 'intelligence').items.push(item('decision-learning', null, 'Activity', {en:'Decision Learning',sq:'Mësimi i vendimeve'}))
navigationGroups.find(g => g.id === 'intelligence').items.splice(2,0,item('supply-optimizer',null,'Boxes',{en:'Supply Optimizer',sq:'Optimizuesi i furnizimit',allPermissions:['analytics.view','analytics.finance','inventory.view','procurement.view','finance.view','financial_accounts.view']}))

// Read evidence → plan → review decisions → coordinate supply → inspect outcomes.
navigationGroups.find(g => g.id === 'intelligence').items.push(item('strategic-simulation',null,'Radar',{en:'Strategic Simulation',sq:'Simulimi strategjik',allPermissions:simulationPermissions}))
const intelligenceOrder = ['intelligence-assistant','inventory-intelligence','inventory-planning','decision-center','supply-optimizer','strategic-simulation','customer-sales-intelligence','financial-intelligence','decision-learning','analytics','reports']
navigationGroups.find(g => g.id === 'intelligence').items.sort((a,b) => intelligenceOrder.indexOf(a.id) - intelligenceOrder.indexOf(b.id))

export function navigationKey(path) {
  const [pathname, search = ''] = path.replace(/^\//, '').split('?')
  if (pathname.startsWith('control-tower/')) return 'control-tower'
  if (pathname === 'inventory-intelligence') {
    const view = new URLSearchParams(search).get('view')
    if (view === 'planning') return 'inventory-planning'
    if (view === 'decisions') return 'decision-center'
  }
  return pathname || 'dashboard'
}

export function navigationContext(path) {
  const key = navigationKey(path)
  for (const group of navigationGroups) {
    const entry = group.items.find(entry => entry.id === key)
    if (entry) return { group, entry }
  }
  return null
}

export function permittedNavigation(permissions, enable3dMap, role) {
  if (role === 'superadmin') return [{ id: 'settings', en: 'Platform administration', sq: 'Administrimi i platformës', items: [item('superadmin', 'nav.platformAdmin', 'ShieldCheck')] }]
  return navigationGroups.map(group => ({ ...group, items: group.items.filter(entry =>
    (!entry.requires3d || enable3dMap) && (!entry.roles || entry.roles.includes(role)) && canOpenPage(entry.path, permissions)
    && (!entry.allPermissions || entry.allPermissions.every(permission => permissions.includes(permission)))
  ) })).filter(group => group.items.length)
}
