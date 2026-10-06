// Activity counts can be combined across products; physical quantities in
// different units cannot. These helpers never alter ledger or inventory data.
export function movementActivity(movements = [], locale = 'en-GB') {
  const days = new Map()
  for (const movement of [...movements].sort((a,b) => new Date(a.created_at) - new Date(b.created_at))) {
    const date = new Date(movement.created_at)
    if (!Number.isFinite(date.getTime()) || !['in','out'].includes(movement.type)) continue
    const key = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`
    if (!days.has(key)) days.set(key, { date: date.toLocaleDateString(locale, { month:'short', day:'numeric' }), In:0, Out:0 })
    days.get(key)[movement.type === 'in' ? 'In' : 'Out']++
  }
  return [...days.values()].slice(-10)
}

export function categoryActivity(movements = [], unknown = 'Uncategorised') {
  const counts = new Map()
  for (const movement of movements.filter(item => item.type === 'out')) {
    const name = movement.product?.category?.name || movement.category_name || unknown
    counts.set(name, (counts.get(name) || 0) + 1)
  }
  return [...counts].map(([name,value]) => ({name,value})).sort((a,b) => b.value-a.value).slice(0,6)
}
