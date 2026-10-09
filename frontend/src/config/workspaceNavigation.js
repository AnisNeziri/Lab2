import { permittedNavigation } from './navigation.js'
export const protectedNavigation = ['dashboard','action-center']
export function normalizeNavigation(value) {
  if (value && ![0,1].includes(value.version??0)) value=null
  const strings = rows => Array.isArray(rows)?[...new Set(rows.filter(v=>typeof v==='string'&&v.length<=80))]:[]
  return {version:1,hidden:strings(value?.hidden).filter(id=>!protectedNavigation.includes(id)),
    favorites:strings(value?.favorites).slice(0,6),order:Object.fromEntries(Object.entries(value?.order||{}).filter(([k,v])=>k.length<=40&&Array.isArray(v)).map(([k,v])=>[k,strings(v)]))}
}
export function defaultNavigation(role) {
  return normalizeNavigation({hidden:role==='staff'?['decision-learning','automation-studio','analytics','supply-optimizer','strategic-simulation','financial-intelligence']:[]})
}
export function personalizedNavigation(permissions, enable3d, role, value) {
  const prefs=normalizeNavigation(value), permitted=permittedNavigation(permissions,enable3d,role)
  const all=permitted.flatMap(g=>g.items), favorites=prefs.favorites.map(id=>all.find(i=>i.id===id)).filter(i=>i&&!prefs.hidden.includes(i.id))
  const groups=permitted.map(g=>{
    const order=prefs.order[g.id]||[],items=g.items.filter(i=>!prefs.hidden.includes(i.id)&&!favorites.some(f=>f.id===i.id))
    return {...g,items:items.toSorted((a,b)=>(order.includes(a.id)?order.indexOf(a.id):100+g.items.indexOf(a))-(order.includes(b.id)?order.indexOf(b.id):100+g.items.indexOf(b)))}
  }).filter(g=>g.items.length)
  return {groups,favorites}
}
