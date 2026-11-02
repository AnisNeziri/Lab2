import { Link } from 'react-router-dom'
import { buildApiUrl } from '../api/client'
import { useInventoryResource } from '../hooks/useInventoryResource'

const labels = {
  integrity: ['Negative / inconsistent stock', 'Stok negativ / jokonsistent'],
  quality_hold: ['Quality holds', 'Kontrolle cilësie'],
  damaged_blocked: ['Damaged / blocked stock', 'Stok i dëmtuar / bllokuar'],
  overdue_receipts: ['Overdue receipts', 'Pranime të vonuara'],
  stale_transfers: ['Transfers open over 7 days', 'Transfere të hapura mbi 7 ditë'],
  count_variances: ['Unresolved count variances', 'Diferenca numërimi të pazgjidhura'],
  reservation_conflicts: ['Invalid lot reservations', 'Rezervime lotesh të pavlefshme'],
}

export default function InventoryHealth({ warehouseId, t }) {
  const resource = useInventoryResource(buildApiUrl('/inventory/health', { warehouse_id: warehouseId || undefined }))
  const checks = Object.entries(resource.data?.checks || {})
  const flagged = checks.filter(([,check]) => check.count == null || Number(check.count) > 0)
  const clear = checks.filter(([,check]) => check.count != null && Number(check.count) === 0)
  const facts = entries => <dl className="inventory-health-facts">{entries.map(([key,check])=><div key={key} data-severity={check.status}><dt>{t(...(labels[key] || [check.label,check.label]))}</dt><dd>{check.count ?? t('Restricted','E kufizuar')}</dd></div>)}</dl>
  return <section className="inventory-health" aria-label={t('Inventory health', 'Gjendja e inventarit')}>
    <h3>{t('Stock that needs attention', 'Stoku që kërkon vëmendje')}</h3>
    <p className="inventory-health-scope">{warehouseId ? t('Selected warehouse · all products', 'Depoja e zgjedhur · të gjitha produktet') : t('All warehouses · all products', 'Të gjitha depot · të gjitha produktet')}</p>
    <p role="status" className="inventory-health-status">{resource.health === 'disconnected' ? t('Disconnected — last known facts; retry', 'Pa lidhje — të dhënat e fundit; riprovo') : resource.health === 'live' ? t('Current review facts', 'Faktet aktuale për shqyrtim') : resource.health === 'paused' ? t('Updates paused', 'Përditësimet në pauzë') : t('Refreshing', 'Duke përditësuar')}
      {resource.updated && ` · ${resource.updated.toLocaleTimeString()}`}
      {resource.health === 'disconnected' && <button type="button" onClick={resource.reload}>{t('Retry', 'Riprovo')}</button>}</p>
    {resource.data && <>
      {flagged.length ? facts(flagged) : <p>{t('No issues flagged by the checks visible to you.', 'Kontrollet ku ke qasje nuk kanë shënuar probleme.')}</p>}
      {resource.data.attention.length > 0 && <details><summary>{t('Investigate source records', 'Hetoni dokumentet burimore')}</summary><ul>{resource.data.attention.map(item => <li key={item.id}><Link to={item.url}>{item.title}</Link> · {t(...(labels[item.key.split(':')[1]] || [item.description, item.description]))}</li>)}</ul><small>{t('Up to 10 records per check. Review the source workspace for all records.', 'Deri 10 dokumente për kontroll. Shihni hapësirën burimore për të gjitha dokumentet.')}</small></details>}
      {clear.length>0&&<details><summary>{t('Checks with no flagged items', 'Kontrolle pa probleme të shënuara')} ({clear.length})</summary>{facts(clear)}</details>}
      <small>{t('Counts refer to source records, not stock quantities. Transfers older than 7 days need review; this is not a promised delivery date.', 'Numrat tregojnë regjistrime burimore, jo sasi stoku. Transferet mbi 7 ditë kërkojnë shqyrtim; ky nuk është afat i premtuar dorëzimi.')}</small>
      {resource.data.valuation_url && <p><Link to={resource.data.valuation_url}>{t('Inventory / accounting reconciliation', 'Rakordimi inventar / kontabilitet')}</Link></p>}
    </>}
  </section>
}
