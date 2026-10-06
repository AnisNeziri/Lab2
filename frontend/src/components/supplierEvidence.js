export function deliveryTrend(orders) {
 const rows=orders.filter(o=>o.complete&&Number.isFinite(o.lead_days)&&o.lead_days>=0&&o.completion_date).sort((a,b)=>a.completion_date.localeCompare(b.completion_date)).slice(-20)
 const maximum=Math.max(1,...rows.map(r=>r.lead_days))
 return {rows,maximum,points:rows.map((r,i)=>`${10+i*280/Math.max(1,rows.length-1)},${90-80*r.lead_days/maximum}`).join(' ')}
}
