import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest, buildApiUrl } from '../api/client'
import { useSettingsStore } from '../store/settingsStore'
import { useAuthStore } from '../store/authStore'
import { formatQuantity } from '../utils/formatQuantity'
import './InventoryPosition.css'
import { businessStatus } from '../utils/businessStatus'
import { useInventoryResource as usePositionResource } from '../hooks/useInventoryResource'
import InventoryOperations from './InventoryOperations'
import InventoryHealth from './InventoryHealth'

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


function Health({ resource, t }) {
  const labels = { paused: t('Paused while hidden', 'Në pauzë kur është e fshehur'), live: t('Live', 'Aktiv'), refreshing: t('Refreshing', 'Duke përditësuar'), disconnected: t('Temporarily disconnected', 'Lidhja është ndërprerë përkohësisht') }
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

function Sources({ inventory, t, canShipments, canPurchasing, unit }) {
  const language = useSettingsStore(s => s.language)
  return <div className="inventory-position-sources">
    {(inventory.incoming_schedule || []).slice(0, 20).map((s, i) => <p key={`po-${i}`}>
      {canPurchasing ? <Link to={`/purchase-orders?po=${s.purchase_order_id}`}>PO #{s.purchase_order_id}</Link> : t('Purchase supply', 'Furnizim blerjeje')}
      {' · '}{formatQuantity(s.quantity, unit)} {unit} · {t('Expected', 'Pritet')}: {s.expected_at || t('Unknown date', 'Datë e panjohur')}
    </p>)}
    {(inventory.incoming_evidence || []).slice(0, 50).map(s => <p key={s.shipment_item_id}>
      {canShipments ? <Link to={`/shipments/my-shipments?shipment=${s.shipment_id}`}>{s.reference || `#${s.shipment_id}`}</Link> : t('Shipment', 'Dërgesa')}
      {' · '}{businessStatus(s.status, language)} · {t('Remaining cargo', 'Ngarkesa e mbetur')}: {s.remaining_base == null ? t('Unattributed', 'Pa lidhje të verifikuar') : `${formatQuantity(s.remaining_base,unit)} ${unit}`}
      {' · '}ETA: {s.eta || '—'} {!s.attribution_complete && ` · ${t('Verify receipt / loaded quantity', 'Verifikoni pranimin / sasinë e ngarkuar')}`}
    </p>)}
    {(inventory.incoming_lines || []).map(line => <div key={line.purchase_order_item_id} className="inventory-supply-line">
      <strong>{canPurchasing ? <Link to={`/purchase-orders?po=${line.purchase_order_id}`}>{line.reference}</Link> : t('Purchase supply', 'Furnizim blerjeje')}</strong> · {businessStatus(line.state,language)}
      <dl>{[['ordered', 'Ordered', 'Porositur'], ['received', 'Received', 'Pranuar'], ['remaining', 'Remaining', 'Mbetur'], ['not_yet_shipped', 'Not yet shipped', 'Ende e padërguar'], ['in_transit', 'In transit', 'Në transport'], ['arrived_awaiting_receipt', 'Arrived / awaiting receipt', 'Arritur / pret pranim']].map(([key,en,sq]) => <div key={key}><dt>{t(en,sq)}</dt><dd>{line[key] == null ? t('Unknown', 'E panjohur') : `${formatQuantity(line[key],unit)} ${unit}`}</dd></div>)}</dl>
    </div>)}
    {inventory.incoming_lines_truncated && <small>{t('More purchase lines exist; open Purchasing.', 'Ka më shumë rreshta blerjeje; hapni Blerjet.')}</small>}
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
      <details><summary>{t('Incoming evidence', 'Burimet e ardhjeve')}</summary><Sources inventory={resource.data.inventory} unit={resource.data.product.unit} t={t} canShipments={permissions.includes('shipments.view')} canPurchasing={permissions.includes('purchase_orders.view')} /></details>
      <InventoryOperations key={`${productId}:${warehouseId || ''}`} productId={productId} warehouseId={warehouseId} unit={resource.data.product.unit} t={t} />
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
  const [detailed, setDetailed] = useState(false)
  const columns = detailed ? fields : fields.filter(([key]) => ['physical','available','reserved','incoming'].includes(key))
  const reserveKey = useRef(null)
  useEffect(() => { const timer = setTimeout(() => { setQuery(search); setPage(1) }, 250); return () => clearTimeout(timer) }, [search])
  const resource = usePositionResource(buildApiUrl('/inventory/control-center', { search: query || undefined, warehouse_id: warehouse || undefined, page, per_page: 25 }))
  return <section className="inventory-control card" aria-label={t('Inventory Control Center', 'Qendra e kontrollit të stokut')}>
    <header className="inventory-control-heading"><div><h2>{t('Inventory Control Center', 'Qendra e kontrollit të stokut')}</h2>
    <p>{t('Find a product to see what is in the warehouse, reserved for orders and available to sell.', 'Gjej produktin për të parë çfarë ka në depo, çfarë është rezervuar dhe çfarë mund të shitet.')}</p></div>
    <Health resource={resource} t={t} />
    </header>
    <div className="inventory-control-filters"><label>{t('Find a product', 'Gjej produktin')}<input type="search" placeholder={t('Product name or SKU', 'Emri i produktit ose SKU')} value={search} onChange={e => setSearch(e.target.value)} /></label>
      <label>{t('Warehouse', 'Depoja')}<select value={warehouse} onChange={e => { setWarehouse(e.target.value); setPage(1) }}><option value="">{t('All warehouses', 'Të gjitha depot')}</option>{warehouses.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}</select></label></div>
    <div className="inventory-control-table-heading"><h3>{t('Stock by product', 'Stoku sipas produktit')}</h3><button type="button" className="secondary" aria-pressed={detailed} onClick={()=>setDetailed(v=>!v)}>{detailed?t('Show key quantities','Shfaq sasitë kryesore'):t('Show all quantities','Shfaq të gjitha sasitë')}</button></div>
    <p className="inventory-control-caption">{t('Available stock excludes reservations, picking and stock on hold. Incoming stock is not yet available for sale.', 'Stoku për shitje përjashton rezervimet, përgatitjen dhe stokun e ndaluar. Stoku në ardhje ende nuk mund të shitet.')}</p>
    <div className="inventory-control-scroll" tabIndex={0} role="region" aria-label={t('Stock positions', 'Pozicionet e stokut')}><table><thead><tr><th>{t('Product', 'Produkti')}</th>{columns.map(([key, en, sq]) => <th key={key}>{t(en, sq)}</th>)}<th>{t('Attention', 'Vëmendje')}</th></tr></thead>
      <tbody>{(resource.data?.data || []).map(row => <tr key={row.product.id}><th><Link to={row.url}>{row.product.name}</Link><small>{row.product.sku} · {row.product.unit}</small>
        <details><summary>{t('Sources and next actions', 'Burimet dhe veprimet vijuese')}</summary><Sources inventory={row} unit={row.product.unit} t={t} canShipments={permissions.includes('shipments.view')} canPurchasing={permissions.includes('purchase_orders.view')} />
          {permissions.includes('fulfillment.view') && <p><Link to={row.orders_url}>{t('Waiting orders', 'Porositë në pritje')}</Link></p>}
          {permissions.includes('analytics.view') && <p><Link to={row.replenishment_url}>{t('Review Replenishment', 'Rishikoni rifurnizimin')}</Link></p>}
          {permissions.includes('inventory.counts.manage') && <p><Link to={`/operations-center?tab=counts&product=${row.product.id}${warehouse ? `&warehouse=${warehouse}` : ''}`}>{t('Prepare cycle count', 'Përgatitni numërim ciklik')}</Link></p>}
          {row.sources_truncated && <p>{t('More source records exist; open the source modules.', 'Ka më shumë burime; hapni modulet përkatëse.')}</p>}
        </details></th>{columns.map(([key]) => <td key={key} data-position={key}>{row.position[key] == null ? '—' : formatQuantity(row.position[key], row.product.unit)}</td>)}
        <td>{row.attention.length?row.attention.map(code => <p key={code}>{t(...(notices[code] || [code, code]))}</p>):'—'}</td></tr>)}</tbody></table></div>
    {resource.data && !resource.data.data.length&&<p className="workspace-empty">{t('No products match these filters.', 'Asnjë produkt nuk përputhet me këta filtra.')}</p>}
    {resource.data && <div className="inventory-control-pages"><button disabled={page <= 1} onClick={() => setPage(p => p - 1)}>{t('Previous', 'Prapa')}</button><span>{page} / {resource.data.last_page} · {resource.data.total} {t('products', 'produkte')}</span><button disabled={page >= resource.data.last_page} onClick={() => setPage(p => p + 1)}>{t('Next', 'Tjetra')}</button></div>}
    <InventoryHealth warehouseId={warehouse} t={t} />
    {permissions.includes('fulfillment.manage') && <details className="inventory-control-reservations"><summary>{t('Reserve stock for waiting orders', 'Rezervo stok për porositë në pritje')}</summary><p>{t('Reviews up to 50 orders across the company in priority and order-date sequence. Your product and warehouse filters above do not limit this action. Credit controls still apply.', 'Shqyrton deri në 50 porosi të kompanisë sipas përparësisë dhe datës. Filtrat e produktit dhe depos më sipër nuk e kufizojnë këtë veprim. Zbatohen kontrollet e kreditit.')}</p><button type="button" disabled={reserving} onClick={async () => {
      setReserving(true); setOutcome(''); reserveKey.current ||= crypto.randomUUID()
      try {
        const result = await apiRequest('/fulfillment/reserve-waiting', { method: 'POST', body: JSON.stringify({ idempotency_key: reserveKey.current }) })
        setOutcome(`${t('Orders reviewed', 'Porosi të kontrolluara')}: ${result.orders.length}. ${t('Blocked', 'Të bllokuara')}: ${result.orders.filter(o => o.status === 'blocked').length}.`)
        reserveKey.current = null; await resource.reload(); window.dispatchEvent(new CustomEvent('stock-refresh'))
      } catch (error) { setOutcome(error.message) } finally { setReserving(false) }
    }}>{t('Reserve waiting orders', 'Rezervoni porositë në pritje')}</button>{outcome&&<p role="status">{outcome}</p>}</details>}
    <details className="inventory-control-definition"><summary>{t('How stock quantities are calculated', 'Si llogariten sasitë e stokut')}</summary><Explanation t={t} /></details>
  </section>
}
