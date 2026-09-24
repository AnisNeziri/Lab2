// The same route-level permissions drive navigation and direct URL access.
export const pagePermissions = {
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
  accounting: ['accounting.reports.view'],
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

export function canOpenPage(page, permissions = []) {
  const required = pagePermissions[page.split('?')[0]]
  return !required || required.some((permission) => permissions.includes(permission))
}
