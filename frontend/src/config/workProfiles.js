import { normalizeDashboard, widgetById } from './dashboardWidgets.js'
import { permittedNavigation } from './navigation.js'
import { normalizeNavigation, protectedNavigation } from './workspaceNavigation.js'
// Layout presets never grant permissions, and must be explicitly saved.
export const workProfiles = {
 owner:{en:'Owner / Admin',sq:'Pronari / Administratori',widgets:['action-center','sales-trend','receivables','low-stock','open-purchases','shipments','cash-outlook','recent-orders','quick-actions'],pages:['dashboard','action-center','order-hub','customer-debts','finance','purchase-orders','shipments/my-shipments','intelligence-assistant','inventory-intelligence','decision-center','products','reports','users']},
 manager:{en:'Manager',sq:'Menaxheri',widgets:['action-center','orders-overview','low-stock','sales-trend','open-purchases','shipments','recent-orders','quick-actions'],pages:['dashboard','action-center','order-hub','products','warehouse-operations','customer-debts','purchase-orders','shipments/my-shipments','inventory-intelligence','inventory-planning','decision-center','intelligence-assistant','reports']},
 sales:{en:'Sales',sq:'Shitjet',widgets:['quick-actions','today-sales','orders-overview','low-stock','receivables','recent-orders','action-center'],pages:['dashboard','action-center','products','order-hub','daily-sales','customer-debts','warehouse-operations','intelligence-assistant']},
 warehouse:{en:'Warehouse',sq:'Depoja',widgets:['action-center','low-stock','warehouse-stock','open-purchases','shipments','stock-movements','quick-actions'],pages:['dashboard','action-center','warehouse-mobile','warehouse-operations','fulfillment','products','stock','operations-center','purchase-orders','shipments/my-shipments','intelligence-assistant']},
 purchasing:{en:'Purchasing',sq:'Blerjet',widgets:['action-center','low-stock','purchase-requests','open-purchases','shipments','supplier-reliability','quick-actions'],pages:['dashboard','action-center','inventory-planning','procurement','purchase-orders','suppliers','shipments/my-shipments','control-tower','products','documents','intelligence-assistant']},
 finance:{en:'Finance',sq:'Financat',widgets:['action-center','receivables','cash-outlook','sales-trend','open-purchases','quick-actions'],pages:['dashboard','action-center','finance','customer-debts','invoices','money-accounts','accounting','financial-intelligence','daily-sales','purchase-orders','suppliers','documents','intelligence-assistant','reports']},
}
export function profileDashboard(profile, permissions) {
 return normalizeDashboard({version:1,widgets:(workProfiles[profile]?.widgets||[]).map(id=>({id,size:widgetById[id].defaultSize,settings:widgetById[id].settings}))},permissions)
}
export function profileNavigation(profile, permissions, role, enable3d) {
 const desired=workProfiles[profile]?.pages||[],groups=permittedNavigation(permissions,enable3d,role),items=groups.flatMap(g=>g.items)
 return normalizeNavigation({hidden:items.filter(i=>!desired.includes(i.id)&&!protectedNavigation.includes(i.id)).map(i=>i.id),favorites:desired.filter(id=>!protectedNavigation.includes(id)&&items.some(i=>i.id===id)).slice(0,6),order:Object.fromEntries(groups.map(g=>[g.id,g.items.toSorted((a,b)=>(desired.includes(a.id)?desired.indexOf(a.id):100)-(desired.includes(b.id)?desired.indexOf(b.id):100)).map(i=>i.id)]))})
}
