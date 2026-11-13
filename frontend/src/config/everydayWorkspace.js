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
// Keep each permitted workspace in its functional group and surface daily work first.
export function focusedNavigation(groups, favorites, profile) {
 const daily=new Set(workProfiles[profile]?.pages||['dashboard','action-center','intelligence-assistant'])
 const advanced=new Set(['intelligence-assistant','automation-studio','integrations','decision-learning','strategic-simulation','supply-optimizer','system-health','system-integrity','onboarding','pilot','security','activity-logs','users','warehouse-layout','warehouse-3d'])
 const primary=entry=>daily.has(entry.id)&&!advanced.has(entry.id)
 const focused=groups.map(group=>({...group,items:group.items.toSorted((a,b)=>Number(primary(b))-Number(primary(a)))}))
 return {groups:focused.toSorted((a,b)=>Number(b.items.some(primary))-Number(a.items.some(primary))),favorites}
}
