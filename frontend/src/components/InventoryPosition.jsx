import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest, buildApiUrl } from '../api/client'
import { useSettingsStore } from '../store/settingsStore'
import { useAuthStore } from '../store/authStore'
import { formatQuantity } from '../utils/formatQuantity'
import './InventoryPosition.css'

const fields = [
  ['physical', 'Physical', 'Fizik'], ['available', 'Available to sell', 'Për shitje'],
  ['reserved', 'Reserved', 'Rezervuar'], ['allocated', 'Allocated / picking', 'Alokuar / mbledhje'],
  ['quality_hold', 'Quality hold', 'Në kontroll cilësie'], ['damaged', 'Damaged', 'Dëmtuar'],
  ['blocked', 'Blocked', 'Bllokuar'], ['incoming', 'Incoming (includes transit)', 'Në ardhje (përfshin transportin)'],
  ['in_transit', 'Of incoming: in transit', 'Nga ardhjet: në transport'],
  ['internal_in_transit', 'Internal transfer in transit', 'Transferim i brendshëm në transport'],
  ['backordered', 'Backordered', 'Në pritje stoku'], ['projected_available', 'Projected after demand', 'E parashikuar pas kërkesës'],
]
const notices = {
  integrity_difference: ['Integrity difference — investigate', 'Diferencë integriteti — hetoni'],
  out_of_stock: ['Out of available stock', 'Pa stok të disponueshëm'],
  below_minimum: ['Available below minimum', 'Stoku i disponueshëm nën minimum'],
  backorders: ['Orders waiting for stock', 'Porosi në pritje stoku'], quality_hold: ['Stock on quality hold', 'Stok në kontroll cilësie'],
  delayed_incoming: ['Incoming overdue — review ETA', 'Ardhje e vonuar — kontrolloni afatin'],
  unattributed_receipts_or_cargo: ['Shipment attribution incomplete', 'Lidhja me dërgesën është e paplotë'],
}

// Uses the existing application poll and stock events. Keep the last valid response on failure.
function usePositionResource(url) {
  const [state, setState] = useState({ data: null, health: 'refreshing', updated: null })
  const sequence = useRef(0)
  const reload = useCallback(async () => {
    const request = ++sequence.current
    setState(s => ({ ...s, health: 'refreshing' }))
    try {
      const data = await apiRequest(url)
      if (request === sequence.current) setState({ data, health: 'live', updated: new Date() })
    } catch {
      if (request === sequence.current) setState(s => ({ ...s, health: 'disconnected' }))
    }
  }, [url])
  useEffect(() => {
    setState({ data: null, health: 'refreshing', updated: null })
    void reload()
    const refresh = () => { if (!document.hidden) void reload() }
    window.addEventListener('database-refresh', refresh)
    window.addEventListener('stock-refresh', refresh)
    return () => { ++sequence.current; window.removeEventListener('database-refresh', refresh); window.removeEventListener('stock-refresh', refresh) }
  }, [reload])
  return { ...state, reload }
}

function Health({ resource, t }) {
  const labels = { live: t('Live', 'Aktiv'), refreshing: t('Refreshing', 'Duke përditësuar'), disconnected: t('Temporarily disconnected', 'Lidhja është ndërprerë përkohësisht') }
  return <p role="status" className="inventory-position-health" data-health={resource.health}>
    {labels[resource.health]} {resource.updated && <span> · {t('Last updated', 'Përditësuar')} {resource.updated.toLocaleTimeString()}</span>}
    {resource.health === 'disconnected' && <button type="button" onClick={resource.reload}>{t('Retry', 'Riprovo')}</button>}
  </p>
}

function Facts({ position, unit, t }) {
  return <dl className="inventory-position-facts">{fields.map(([key, en, sq]) => <div key={key}>
    <dt>{t(en, sq)}</dt><dd data-position={key}>{position[key] == null ? t('Unknown', 'E panjohur') : `${formatQuantity(position[key], unit)} ${unit}`}</dd>
  </div>)}</dl>
}

function Explanation({ t }) {
  return <p className="inventory-position-explanation">{t(
    'Available = Physical − Reserved − Allocated − Quality Hold − Damaged − Blocked. Picked stock is part of Allocated. In Transit is part of Incoming. Projected = Available + Incoming − Backorders; it is not sellable stock. Internal transfers remain owned while travelling between warehouses.',
    'Për shitje = Fizik − Rezervuar − Alokuar − Kontroll cilësie − Dëmtuar − Bllokuar. Stoku i mbledhur përfshihet te Alokuar. Transporti përfshihet te Ardhjet. E parashikuar = Për shitje + Ardhjet − Porositë në pritje; nuk është stok për shitje. Transferimet mbeten pronë gjatë transportit mes depove.'
  )}</p>
}

function Sources({ inventory, t, canShipments, canPurchasing }) {
  return <div className="inventory-position-sources">
    {(inventory.incoming_schedule || []).slice(0, 20).map((s, i) => <p key={`po-${i}`}>
      {canPurchasing ? <Link to={`/purchase-orders?po=${s.purchase_order_id}`}>PO #{s.purchase_order_id}</Link> : t('Purchase supply', 'Furnizim blerjeje')}
      {' · '}{s.quantity} · {t('Expected', 'Pritet')}: {s.expected_at || t('Unknown date', 'Datë e panjohur')}
    </p>)}
    {(inventory.incoming_evidence || []).slice(0, 50).map(s => <p key={s.shipment_item_id}>
      {canShipments ? <Link to={`/shipments/my-shipments?shipment=${s.shipment_id}`}>{s.reference || `#${s.shipment_id}`}</Link> : t('Shipment', 'Dërgesa')}
      {' · '}{t('Remaining cargo', 'Ngarkesa e mbetur')}: {s.remaining_base ?? t('Unattributed', 'Pa lidhje të verifikuar')}
      {' · '}ETA: {s.eta || '—'} {!s.attribution_complete && ` · ${t('Verify receipt / loaded quantity', 'Verifikoni pranimin / sasinë e ngarkuar')}`}
    </p>)}
    <small>{t('Dates are estimates. Receipt and inspection must complete before stock is available.', 'Datat janë vlerësime. Pranimi dhe kontrolli duhet të përfundojnë para se stoku të jetë i disponueshëm.')}</small>
  </div>
}

export function InventoryPosition({ productId, warehouseId }) {
  const language = useSettingsStore(s => s.language)
  const t = (en, sq) => language === 'sq' ? sq : en
  const permissions = useAuthStore(s => s.permissions)
  const url = buildApiUrl(`/inventory/products/${productId}`, { warehouse_id: warehouseId || undefined })
  const resource = usePositionResource(url)
  return <section className="inventory-position card" aria-label={t('Stock position', 'Pozicioni i stokut')}>
    <h3>{t('Stock position', 'Pozicioni i stokut')}</h3><Health resource={resource} t={t} />
    {resource.data?.position && <><Facts position={resource.data.position} unit={resource.data.product.unit} t={t} /><Explanation t={t} />
      <details><summary>{t('Incoming evidence', 'Burimet e ardhjeve')}</summary><Sources inventory={resource.data.inventory} t={t} canShipments={permissions.includes('shipments.view')} canPurchasing={permissions.includes('purchase_orders.view')} /></details>
      <Link to={`/stock?product=${productId}`}>{t('Movement history', 'Historia e lëvizjeve')}</Link>
    </>}
  </section>
}

export default function InventoryControlCenter({ warehouses = [] }) {
  const language = useSettingsStore(s => s.language)
  const permissions = useAuthStore(s => s.permissions)
  const t = (en, sq) => language === 'sq' ? sq : en
  const [search, setSearch] = useState(''), [query, setQuery] = useState(''), [warehouse, setWarehouse] = useState(''), [page, setPage] = useState(1)
  const [reserving, setReserving] = useState(false), [outcome, setOutcome] = useState('')
  const reserveKey = useRef(null)
  useEffect(() => { const timer = setTimeout(() => { setQuery(search); setPage(1) }, 250); return () => clearTimeout(timer) }, [search])
  const resource = usePositionResource(buildApiUrl('/inventory/control-center', { search: query || undefined, warehouse_id: warehouse || undefined, page, per_page: 25 }))
  return <section className="inventory-control card" aria-label={t('Inventory Control Center', 'Qendra e kontrollit të stokut')}>
    <h2>{t('Inventory Control Center', 'Qendra e kontrollit të stokut')}</h2>
    <p>{t('Current stock and supply facts. Open Replenishment for forecasts and recommendations.', 'Faktet aktuale të stokut dhe furnizimit. Hapni Rifurnizimin për parashikime dhe rekomandime.')}</p>
    <Health resource={resource} t={t} />
    {permissions.includes('fulfillment.manage') && <><button type="button" disabled={reserving} onClick={async () => {
      setReserving(true); setOutcome(''); reserveKey.current ||= crypto.randomUUID()
      try {
        const result = await apiRequest('/fulfillment/reserve-waiting', { method: 'POST', body: JSON.stringify({ idempotency_key: reserveKey.current }) })
        setOutcome(`${t('Orders reviewed', 'Porosi të kontrolluara')}: ${result.orders.length}. ${t('Blocked', 'Të bllokuara')}: ${result.orders.filter(o => o.status === 'blocked').length}.`)
        reserveKey.current = null; await resource.reload(); window.dispatchEvent(new CustomEvent('stock-refresh'))
      } catch (error) { setOutcome(error.message) } finally { setReserving(false) }
    }}>{t('Reserve waiting orders', 'Rezervoni porositë në pritje')}</button><small>{t('Company-wide: priority, order date, order number. Reviews up to 50 orders; credit controls apply.', 'Për kompaninë: prioriteti, data, numri i porosisë. Kontrollon deri 50 porosi; zbatohen kontrollet e kreditit.')}</small><p role="status">{outcome}</p></>}
    <div className="inventory-control-filters"><label>{t('Product / SKU', 'Produkti / SKU')}<input value={search} onChange={e => setSearch(e.target.value)} /></label>
      <label>{t('Warehouse', 'Depoja')}<select value={warehouse} onChange={e => { setWarehouse(e.target.value); setPage(1) }}><option value="">{t('All warehouses', 'Të gjitha depot')}</option>{warehouses.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}</select></label></div>
    <Explanation t={t} />
    <div className="inventory-control-scroll" tabIndex={0} role="region" aria-label={t('Stock positions', 'Pozicionet e stokut')}><table><thead><tr><th>{t('Product', 'Produkti')}</th>{fields.filter(([key]) => !['damaged', 'blocked', 'internal_in_transit'].includes(key)).map(([key, en, sq]) => <th key={key}>{t(en, sq)}</th>)}<th>{t('Attention', 'Vëmendje')}</th></tr></thead>
      <tbody>{(resource.data?.data || []).map(row => <tr key={row.product.id}><th><Link to={row.url}>{row.product.name}</Link><small>{row.product.sku} · {row.product.unit}</small>
        <details><summary>{t('Investigate', 'Hetoni')}</summary><Sources inventory={row} t={t} canShipments={permissions.includes('shipments.view')} canPurchasing={permissions.includes('purchase_orders.view')} />
          {permissions.includes('fulfillment.view') && <p><Link to={row.orders_url}>{t('Waiting orders', 'Porositë në pritje')}</Link></p>}
          {permissions.includes('analytics.view') && <p><Link to={row.replenishment_url}>{t('Review Replenishment', 'Rishikoni rifurnizimin')}</Link></p>}
          {permissions.includes('inventory.counts.manage') && <p><Link to={`/operations-center?tab=counts&product=${row.product.id}${warehouse ? `&warehouse=${warehouse}` : ''}`}>{t('Prepare cycle count', 'Përgatitni numërim ciklik')}</Link></p>}
          {row.sources_truncated && <p>{t('More source records exist; open the source modules.', 'Ka më shumë burime; hapni modulet përkatëse.')}</p>}
        </details></th>{fields.filter(([key]) => !['damaged', 'blocked', 'internal_in_transit'].includes(key)).map(([key]) => <td key={key} data-position={key}>{row.position[key] == null ? '—' : formatQuantity(row.position[key], row.product.unit)}</td>)}
        <td>{row.attention.map(code => <p key={code}>{t(...(notices[code] || [code, code]))}</p>)}</td></tr>)}</tbody></table></div>
    {resource.data && <div className="inventory-control-pages"><button disabled={page <= 1} onClick={() => setPage(p => p - 1)}>{t('Previous', 'Prapa')}</button><span>{page} / {resource.data.last_page} · {resource.data.total} {t('products', 'produkte')}</span><button disabled={page >= resource.data.last_page} onClick={() => setPage(p => p + 1)}>{t('Next', 'Tjetra')}</button></div>}
  </section>
}
