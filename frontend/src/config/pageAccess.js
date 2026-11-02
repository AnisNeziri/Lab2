// The same route-level permissions drive navigation and direct URL access.
import { simulationPermissions } from '../pages/strategicSimulationPresentation.js'
export const pagePermissions = {
  customers:['customers.manage'],
  workforce:['employees.view'],payroll:['payroll.view'],'my-workforce':['workforce.self'],
  onboarding:['onboarding.view'],
  'strategic-simulation':simulationPermissions,
  'supply-optimizer':['analytics.finance'],
  'decision-learning':['analytics.view'],
  'customer-sales-intelligence':['analytics.view'],
  'financial-intelligence':['analytics.finance'],
  analytics:['analytics.view'],
  'inventory-intelligence':['analytics.view'],
  'action-center':['tasks.view'],
  'automation-studio':['automations.view'],
  dashboard: ['dashboard.view'],
  products: ['products.manage', 'inventory.view'],
  stock: ['inventory.view'],
  reports: ['reports.view'],
  'purchase-orders': ['purchase_orders.view'],
  procurement: ['procurement.view'],
  fulfillment: ['fulfillment.view'],
  'order-hub': ['fulfillment.view'],
  documents: ['documents.view'],
  quality: ['quality.view'],
  'daily-sales': ['daily_sales.manage'],
  'customer-debts': ['debts.view'],
  invoices: ['invoices.manage'],
  finance: ['finance.view'],
  'money-accounts': ['financial_accounts.view'],
  tax:['tax.view'], fiscalization:['fiscalization.view'],
  accounting: ['accounting.reports.view'],
  integrations:['integrations.view'],
  'system-health':['deployment.view'], security:[],
  'system-integrity': ['system_integrity.view'],
  'warehouse-operations': ['transfers.view'],
  'warehouse-mobile': ['warehouse_mobile.use'],
  'operations-center': ['inventory.view'],
  'warehouse-layout': ['inventory.view'],
  'warehouse-3d': ['inventory.view'],
  'control-tower': ['control_tower.view'],
  'shipments/global-map': ['shipments.view'],
  'shipments/my-shipments': ['shipments.view'],
  'shipments/alerts': ['shipments.view'],
  users: ['users.manage'],
  'activity-logs': ['activity.view'],
  cms: ['cms.manage'],
}

export const pageCapabilities = Object.fromEntries(Object.entries({
  inventory:['products','stock','categories','quality','operations-center','reports'],
  wms:['warehouse-operations','warehouse-mobile','warehouse-layout','warehouse-3d'],
  sales:['daily-sales','invoices','fulfillment','order-hub'],crm:['customer-debts','customers'],
  procurement:['purchase-orders','procurement','suppliers'],shipments:['control-tower','shipments/global-map','shipments/my-shipments','shipments/alerts'],
  accounting:['accounting','finance','money-accounts'],tax:['tax','fiscalization'],
  workforce:['workforce','my-workforce'],payroll:['payroll'],automation:['automation-studio'],
  integrations:['integrations'],analytics:['analytics'],
  intelligence:['intelligence-assistant','inventory-intelligence','inventory-planning','decision-center','supply-optimizer','strategic-simulation','financial-intelligence','customer-sales-intelligence','decision-learning'],
}).flatMap(([capability,pages])=>pages.map(page=>[page,capability])))

export function canOpenPage(page, permissions = [], capabilities = null) {
  const pathname = String(page).replace(/^\/+|\/+$/g, '').split(/[?#]/)[0]
  const key = pathname.startsWith('control-tower/') ? 'control-tower' : pathname
  if (Array.isArray(capabilities) && pageCapabilities[key] && !capabilities.includes(pageCapabilities[key])) return false
  const required = pagePermissions[key]
  if (key === 'security') return true
  if (key === 'strategic-simulation') return simulationPermissions.every(p=>permissions.includes(p))
  if (key === 'supply-optimizer') return ['analytics.view','analytics.finance','inventory.view','procurement.view','finance.view','financial_accounts.view'].every(p=>permissions.includes(p))
  if (key === 'customer-sales-intelligence') return ['analytics.view', 'customers.manage', 'daily_sales.manage'].every(p => permissions.includes(p))
  if (key === 'financial-intelligence') return ['analytics.finance', 'finance.view', 'financial_accounts.view'].every(p => permissions.includes(p))
  return !required || required.some((permission) => permissions.includes(permission))
}
