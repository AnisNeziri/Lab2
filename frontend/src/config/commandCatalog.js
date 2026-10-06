import { canOpenPage } from './pageAccess.js'

export const commandCatalog = [
  { id:'supply-optimizer', label:'Open Supply Optimizer', keywords:'supply optimize purchasing plan constraints furnizim optimizim', path:'/supply-optimizer', permission:'analytics.view' },
  { id:'inventory-planning', label:'Open Inventory Planning', keywords:'replenish plan quantity buy inventory planifikim blerje', path:'/inventory-intelligence?view=planning', permission:'analytics.view' },
  { id:'decision-center', label:'Review Decisions', keywords:'decisions recommended actions attention vendime rekomandime', path:'/inventory-intelligence?view=decisions', permission:'analytics.view' },
  { id: 'customer-sales-intelligence', label: 'Open Customer & Sales Intelligence', keywords: 'customer sales reorder affinity klient shitje riporosi', path: '/customer-sales-intelligence', permission: 'analytics.view' },
  { id: 'financial-intelligence', label: 'Open Financial Intelligence', keywords: 'cash forecast receivables working capital financat para', path: '/financial-intelligence', permission: 'analytics.finance' },
  { id: 'add-product', label: 'Add Product', keywords: 'new register inventory', path: '/products?new=1', permission: 'products.manage' },
  { id: 'daily-sales', label: 'Record Daily Sale', keywords: 'sell sale book payment', path: '/daily-sales', permission: 'daily_sales.manage' },
  { id: 'stock-locator', label: 'Find Stock by Warehouse', keywords: 'locate warehouse bin availability', path: '/warehouse-operations', permission: 'transfers.view' },
  { id: 'finance', label: 'Open Finance Center', keywords: 'expenses invoices receivables money', path: '/finance', permission: 'finance.view' },
  {id:'inventory-intelligence',label:'Inventory Intelligence',keywords:'forecast demand stockout replenish model',path:'/inventory-intelligence',permission:'analytics.view'},
  {id:'analytics',label:'Open Analytics',keywords:'metrics data intelligence history',path:'/analytics',permission:'analytics.view'},
  {id:'action-center',label:'Open Action Center',keywords:'my tasks attention',path:'/action-center',permission:'tasks.view'},
  {id:'automation-studio',label:'Open Automation Studio',keywords:'automation rules templates',path:'/automation-studio',permission:'automations.view'},
  {id:'my-tasks',label:'My Tasks',keywords:'assigned work',path:'/action-center?view=mine',permission:'tasks.view'},
  {id:'create-automation',label:'Create Automation',keywords:'new rule',path:'/automation-studio?new=1',permission:'automations.create',anyPermission:['automations.view']},
  {id:'failed-automations',label:'Failed Automations',keywords:'failed rules issues',path:'/action-center?area=system',permission:'automations.executions.view',anyPermission:['tasks.view']},
  {id:'order-hub',label:'Open Orders',keywords:'orders online website order hub',path:'/order-hub',permission:'fulfillment.view'},
  {id:'hub-new',label:'Create Order',keywords:'new manual online order',path:'/order-hub?new=1',permission:'fulfillment.manage'},
  {id:'hub-attention',label:'Orders Needing Attention',keywords:'review blocked issue',path:'/order-hub?view=attention',permission:'fulfillment.view'},
  {id:'hub-backorders',label:'Open Backorders',keywords:'incoming stock shortage',path:'/order-hub?view=backorders',permission:'fulfillment.view'},
  {id:'documents',label:'Open Document Center',keywords:'documents evidence files',path:'/documents',permission:'documents.view'},
  {id:'upload-document',label:'Upload Document',keywords:'attachment evidence file',path:'/documents?upload=1',permission:'documents.upload'},
  {id:'expiring-documents',label:'Expiring Documents',keywords:'certificate expiry',path:'/documents?expiry=soon',permission:'documents.view'},
  {id:'review-documents',label:'Documents Awaiting Review',keywords:'approval review',path:'/documents?status=under_review',permission:'documents.review'},
  { id:'sales-order', label:'Create Warehouse Sales Order', keywords:'customer demand fulfillment direct sales order', path:'/fulfillment?new=1', permission:'fulfillment.manage' },
  { id:'ready-pick', label:'Open Orders Ready to Pick', keywords:'warehouse pick', path:'/fulfillment?status=allocated', permission:'fulfillment.view' },
  { id:'dispatch-queue', label:'Open Dispatch Queue', keywords:'packed deliver', path:'/fulfillment?status=packed', permission:'fulfillment.view' },
  { id:'customer-returns', label:'Open Customer Returns', keywords:'rma return', path:'/fulfillment?view=returns', permission:'fulfillment.view' },
  { id:'late-deliveries', label:'Open Late Deliveries', keywords:'overdue customer order', path:'/fulfillment?view=late', permission:'fulfillment.view' },
  { id: 'dashboard', label: 'Open Dashboard', keywords: 'overview home', path: '/dashboard', permission: 'dashboard.view' },
  { id: 'products', label: 'Search Products', keywords: 'sku barcode inventory', path: '/products', anyPermission: ['products.manage', 'inventory.view'] },
  { id: 'low-stock', label: 'Open Low Stock', keywords: 'replenishment shortage reorder', path: '/operations-center?tab=replenishment', permission: 'replenishment.view' },
  { id: 'purchase-order', label: 'Open Purchase Orders', keywords: 'po purchasing supplier order receive goods receipt incoming warehouse', path: '/purchase-orders', permission: 'purchase_orders.view' },
  { id: 'move-stock', label: 'Move Stock', keywords: 'transfer warehouse bin', path: '/warehouse-operations?tab=transfers', permission: 'transfers.view' },
  { id: 'approvals', label: 'Open Pending Approvals', keywords: 'approve reject request', path: '/procurement?view=approvals', permission: 'approvals.view' },
  { id: 'debts', label: 'Open Customer Debts', keywords: 'credit receivables borxhet', path: '/customer-debts', permission: 'debts.view' },
  { id: 'control-tower', label: 'Open Control Tower', keywords: 'shipments imports exceptions', path: '/control-tower', permission: 'control_tower.view' },
  { id: 'accounting-exceptions', label: 'Open Accounting Exceptions', keywords: 'integrity journal reconciliation', path: '/accounting?tab=integrity', permission: 'accounting.integrity.view' },
  { id: 'system-integrity', label: 'Open System Integrity', keywords: 'health diagnostics jobs integrations', path: '/system-integrity', permission: 'system_integrity.view' },
]

export function permittedCommands(permissions = []) {
  const allowed = new Set(permissions)
  return commandCatalog.filter((command) => (
    (!command.permission || allowed.has(command.permission))
    && (!command.anyPermission || command.anyPermission.some((permission) => allowed.has(permission)))
    && canOpenPage(command.path.slice(1), permissions)
  ))
}

export const commandLabelsSq = {
  'supply-optimizer':'Hap optimizuesin e furnizimit', 'inventory-planning':'Hap planifikimin e inventarit', 'decision-center':'Rishiko vendimet',
  'financial-intelligence': 'Hap inteligjencën financiare',
  'customer-sales-intelligence': 'Hap inteligjencën e klientëve dhe shitjeve',
  'add-product': 'Shto produkt', 'daily-sales': 'Regjistro shitje ditore', 'stock-locator': 'Gjej stokun sipas depos', finance: 'Hap qendrën financiare',
  analytics:'Hap analitikën',
  'inventory-intelligence':'Inteligjenca e inventarit',
  'action-center':'Hap qendrën e veprimeve','automation-studio':'Hap studion e automatizimit','my-tasks':'Detyrat e mia',
  'create-automation':'Krijo automatizim','failed-automations':'Automatizimet e dështuara',
  'order-hub':'Hap porositë','hub-new':'Krijo porosi','hub-attention':'Porositë që kërkojnë vëmendje','hub-backorders':'Porositë në pritje stoku',
  documents: 'Hap qendrën e dokumenteve', 'upload-document': 'Ngarko dokument', 'expiring-documents': 'Dokumentet pranë skadimit', 'review-documents': 'Dokumentet në pritje të shqyrtimit',
  'sales-order': 'Krijo porosi shitjeje për depo', 'ready-pick': 'Porositë gati për mbledhje', 'dispatch-queue': 'Hap radhën e dërgesave', 'customer-returns': 'Hap kthimet e klientëve', 'late-deliveries': 'Hap dorëzimet e vonuara',
  dashboard: 'Hap panelin kryesor', products: 'Kërko produkte', 'low-stock': 'Hap stokun e ulët', 'purchase-order': 'Hap porositë e blerjes',
  'move-stock': 'Transfero stokun', approvals: 'Hap miratimet në pritje', debts: 'Hap borxhet e klientëve', 'control-tower': 'Hap qendrën e kontrollit',
  'accounting-exceptions': 'Hap përjashtimet kontabël', 'system-integrity': 'Hap integritetin e sistemit',
}

export function matchingCommands(query, permissions = [], language = 'en') {
  const needle = query.trim().toLocaleLowerCase()
  const commands = permittedCommands(permissions).map((command) => ({ ...command, label: language === 'sq' ? commandLabelsSq[command.id] || command.label : command.label, keywords: `${command.keywords} ${command.label}` }))
  const common = ['products', 'add-product', 'daily-sales', 'order-hub', 'purchase-order', 'stock-locator', 'debts', 'finance', 'action-center', 'dashboard']
  if (!needle) return commands.toSorted((a, b) => (common.indexOf(a.id) < 0 ? 100 : common.indexOf(a.id)) - (common.indexOf(b.id) < 0 ? 100 : common.indexOf(b.id)))
  return commands.filter(command => `${command.label} ${command.keywords}`.toLocaleLowerCase().includes(needle)).toSorted((a, b) => Number(b.label.toLocaleLowerCase().startsWith(needle)) - Number(a.label.toLocaleLowerCase().startsWith(needle)))
}
