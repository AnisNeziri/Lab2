import { canOpenPage } from './pageAccess.js'
import { simulationPermissions } from '../pages/strategicSimulationPresentation.js'

const widget = (id, en, sq, category, path, permissions, source, sizes = ['medium','large'], extra = {}) => ({
  id, en, sq, category, path, permissions, source, component: 'BusinessWidget', sizes,
  defaultSize: sizes[0], minHeight: sizes.includes('small') ? 140 : 240,
  minColumns: sizes.includes('small') ? 3 : 6, maxColumns: sizes.includes('large') ? 12 : 6,
  settings: {}, ...extra,
})
export const widgetCategories = {
  overview:['Overview','Përmbledhja'], sales:['Sales','Shitjet'], orders:['Orders','Porositë'],
  inventory:['Inventory','Inventari'], customers:['Customers','Klientët'], suppliers:['Suppliers','Furnitorët'],
  purchasing:['Purchasing','Blerjet'], shipments:['Shipments','Dërgesat'], finance:['Finance','Financat'],
  intelligence:['Intelligence','Inteligjenca'], tasks:['Tasks','Detyrat'],
}
const inventory = ['dashboard.view','inventory.view'], sales = ['dashboard.view','daily_sales.manage']
const financial = ['analytics.finance','finance.view','financial_accounts.view']
const commercial = ['analytics.view','customers.manage','daily_sales.manage']
export const widgetCatalog = [
  widget('today-sales',"Today's sales",'Shitjet e sotme','sales','/daily-sales',sales,'/daily-sales/summary',['small','medium']),
  widget('sales-trend','Sales trend','Ecuria e shitjeve','sales','/daily-sales',sales,'sales-chart',['medium','large'],{settings:{period:'month'}}),
  widget('orders-overview','Orders overview','Përmbledhja e porosive','orders','/order-hub',['fulfillment.view'],'/order-hub/overview'),
  widget('recent-orders','Recent orders','Porositë e fundit','orders','/order-hub',['fulfillment.view'],'/order-hub?per_page=5'),
  widget('inventory-value','Inventory value','Vlera e inventarit','inventory','/products',[...inventory,'analytics.finance'],'/dashboard',['small','medium']),
  widget('stock-turnover','Inventory turnover rate','Qarkullimi i inventarit','inventory','/stock',inventory,'/dashboard',['small','medium']),
  widget('warehouse-sections','Warehouse sections','Seksionet e depos','inventory','/warehouse-layout',inventory,'/warehouse/sections/distribution',['small','medium']),
  widget('stock-alert-count','Flagged stock products','Produktet me paralajmërim stoku','inventory','/stock',inventory,'/dashboard',['small','medium']),
  widget('product-count','Products in inventory','Produktet në inventar','inventory','/products',inventory,'/dashboard',['small','medium']),
  widget('low-stock','Low stock','Stoku i ulët','inventory','/stock',inventory,'/dashboard/low-stock-alerts'),
  widget('stock-movements','Stock movements','Lëvizjet e stokut','inventory','/stock',inventory,'/dashboard'),
  widget('category-activity','Recent stock-out activity by category','Daljet e fundit sipas kategorisë','inventory','/stock',inventory,'/dashboard'),
  widget('category-stock','Inventory by category','Inventari sipas kategorisë','inventory','/products',[...inventory,'analytics.finance'],'/dashboard'),
  widget('warehouse-stock','Stock by warehouse section','Stoku sipas seksionit të depos','inventory','/warehouse-operations',['inventory.view','transfers.view'],'/warehouse/sections/distribution'),
  widget('receivables','Outstanding customer debt','Borxhet e papaguara të klientëve','customers','/customer-debts',['debts.view'],'/customers/debts/summary',['small','medium']),
  widget('customer-opportunities','Customer reorder opportunities','Mundësitë e riporositjes së klientëve','customers','/customer-sales-intelligence?view=opportunities',commercial,'/customer-sales-intelligence'),
  widget('supplier-reliability','Supplier reliability','Besueshmëria e furnitorit','suppliers','/suppliers',['supplier_performance.view'],'supplier-scorecard',['medium','large'],{settings:{supplier_id:''}}),
  widget('open-purchases','Recent purchase orders','Porositë e fundit të blerjes','purchasing','/purchase-orders',['purchase_orders.view'],'/purchase-orders?per_page=5'),
  widget('purchase-requests','Purchase requests','Kërkesat për blerje','purchasing','/procurement',['procurement.view'],'/purchase-requests?per_page=5'),
  widget('shipments','Active shipments','Dërgesat aktive','shipments','/shipments/my-shipments',['shipments.view'],'/shipments'),
  widget('shipment-risk','Shipment risk','Rreziku i dërgesave','shipments','/control-tower',['shipments.view','control_tower.view'],'/shipment-intelligence'),
  widget('cash-outlook','Cash outlook','Perspektiva e parasë','finance','/financial-intelligence',financial,'/financial-intelligence?horizon=30'),
  widget('action-center','Action Center','Qendra e veprimeve','tasks','/action-center',['tasks.view'],'/action-center?view=mine&summary_only=1'),
  widget('automation-status','Automation status','Gjendja e automatizimit','tasks','/automation-studio',['automations.view'],'/automations'),
  widget('forecast-health','Forecast health','Gjendja e parashikimeve','intelligence','/inventory-intelligence',['analytics.view','inventory.view'],'/analytics/intelligence'),
  widget('supply-optimizer','Supply Optimizer summary','Përmbledhja e optimizuesit','intelligence','/supply-optimizer',['analytics.view','analytics.finance','inventory.view','procurement.view','finance.view','financial_accounts.view'],'/supply-optimizer'),
  widget('strategic-simulation','Strategic Simulation summary','Përmbledhja e simulimit strategjik','intelligence','/strategic-simulation',simulationPermissions,'/strategic-simulation'),
  widget('activity','Recent inventory activity','Aktiviteti i fundit i inventarit','overview','/stock',inventory,'/dashboard/activity-feed?limit=8'),
  widget('quick-actions','Common actions','Veprimet e zakonshme','overview','/dashboard',['dashboard.view'],null),
]
export const widgetById = Object.fromEntries(widgetCatalog.map(w => [w.id,w]))
export const allowedWidget = (w, permissions) => Boolean(w && w.permissions.every(p => permissions.includes(p)) && canOpenPage(w.path,permissions))
export const availableWidgets = permissions => widgetCatalog.filter(w => allowedWidget(w,permissions))

export function defaultDashboard(permissions, role = 'staff') {
  const ids = role === 'staff'
    ? ['today-sales','product-count','receivables','low-stock','orders-overview','warehouse-stock','action-center','shipments','purchase-requests','open-purchases','cash-outlook','stock-movements','quick-actions']
    : ['inventory-value','stock-turnover','warehouse-sections','stock-alert-count','stock-movements','category-activity','warehouse-stock','sales-trend','quick-actions','activity','low-stock','today-sales','product-count','receivables','shipments','action-center','customer-opportunities','orders-overview']
  return normalizeDashboard({version:1,widgets:ids.map(id => ({id,size:widgetById[id].defaultSize,settings:widgetById[id].settings}))},permissions)
}
export function normalizeDashboard(layout, permissions) {
  if (!layout || ![0,1].includes(layout.version ?? 0) || !Array.isArray(layout.widgets)) return null
  const seen = new Set()
  const widgets = layout.widgets.slice(0,60).filter(w => {
    if (!w || !allowedWidget(widgetById[w.id],permissions) || seen.has(w.id)) return false
    seen.add(w.id); return true
  }).map((w,position) => {
    const def = widgetById[w.id], settings = {...def.settings}
    if ('period' in settings && ['week','month','year'].includes(w.settings?.period)) settings.period=w.settings.period
    if ('supplier_id' in settings && /^\d+$/.test(String(w.settings?.supplier_id))) settings.supplier_id=String(w.settings.supplier_id)
    return {id:w.id,position,size:def.sizes.includes(w.size)?w.size:def.defaultSize,settings}
  })
  return {version:1,widgets}
}
export function moveWidget(layout, from, to) {
  if(from<0 || to<0 || from>=layout.widgets.length || to>=layout.widgets.length) return layout
  const widgets=[...layout.widgets], [row]=widgets.splice(from,1);widgets.splice(to,0,row)
  return {...layout,widgets:widgets.map((w,position)=>({...w,position}))}
}
