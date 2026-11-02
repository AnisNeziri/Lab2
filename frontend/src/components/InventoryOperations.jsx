import { useState } from 'react'
import { Link } from 'react-router-dom'
import { buildApiUrl } from '../api/client'
import { useInventoryResource } from '../hooks/useInventoryResource'
import { formatQuantity } from '../utils/formatQuantity'

const kinds = { customer_reservation: ['Customer reservation', 'Rezervim klienti'], picking: ['Allocated / picking', 'Alokuar / mbledhje'], planned_transfer: ['Transfer request (not reserved)', 'Kërkesë transferimi (pa rezervim)'], internal_transit: ['Internal transit', 'Transport i brendshëm'] }
const states = { available: ['Available', 'I disponueshëm'], reserved: ['Reserved', 'Rezervuar'], quarantine: ['Quality hold', 'Kontroll cilësie'], damaged: ['Damaged', 'Dëmtuar'], blocked: ['Blocked', 'Bllokuar'] }
export default function InventoryOperations({ productId, warehouseId, unit, t }) {
  const [open, setOpen] = useState(false)
  const resource = useInventoryResource(open ? buildApiUrl(`/inventory/products/${productId}/operations`, { warehouse_id: warehouseId || undefined }) : null)
  const q = value => `${formatQuantity(value, unit)} ${unit}`
  return <details className="inventory-operations" onToggle={e => setOpen(e.currentTarget.open)}>
    <summary>{t('Reservations, commitments & movement timeline', 'Rezervimet, angazhimet dhe historia e lëvizjeve')}</summary>
    {open && <>
      <p role="status">{resource.health === 'disconnected' ? t('Temporarily disconnected — last recorded data', 'Lidhja e ndërprerë — të dhënat e fundit') : resource.health === 'refreshing' ? t('Refreshing', 'Duke përditësuar') : resource.health === 'paused' ? t('Updates paused', 'Përditësimet në pauzë') : t('Live', 'Aktiv')}</p>
      {resource.data && <>
        <p>{t('Customer reservations reduce available stock. Draft transfers are requests, not reservations; availability is checked again at dispatch. Expired/inactive lots require source review. Confirmed order quantities use release/cancel and a new revision.', 'Rezervimet e klientëve ulin stokun e disponueshëm. Transferimet draft janë kërkesa pa rezervim; stoku kontrollohet sërish në nisje. Lotet e skaduara/joaktive kërkojnë rishikim në burim. Sasite e konfirmuara korrigjohen me lirim/anulim dhe një version të ri.')}</p>
        {resource.data.reservations_restricted && <p>{t('Order reservation details require fulfillment access.', 'Detajet e rezervimeve kërkojnë qasje në përmbushje.')}</p>}
        <div className="inventory-control-scroll" tabIndex={0} role="region" aria-label={t('Stock commitments', 'Angazhimet e stokut')}><table><thead><tr><th>{t('Source', 'Burimi')}</th><th>{t('Commitment', 'Angazhimi')}</th><th>{t('Warehouse / bin', 'Depoja / lokacioni')}</th><th>{t('Quantity', 'Sasia')}</th><th>{t('Picked', 'Mbledhur')}</th><th>{t('Review', 'Rishikimi')}</th></tr></thead><tbody>{resource.data.commitments.map(c => <tr key={c.id}>
          <th><Link to={c.url}>{c.reference}</Link></th><td>{t(...kinds[c.kind])}</td><td>{c.warehouse} {c.location || ''}{c.destination && ` → ${c.destination}`}</td><td>{q(c.quantity)}</td><td>{q(c.picked)}</td><td>{c.needs_review ? t('Expired / inactive lot — investigate', 'Lot i skaduar / joaktiv — hetoni') : '—'}</td>
        </tr>)}</tbody></table></div>
        {!resource.data.commitments.length && <p>{t('No visible open commitments.', 'Nuk ka angazhime të hapura të dukshme.')}</p>}
        <h4>{t('Recent stock activity', 'Aktiviteti i fundit i stokut')}</h4>
        <ol className="inventory-timeline">{resource.data.timeline.map(m => <li key={m.id}>
          <strong>{m.label} · {q(m.physical_change)}</strong> {m.from_state && <span>{t(...(states[m.from_state] || [m.from_state, m.from_state]))} → {t(...(states[m.to_state] || [m.to_state, m.to_state]))} · {q(m.quantity)}</span>}
          <p>{m.warehouse} {m.location || ''} · {m.actor} · {new Date(m.at).toLocaleString()}</p>
          <p>{m.reason}</p><small>{t('Location physical', 'Stoku fizik në lokacion')}: {q(m.before)} → {q(m.after)}. {t('Company-owned change', 'Ndryshimi i sasisë në pronësi')}: {q(m.company_owned_change)}</small>
          {m.url && <p><Link to={m.url}>{t('Open source', 'Hapni burimin')} #{m.source_id}</Link></p>}
        </li>)}</ol>
        {(resource.data.commitments_truncated || resource.data.timeline_truncated) && <p>{t('Showing the latest 30 movements and up to 50 commitments per source. Open the source module for full history.', 'Shfaqen 30 lëvizjet e fundit dhe deri 50 angazhime për burim. Hapni modulin për historinë e plotë.')}</p>}
      </>}
    </>}
  </details>
}
