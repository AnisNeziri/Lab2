export function projectedReceiptBalance(product) {
  // V6 freezes a baseline that excludes this shipment. Add its remaining allocation once.
  // A port arrival cannot stand in for usable warehouse stock.
  if(product.arrival_target!=='warehouse'||!product.arrival||!product.forecast_available)return null
  const point=product.timeline?.find(p=>p.date===product.arrival)
  if(point?.baseline==null||product.quantity==null)return null
  return Math.round((Number(point.baseline)+Number(product.quantity))*1000)/1000
}
export function cargoGroups(cargo, query='') {
  const q=query.trim().toLocaleLowerCase()
  return (cargo?.groups||[]).map(group=>({...group,items:(group.items||[]).filter(row=>!q||[row.name,row.sku,row.category,group.reference,group.destination].filter(Boolean).join(' ').toLocaleLowerCase().includes(q))})).filter(group=>group.items.length)
}
const stages=[['supplier_confirmation','Supplier','Furnitori'],['supplier_dispatch','Dispatched','Dërguar'],['origin_port','Origin port','Porti i nisjes'],['sea_transit','In transit','Në transport'],['destination_port','Destination port','Porti i destinacionit'],['customs_cleared','Customs','Dogana'],['inland_transport','Inland transport','Transporti tokësor'],['warehouse_arrival','Warehouse','Depo']]
export function logisticsProgress(shipment, evidence, now=new Date()) {
  const records=evidence?.milestones||shipment?.milestones||[]
  const milestone=type=>records.filter(r=>(r.type||r.milestone_type)===type)
  const actual=type=>milestone(type).find(r=>{const date=r.actual||r.actual_at;return date&&new Date(date)<=now})
  return stages.map(([key,en,sq])=>{
    const rows=milestone(key),done=actual(key)
    const dated=rows.find(r=>r.estimated||r.estimated_at||r.planned||r.planned_at)
    const expected=dated?.estimated||dated?.estimated_at||dated?.planned||dated?.planned_at
    const departure=key==='sea_transit'&&shipment.departed_at&&new Date(shipment.departed_at)<=now
    const late=!done&&!departure&&expected&&new Date(expected)<now
    // No stage is marked completed merely because a later stage exists.
    return {key,en,sq,date:done?.actual||done?.actual_at||expected||null,state:done?'completed':late?'delayed':departure||evidence?.current_milestone===key?'current':'upcoming'}
  })
}
