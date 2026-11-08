import { workProfiles } from './workProfiles.js'
import { canOpenPage } from './pageAccess.js'

export const dailyStarts = {
 owner:'/accounting?tab=owner', sales:'/customers', purchasing:'/procurement',
 warehouse:'/warehouse-mobile', finance:'/accounting', hr:'/workforce', manager:'/action-center',
}
export function availableWorkProfiles(permissions, capabilities) {
 return Object.keys(dailyStarts).filter(id=>canOpenPage(dailyStarts[id],permissions,capabilities))
}
export function suggestedWorkProfile(role, permissions) {
 if(role==='admin')return 'owner'
 if(role==='accountant')return 'finance'
 if(Object.hasOwn(dailyStarts,role))return role
 if(['hr','payroll'].includes(role))return 'hr'
 if(permissions.includes('accounting.reports.view')&&permissions.includes('accounting.integrity.view'))return 'finance'
 if(permissions.includes('procurement.view')&&!permissions.includes('daily_sales.manage'))return 'purchasing'
 if(permissions.includes('inventory.view')&&!permissions.includes('daily_sales.manage'))return 'warehouse'
 return role==='manager'?'manager':'sales'
}
// Presentation only. Both lists have already passed permissions and package gates.
export function focusedNavigation(groups, favorites, profile) {
 const daily=new Set(workProfiles[profile]?.pages||['dashboard','action-center','intelligence-assistant'])
 const advanced=new Set(['automation-studio','integrations','decision-learning','strategic-simulation','supply-optimizer','system-health','system-integrity','onboarding','pilot','security','activity-logs','users','warehouse-layout','warehouse-3d'])
 const primary=entry=>daily.has(entry.id)&&!advanced.has(entry.id)
 let focused=groups.map(g=>({...g,items:g.items.filter(primary)})).filter(g=>g.items.length)
 if(!['owner','manager'].includes(profile)){
  const overview=new Set(['dashboard','action-center','intelligence-assistant']),ordered=workProfiles[profile]?.pages||[]
  const entries=focused.flatMap(g=>g.items).toSorted((a,b)=>ordered.indexOf(a.id)-ordered.indexOf(b.id))
  focused=[{id:'overview',en:'My work',sq:'Puna ime',items:entries.filter(e=>overview.has(e.id))},{id:'daily-work',en:workProfiles[profile]?.en||'Daily work',sq:workProfiles[profile]?.sq||'Puna ditore',items:entries.filter(e=>!overview.has(e.id))}].filter(g=>g.items.length)
 }
 const occasional=groups.flatMap(g=>g.items).concat(favorites).filter(e=>!primary(e))
 return {groups:focused,favorites:favorites.filter(primary),occasional}
}
