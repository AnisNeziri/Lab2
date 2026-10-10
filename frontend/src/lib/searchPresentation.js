const priority=['products','customers','suppliers','purchase_orders','order_hub','sales_orders','shipments','warehouses','documents','purchase_requests','rfqs','tasks','decisions','stock_movements']
export function searchSections(results,query,permitted=()=>true) {
 const term=String(query||'').trim().toLocaleLowerCase()
 const score=item=>{const title=String(item.title||'').toLocaleLowerCase();return title===term?3:title.startsWith(term)?2:title.includes(term)?1:0}
 const rank=type=>priority.includes(type)?priority.indexOf(type):priority.length
 return Object.entries(results||{}).map(([type,items])=>[type,Array.isArray(items)?items.filter(item=>permitted(item.url)):[]]).filter(([,items])=>items.length)
  .sort(([a,aa],[b,bb])=>Math.max(...bb.map(score))-Math.max(...aa.map(score))||rank(a)-rank(b))
}
