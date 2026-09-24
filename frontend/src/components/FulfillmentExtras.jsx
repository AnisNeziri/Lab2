import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'

export function DeliveryEditor({ d, detail, t, busy, onSave, statusText }) {
  const rows = d.packages.flatMap(p => p.items.map(item => ({ ...item, package: p.reference })))
  const [quantities, setQuantities] = useState({})
  const [recipient, setRecipient] = useState(d.recipient || '')
  const [proof, setProof] = useState(d.proof_reference || '')
  const [notes, setNotes] = useState(d.notes || '')
  const [failure, setFailure] = useState('')
  const revision = rows.map(r => `${r.id}:${r.delivered_quantity}`).join('|')
  useEffect(() => { setQuantities({}); setFailure('') }, [revision, d.status])
  return <form className="fulfillment-panel" onSubmit={e => {
    e.preventDefault()
    onSave({ dispatch_id: d.id, recipient, proof_reference: proof, notes, failure_reason: failure,
      items: rows.map(r => ({ package_item_id: r.id, quantity: quantities[r.id] ?? r.delivered_quantity })) })
  }}>
    <h3>{d.reference} · {statusText(d.status)}</h3>
    <Link to={`/daily-sales?sale=${d.daily_sale_id}`}>{t('Open sale', 'Hap shitjen')}</Link>
    {d.status !== 'delivered' && <>
      <p>{t('Enter cumulative delivered quantities. Leave the remainder outstanding for a later delivery.', 'Shkruani sasitë totale të dorëzuara. Pjesa e mbetur mund të dorëzohet më vonë.')}</p>
      {rows.map(r => {
        const allocation = detail.allocations.find(a => a.id === r.outbound_allocation_id)
        return <label className="fulfillment-pack-line" key={r.id}>
          <span>{allocation?.item?.product?.name} · {r.package} · {t('Dispatched', 'Nisur')}: {r.quantity} · {t('Delivered', 'Dorëzuar')}: {r.delivered_quantity}</span>
          <input aria-label={`${t('Delivered quantity', 'Sasia e dorëzuar')} ${r.id}`} type="number" step="0.001" min={r.delivered_quantity} max={r.quantity} required={!failure} value={quantities[r.id] ?? r.delivered_quantity} onChange={e => setQuantities({ ...quantities, [r.id]: e.target.value })}/>
        </label>
      })}
      <button type="button" disabled={busy} onClick={() => setQuantities(Object.fromEntries(rows.map(r => [r.id, r.quantity])))}>{t('Mark all quantities delivered', 'Shëno të gjitha sasitë të dorëzuara')}</button>
      <div className="fulfillment-grid">
        <label>{t('Recipient', 'Pranuesi')}<input value={recipient} onChange={e => setRecipient(e.target.value)} required={!failure}/></label>
        <label>{t('Proof / reference', 'Dëshmi / referencë')}<input value={proof} onChange={e => setProof(e.target.value)}/></label>
        <label>{t('Delivery notes', 'Shënime për dorëzimin')}<input value={notes} onChange={e => setNotes(e.target.value)}/></label>
        <label>{t('Delivery failure reason', 'Arsyeja e dështimit')}<input value={failure} onChange={e => setFailure(e.target.value)}/></label>
      </div>
      <button disabled={busy}>{failure ? t('Record failed delivery', 'Regjistro dështimin') : t('Confirm delivery', 'Konfirmo dorëzimin')}</button>
    </>}
  </form>
}

export function ReturnRequest({detail,t,busy,onSave}) {
  const [source,setSource]=useState(''),[quantity,setQuantity]=useState(''),[reason,setReason]=useState('')
  const rows=detail.dispatches.flatMap(d=>d.packages.flatMap(p=>p.items.map(i=>({...i,reference:`${d.reference} / ${p.reference}`}))))
    .map(i=>({...i,remaining:Number(i.delivered_quantity)-detail.returns.filter(r=>r.outbound_package_item_id===i.id&&r.status!=='cancelled').reduce((n,r)=>n+Number(r.quantity),0)})).filter(i=>i.remaining>0)
  const row=rows.find(i=>String(i.id)===source)
  return <form className="fulfillment-line" onSubmit={e=>{e.preventDefault();if(row)onSave({stage:'requested',allocation_id:row.outbound_allocation_id,package_item_id:row.id,quantity,reason})}}>
    <label>{t('Delivered product / package','Produkti / pakoja e dorëzuar')}<select required value={source} onChange={e=>setSource(e.target.value)}><option value="">{t('Select','Zgjidh')}</option>{rows.map(r=><option key={r.id} value={r.id}>{detail.allocations.find(a=>a.id===r.outbound_allocation_id)?.item?.product?.name} · {r.reference} · {r.remaining}</option>)}</select></label>
    <label>{t('Return quantity','Sasia për kthim')}<input required type="number" min="0.001" max={row?.remaining} step="0.001" value={quantity} onChange={e=>setQuantity(e.target.value)}/></label>
    <label>{t('Reason','Arsyeja')}<input required value={reason} onChange={e=>setReason(e.target.value)}/></label>
    <button disabled={busy||!row}>{t('Request return','Kërko kthimin')}</button>
  </form>
}

export function TaskAssignments({tasks,pickers,t,busy,onSave,statusText}) {
  return <div>{tasks.map(task=><form key={task.id} className="fulfillment-line" onSubmit={e=>{e.preventDefault();onSave({task_id:task.id,assigned_to:new FormData(e.currentTarget).get('picker')||null})}}>
    <span>{task.reference} · {statusText(task.status)}</span>
    <label>{t('Assigned picker','Punëtori i caktuar')}<select key={`${task.id}:${task.assigned_to}`} name="picker" defaultValue={task.assigned_to||''} disabled={['completed','cancelled'].includes(task.status)}><option value="">{t('Unassigned','Pa caktuar')}</option>{pickers.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
    <button disabled={busy||['completed','cancelled'].includes(task.status)}>{t('Save assignment','Ruaj caktimin')}</button>
  </form>)}</div>
}

export function FulfillmentMetrics({metrics,t}) {
  if(!metrics)return null
  const rows=[['average_pick_minutes',t('Average pick time (minutes)','Koha mesatare e mbledhjes (minuta)')],['average_fulfillment_hours',t('Fulfillment time (hours)','Koha e përmbushjes (orë)')],['on_time_delivery_percent',t('On-time delivery','Dorëzim në kohë')],['on_time_dispatch_percent',t('On-time dispatch','Nisje në kohë')],['short_pick_percent',t('Short picks','Mbledhje me mungesa')],['pick_accuracy_percent',t('Picks without reported exceptions','Mbledhje pa probleme të raportuara')],['return_percent',t('Returned quantity','Sasia e kthyer')]]
  return <details className="fulfillment-panel"><summary>{t('Operational performance','Performanca operative')} · {metrics.from} — {metrics.to}</summary><p>{t('Recorded operations only. A dash means there is no eligible data.','Vetëm operacione të regjistruara. Viza tregon se nuk ka të dhëna të përshtatshme.')}</p><div className="fulfillment-metrics">{rows.map(([key,label])=><article key={key}><span>{label}</span><strong>{metrics[key] == null ? '—' : `${metrics[key]}${key.endsWith('percent')?'%':''}`}</strong></article>)}</div></details>
}
