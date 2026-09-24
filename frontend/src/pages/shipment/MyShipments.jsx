import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Archive,
  Heart,
  RefreshCw,
  Star,
  Trash2,
  Plus,
  Search,
  Ship,
  X,
} from 'lucide-react'
import { ShipmentRouteMap } from '../../components/tracking/GlobalVesselMap'
import ShipmentsPageIntro from '../../components/ShipmentsPageIntro'
import EntityDocuments from '../../components/EntityDocuments'
import { useTranslation } from '../../hooks/useTranslation'
import {
  archiveShipment,
  createAisShipment,
  favoriteShipment,
  getPurchaseOrders,
  getShipmentHistory,
  getShipments,
  lookupVessel,
  refreshShipment,
  removeShipment,
  restoreShipment,
  saveShipment,
  getShipment,
  updateShipmentLogistics,
} from '../../api/shipments'
import { getSystemMode } from '../../api/system'
import { useAuthStore } from '../../store/authStore'
import { getPurchaseOrder } from '../../api/purchaseOrders'
import {
  aisConnectionLabel,
  aisConnectionTone,
  aisPositionLabel,
  aisPositionState,
  navigationStatusLabel,
  relativeAisTime,
} from '../../utils/aisTracking'

const REFRESH_MS = 10000

const RISK_CLASS = {
  on_schedule: 'risk-on-schedule',
  potential_delay: 'risk-potential-delay',
  high_risk: 'risk-high',
}

// Purchase orders are paginated by the API while shipments return a plain
// array. Normalize both shapes before rendering so a paginator
// response can never crash this page with `.map is not a function`.
function responseItems(payload) {
  if (Array.isArray(payload)) return payload
  if (Array.isArray(payload?.data)) return payload.data
  if (Array.isArray(payload?.items)) return payload.items
  return []
}

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

export default function MyShipments() {
  const { t } = useTranslation()
  const [searchParams] = useSearchParams()
  const requestedShipmentId = Number(searchParams.get('shipment')) || null
  const permissions = useAuthStore((state) => state.permissions)
  const canManageLogistics = permissions.includes('shipments.manage')
  const [view, setView] = useState('active')
  const [shipments, setShipments] = useState([])
  const [history, setHistory] = useState([])
  const [purchaseOrders, setPurchaseOrders] = useState([])
  const [trackingCapabilities, setTrackingCapabilities] = useState(null)
  const [selectedId, setSelectedId] = useState(null)
  const [vesselIdentifier, setVesselIdentifier] = useState('')
  const [vesselLookup, setVesselLookup] = useState(null)
  const [lookingUp, setLookingUp] = useState(false)
  const [purchaseOrderId, setPurchaseOrderId] = useState('')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [logisticsOpen, setLogisticsOpen] = useState(false)
  const [logistics, setLogistics] = useState(null)

  const selected = useMemo(
    () => shipments.find((item) => item.id === selectedId) ?? shipments[0] ?? null,
    [shipments, selectedId]
  )

  async function loadShipments(nextView = view) {
    const data = await getShipments({ archived: nextView === 'archived' })
    const items = responseItems(data)
    // Background polling returns compact list records. Merge them into any
    // open detailed record so containers and history-backed fields
    // do not visibly disappear every 30 seconds.
    setShipments((current) => items.map((item) => ({
      ...(current.find((existing) => existing.id === item.id) || {}),
      ...item,
    })))
    setSelectedId((current) => {
      if (current && items.some((item) => item.id === current)) return current
      if (!current && requestedShipmentId && items.some((item) => item.id === requestedShipmentId)) {
        return requestedShipmentId
      }
      return items[0]?.id ?? null
    })
  }

  async function loadAll() {
    try {
      setLoading(true)
      setError('')
      const [poData, modeData] = await Promise.all([
        canManageLogistics ? getPurchaseOrders() : Promise.resolve([]),
        getSystemMode().catch(() => null),
      ])
      setPurchaseOrders(responseItems(poData))
      setTrackingCapabilities(modeData?.tracking || null)
      await loadShipments()
    } catch {
      setError(t('shipments.loadError'))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadAll()
  }, [])

  useEffect(() => {
    if (!selected?.id) {
      setHistory([])
      return
    }
    Promise.all([getShipmentHistory(selected.id), getShipment(selected.id)])
      .then(([events, detail]) => {
        setHistory(events)
        setShipments((current) => current.map((row) => row.id === detail.id ? detail : row))
      }).catch(() => setHistory([]))
  }, [selected?.id])

  function openLogistics() {
    if (!selected) return
    const linkedOrders = [...(selected.purchase_orders || []), ...(selected.purchase_order ? [selected.purchase_order] : [])]
      .filter((row, index, rows) => rows.findIndex((candidate) => candidate.id === row.id) === index)
    setLogistics({
      purchase_order_id: selected.purchase_order_id || '',
      purchase_order_ids: linkedOrders.map((row) => row.id),
      available_order_items: linkedOrders.flatMap((row) => (row.items || []).map((item) => ({ ...item, po_number: row.po_number }))),
      bill_of_lading: selected.bill_of_lading || '', commercial_invoice_number: selected.commercial_invoice_number || '', incoterm: selected.incoterm || '',
      transshipment_port: selected.transshipment_port || '', arrival_date: selected.arrival_date ? String(selected.arrival_date).slice(0, 10) : '',
      notes: selected.notes || '',
      containers: (selected.containers || []).map((row) => ({ id: row.id, client_key: `container-${row.id}`, container_number: row.container_number, seal_number: row.seal_number || '', container_type: row.container_type || '', booking_reference: row.booking_reference || '', bill_of_lading: row.bill_of_lading || '', forwarder: row.forwarder || '', vessel_name: row.vessel_name || '', voyage: row.voyage || '', origin_port: row.origin_port || '', destination_port: row.destination_port || '', etd: row.etd ? String(row.etd).slice(0,16) : '', eta: row.eta ? String(row.eta).slice(0,16) : '', actual_departure: row.actual_departure ? String(row.actual_departure).slice(0,16) : '', actual_arrival: row.actual_arrival ? String(row.actual_arrival).slice(0,16) : '', status: row.status || 'planned', capacity_cbm: row.capacity_cbm || '', capacity_weight_kg: row.capacity_weight_kg || '', gross_weight_kg: row.gross_weight_kg || '', volume_m3: row.volume_m3 || '', notes: row.notes || '' })),
      items: (selected.items || []).map((row) => ({ ...row, shipment_container_key: row.shipment_container_id ? `container-${row.shipment_container_id}` : '' })),
    })
    setLogisticsOpen(true)
  }

  async function saveLogistics(event) {
    event.preventDefault(); setSubmitting(true); setError('')
    try {
      const { available_order_items, ...payload } = logistics
      const detail = await updateShipmentLogistics(selected.id, { ...payload, purchase_order_id: logistics.purchase_order_id ? Number(logistics.purchase_order_id) : null, purchase_order_ids: logistics.purchase_order_ids.map(Number), arrival_date: logistics.arrival_date || null, containers: logistics.containers.map((row) => ({ ...row, gross_weight_kg: row.gross_weight_kg || null, volume_m3: row.volume_m3 || null, capacity_cbm: row.capacity_cbm || null, capacity_weight_kg: row.capacity_weight_kg || null, etd: row.etd || null, eta: row.eta || null, actual_departure: row.actual_departure || null, actual_arrival: row.actual_arrival || null })), items: logistics.items.map((row) => ({ ...row, shipment_container_id: row.shipment_container_id || null, shipment_container_key: row.shipment_container_id ? null : (row.shipment_container_key || null), loaded_quantity: row.loaded_quantity === '' ? null : row.loaded_quantity })) })
      setShipments((current) => current.map((row) => row.id === detail.id ? detail : row)); setLogisticsOpen(false)
    } catch (err) { setError(err.message || t('shipments.logisticsError')) } finally { setSubmitting(false) }
  }

  async function toggleLogisticsOrder(orderId) {
    const id = Number(orderId)
    const removing = logistics.purchase_order_ids.includes(id)
    if (removing) {
      setLogistics((current) => ({ ...current, purchase_order_id: Number(current.purchase_order_id) === id ? '' : current.purchase_order_id, purchase_order_ids: current.purchase_order_ids.filter((value) => value !== id), available_order_items: current.available_order_items.filter((item) => Number(item.purchase_order_id) !== id), items: current.items.filter((item) => Number(item.purchase_order_item?.purchase_order_id || item.purchase_order_id) !== id) }))
      return
    }
    try {
      const order = await getPurchaseOrder(id)
      setLogistics((current) => ({ ...current, purchase_order_id: current.purchase_order_id || id, purchase_order_ids: [...current.purchase_order_ids, id], available_order_items: [...current.available_order_items, ...(order.items || []).map((item) => ({ ...item, po_number: order.po_number }))] }))
    } catch (err) { setError(err.message || t('shipments.logisticsError')) }
  }

  function updateContainer(index, field, value) {
    setLogistics((current) => ({ ...current, containers: current.containers.map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row) }))
  }

  function addAllocation(item) {
    if (logistics.items.some((row) => Number(row.purchase_order_item_id) === Number(item.id))) return
    setLogistics((current) => ({ ...current, items: [...current.items, { purchase_order_item_id: item.id, product_id: item.product_id || null, description: item.description, unit: item.unit, quantity: item.quantity, planned_quantity: item.quantity, loaded_quantity: '', base_quantity: item.base_quantity || item.quantity, unit_cbm: item.product?.volume_m3 || null, unit_weight_kg: item.product?.weight_kg || null, shipment_container_key: current.containers[0]?.client_key || '', shipment_container_id: current.containers[0]?.id || null }] }))
  }

  function updateAllocationQuantity(index, value) {
    setLogistics((current) => ({ ...current, items: current.items.map((row, rowIndex) => {
      if (rowIndex !== index) return row
      const previousQuantity = Number(row.quantity || row.planned_quantity || 0)
      const factor = previousQuantity > 0 && row.base_quantity !== null && row.base_quantity !== undefined
        ? Number(row.base_quantity) / previousQuantity
        : null
      return { ...row, quantity: value, planned_quantity: value, base_quantity: factor === null ? null : Number(value) * factor }
    }) }))
  }

  useEffect(() => {
    if (view !== 'active') return undefined
    const timer = setInterval(async () => {
      try {
        const remoteShipments = shipments.filter((item) => {
          const provider = item.tracking_provider || item.tracking_mode
          return item.status !== 'delivered' && !['manual', 'demo', 'disabled', 'aisstream'].includes(provider)
        })
        await Promise.allSettled(remoteShipments.map((item) => refreshShipment(item.id)))
        const [, modeData] = await Promise.all([
          loadShipments(),
          getSystemMode().catch(() => null),
        ])
        if (modeData?.tracking) setTrackingCapabilities(modeData.tracking)
      } catch {
      }
    }, REFRESH_MS)
    return () => clearInterval(timer)
  }, [view, shipments.length])

  async function switchView(nextView) {
    setView(nextView)
    try {
      await loadShipments(nextView)
    } catch {
      setError(t('shipments.loadError'))
    }
  }

  async function handleLookup(event) {
    event.preventDefault()
    setLookingUp(true)
    setError('')
    try {
      const result = await lookupVessel(vesselIdentifier.trim())
      setVesselLookup(result)
    } catch (err) {
      setVesselLookup(null)
      setError(err.message || t('shipments.lookupError'))
    } finally {
      setLookingUp(false)
    }
  }

  async function handleTrack(event) {
    event.preventDefault()
    if (!vesselLookup?.lookup_token) return
    setSubmitting(true)
    setError('')
    try {
      const created = await createAisShipment({
        lookup_token: vesselLookup.lookup_token,
        purchase_order_id: purchaseOrderId ? Number(purchaseOrderId) : null,
      })
      setVesselIdentifier('')
      setVesselLookup(null)
      setPurchaseOrderId('')
      await loadShipments()
      setSelectedId(created.id)
    } catch (err) {
      setError(err.message || t('shipments.trackError'))
    } finally {
      setSubmitting(false)
    }
  }

  async function runAction(action, { refreshNotifications = false } = {}) {
    if (!selected) return
    setError('')
    try {
      await action(selected.id)
      if (refreshNotifications) {
        window.dispatchEvent(new CustomEvent('notifications-refresh'))
      }
      await loadShipments()
    } catch (err) {
      setError(err.message || t('shipments.loadError'))
    }
  }

  const statusKey = selected?.status ? `shipments.status.${selected.status}` : null
  const statusLabel = statusKey && t(statusKey) !== statusKey
    ? t(statusKey)
    : selected?.status?.replace(/_/g, ' ')
  const liveAisAvailable = Boolean(trackingCapabilities?.vessel?.enabled)
  const trackingStatusKnown = trackingCapabilities !== null
  const imoLookupAvailable = Boolean(trackingCapabilities?.vessel?.imo_lookup_enabled)
  const lookupVesselData = vesselLookup?.vessel || null
  const selectedProvider = selected?.tracking_provider || selected?.tracking_mode
  const canRefreshSelected = selected && !['manual', 'demo', 'disabled', 'aisstream'].includes(selectedProvider)
  const aisConnectionState = trackingCapabilities?.vessel?.connection_state || trackingCapabilities?.vessel?.status || 'starting'
  const selectedPositionState = selected ? aisPositionState(selected) : 'waiting_for_data'
  const selectedAisDetails = selected?.vessel_details?.aisstream || {}
  const selectedDimensions = selectedAisDetails?.static?.dimensions || {}

  return (
    <div className="page">
      <ShipmentsPageIntro message={t('shipments.networkLoading')} />
      {selected&&<EntityDocuments entityType="shipment" entityId={selected.id}/>}
      <div className="shipments-toolbar no-print">
        <div className="shipments-view-tabs">
          <button type="button" className={view === 'active' ? 'active' : ''} onClick={() => switchView('active')}>
            {t('shipments.active')}
          </button>
          <button type="button" className={view === 'archived' ? 'active' : ''} onClick={() => switchView('archived')}>
            {t('shipments.archived')}
          </button>
        </div>
      </div>

      {error ? <div className="auth-error">{error}</div> : null}

      <div className="shipments-layout">
        <div className="shipments-form">
          {view === 'active' && canManageLogistics ? (
            <div className="vessel-link-workflow">
              <h2>{t('tracking.my.addShipment')}</h2>
              <p className={`tracking-provider-notice ${aisConnectionTone(aisConnectionState)}${!trackingStatusKnown ? ' pending' : ''}`}>
                {!trackingStatusKnown
                  ? t('shipments.trackingStatusLoading')
                  : liveAisAvailable
                    ? `${aisConnectionLabel(aisConnectionState, t)} · ${t('shipments.aisOnlyNotice')}`
                    : t('shipments.liveProviderDisabled')}
              </p>
              {!imoLookupAvailable && liveAisAvailable ? <small className="field-hint">{t('shipments.imoProviderHint')}</small> : null}

              <form className="vessel-lookup-form" onSubmit={handleLookup}>
                <label htmlFor="vessel-identifier">{t('shipments.vesselIdentifier')}
                  <input
                    id="vessel-identifier"
                    value={vesselIdentifier}
                    onChange={(event) => {
                      setVesselIdentifier(event.target.value.toUpperCase())
                      setVesselLookup(null)
                    }}
                    placeholder="MMSI 353136000 / IMO 9811000"
                    minLength={7}
                    maxLength={30}
                    required
                  />
                </label>
                <button type="submit" className="auth-submit" disabled={lookingUp || !liveAisAvailable}>
                  <Search size={17} />{lookingUp ? t('common.loading') : t('shipments.findVessel')}
                </button>
              </form>

              {lookupVesselData ? <article className="vessel-lookup-result" aria-live="polite">
                <Ship size={28} />
                <div>
                  <h3>{lookupVesselData.name || `MMSI ${lookupVesselData.mmsi}`}</h3>
                  <p>{lookupVesselData.message}</p>
                  <dl>
                    <div><dt>MMSI</dt><dd>{lookupVesselData.mmsi}</dd></div>
                    {lookupVesselData.imo ? <div><dt>IMO</dt><dd>{lookupVesselData.imo}</dd></div> : null}
                    {lookupVesselData.vessel_type ? <div><dt>{t('shipments.vesselType')}</dt><dd>{lookupVesselData.vessel_type}</dd></div> : null}
                    {lookupVesselData.flag_country ? <div><dt>{t('shipments.flag')}</dt><dd>{lookupVesselData.flag_country}</dd></div> : null}
                    {lookupVesselData.destination_port ? <div><dt>{t('tracking.my.destination')}</dt><dd>{lookupVesselData.destination_port}</dd></div> : null}
                  </dl>
                </div>
                <form className="vessel-link-form" onSubmit={handleTrack}>
                  <label htmlFor="po-select">{t('shipments.purchaseOrder')}
                    <select id="po-select" value={purchaseOrderId} onChange={(e) => setPurchaseOrderId(e.target.value)}>
                      <option value="">{t('shipments.trackWithoutOrder')}</option>
                      {purchaseOrders.map((po) => (
                        <option key={po.id} value={po.id}>{po.po_number} · {po.supplier?.name || ''}</option>
                      ))}
                    </select>
                  </label>
                  <button type="submit" className="auth-submit" disabled={submitting}>
                    {submitting ? t('common.loading') : t('shipments.linkAndTrack')}
                  </button>
                </form>
              </article> : null}
            </div>
          ) : view === 'archived' ? (
            <div className="shipments-archived-hint">
              <Archive size={20} />
              <p>{t('shipments.archivedHint')}</p>
            </div>
          ) : null}

          <div className="shipments-list">
            {loading ? <p>{t('common.loading')}</p> : null}
            {!loading && !shipments.length ? <p>{t('shipments.empty')}</p> : null}
            {shipments.map((item) => (
              <button
                key={item.id}
                type="button"
                className={`shipments-list-item${item.id === selected?.id ? ' active' : ''}`}
                onClick={() => setSelectedId(item.id)}
              >
                <span>
                  {item.is_favorite ? <Star size={14} className="inline-star" /> : null}
                  {item.vessel_name || (item.mmsi ? `MMSI ${item.mmsi}` : null) || item.tracking_number || `#${item.id}`}
                </span>
                <small>
                  {item.purchase_order?.po_number || (item.mmsi ? `MMSI ${item.mmsi}` : item.tracking_number)}
                  {' · '}{aisPositionLabel(item, t)}
                  {item.position_updated_at ? ` · ${relativeAisTime(item.position_updated_at, t)}` : ''}
                </small>
              </button>
            ))}
          </div>
        </div>

        <div className="shipments-detail">
          {selected ? (
            <>
              <div className="shipments-detail-header">
                <div>
                  <h2>{selected.tracking_number || selected.tracking_reference}</h2>
                  <p>{selected.vessel_name || `MMSI ${selected.mmsi}`}{selected.purchase_order?.po_number ? ` · ${selected.purchase_order.po_number}` : ''}</p>
                </div>
                <span className={`risk-badge ${RISK_CLASS[selected.risk_level] || ''}`}>
                  {t(`shipments.risk.${selected.risk_level}`)}
                </span>
              </div>

              <div className="shipments-actions">
                {view === 'active' && canManageLogistics ? (
                  <>
                    {canRefreshSelected ? <button type="button" onClick={() => runAction(refreshShipment)}><RefreshCw size={16} />{t('shipments.refresh')}</button> : null}
                    {!selected.is_saved ? <button type="button" onClick={() => runAction(saveShipment)}>{t('shipments.save')}</button> : null}
                    <button type="button" onClick={() => runAction(favoriteShipment)}><Heart size={16} />{selected.is_favorite ? t('tracking.my.unfavorite') : t('tracking.my.favorite')}</button>
                    <button type="button" onClick={() => runAction(archiveShipment)}><Archive size={16} />{t('shipments.archive')}</button>
                    <button type="button" className="danger" onClick={() => runAction(removeShipment, { refreshNotifications: true })}><Trash2 size={16} />{t('tracking.my.remove')}</button>
                    {canManageLogistics ? <button type="button" className="secondary" onClick={openLogistics}>{t('shipments.logistics')}</button> : null}
                  </>
                ) : view === 'archived' && canManageLogistics ? (
                  <>
                    <button type="button" onClick={() => runAction(restoreShipment)}>{t('shipments.restore')}</button>
                    <button type="button" className="danger" onClick={() => runAction(removeShipment, { refreshNotifications: true })}><Trash2 size={16} />{t('tracking.my.remove')}</button>
                  </>
                ) : null}
              </div>

              {selectedProvider === 'aisstream' ? (
                <p className={`tracking-provider-note ${aisConnectionTone(aisConnectionState)}`}>
                  <RefreshCw size={16} />
                  <span><strong>{aisConnectionLabel(aisConnectionState, t)}</strong><br />{t('shipments.aisAutomatic')}</span>
                </p>
              ) : null}

              <div className="shipments-meta">
                <div><span>{t('shipments.status')}</span><strong>{statusLabel}</strong></div>
                {selectedProvider === 'aisstream' ? <div><span>{t('shipments.aisPositionState')}</span><strong className={`ais-state-badge ${selectedPositionState}`}>{aisPositionLabel(selected, t)}</strong></div> : null}
                {selected.eta ? <div><span>{t('shipments.eta')}</span><strong>{formatDate(selected.eta)}</strong></div> : null}
                {selected.previous_eta ? <div><span>{t('shipments.previousEta')}</span><strong>{formatDate(selected.previous_eta)}</strong></div> : null}
                {selected.distance_to_port_km != null ? <div><span>{t('shipments.distance')}</span><strong>{selected.distance_to_port_km} {t('common.km')}</strong></div> : null}
                <div><span>{t('shipments.location')}</span><strong>{selected.last_location_label || '—'}</strong></div>
                <div><span>{t('tracking.my.origin')}</span><strong>{selected.origin_port || t('shipments.originNotBroadcast')}</strong></div>
                <div><span>{t('tracking.my.destination')}</span><strong>{selected.destination_port || t('shipments.awaitingVoyageData')}</strong></div>
                <div><span>{t('shipments.billOfLading')}</span><strong>{selected.bill_of_lading || '—'}</strong></div>
                <div><span>{t('shipments.incoterm')}</span><strong>{selected.incoterm || '—'}</strong></div>
                {selected.mmsi ? <div><span>{t('shipments.mmsi')}</span><strong>{selected.mmsi}</strong></div> : null}
                {selected.imo ? <div><span>IMO</span><strong>{selected.imo}</strong></div> : null}
                {selected.call_sign ? <div><span>{t('shipments.callSign')}</span><strong>{selected.call_sign}</strong></div> : null}
                {selected.vessel_type ? <div><span>{t('shipments.vesselType')}</span><strong>{selected.vessel_type}</strong></div> : null}
                {selected.flag_country ? <div><span>{t('shipments.flag')}</span><strong>{selected.flag_country}</strong></div> : null}
                {selected.speed_knots != null ? <div><span>{t('tracking.global.speed')}</span><strong>{selected.speed_knots} kn</strong></div> : null}
                {selected.course != null ? <div><span>{t('shipments.course')}</span><strong>{selected.course}°</strong></div> : null}
                {selected.heading != null ? <div><span>{t('shipments.heading')}</span><strong>{selected.heading}°</strong></div> : null}
                {selected.navigation_status != null ? <div><span>{t('shipments.navigationStatus')}</span><strong>{navigationStatusLabel(selected.navigation_status, t)}</strong></div> : null}
                {selected.position_updated_at ? <div><span>{t('tracking.global.lastUpdate')}</span><strong title={formatDate(selected.position_updated_at)}>{relativeAisTime(selected.position_updated_at, t)}</strong></div> : null}
                {selected.last_refreshed_at && selected.last_refreshed_at !== selected.position_updated_at ? <div><span>{t('shipments.lastAisMessage')}</span><strong title={formatDate(selected.last_refreshed_at)}>{relativeAisTime(selected.last_refreshed_at, t)}</strong></div> : null}
                {selectedDimensions.length_m ? <div><span>{t('shipments.vesselLength')}</span><strong>{selectedDimensions.length_m} m</strong></div> : null}
                {selectedDimensions.beam_m ? <div><span>{t('shipments.vesselBeam')}</span><strong>{selectedDimensions.beam_m} m</strong></div> : null}
                {selectedAisDetails?.voyage?.maximum_static_draught_m ? <div><span>{t('shipments.vesselDraught')}</span><strong>{selectedAisDetails.voyage.maximum_static_draught_m} m</strong></div> : null}
                {selected.purchase_order?.po_number ? <div><span>{t('shipments.purchaseOrder')}</span><strong>{selected.purchase_order.po_number}</strong></div> : null}
                <div><span>{t('shipments.incomingItems')}</span><strong>{selected.items_count ?? selected.items?.length ?? 0} · {selected.incoming_quantity || 0}</strong></div>
              </div>

              {(selected.containers?.length || canManageLogistics) ? <section className="shipment-logistics-summary"><h3>{t('shipments.logistics')}</h3>
                {selected.containers?.length ? <div className="shipment-container-chips">{selected.containers.map((container) => <span key={container.id}><strong>{container.container_number}</strong><small>{container.container_type || t('shipments.container')} · {container.seal_number || t('shipments.noSeal')}</small></span>)}</div> : <p>{t('shipments.noContainers')}</p>}
              </section> : null}

              <ShipmentRouteMap shipment={selected} pendingLabel={t('shipments.awaitingAisDetails')} />

              <section className="shipment-history">
                <h3>{t('shipments.history')}</h3>
                {!history.length ? <p>{t('shipments.historyEmpty')}</p> : (
                  <ol>
                    {history.map((event) => (
                      <li key={event.id}>
                        <strong>{event.description}</strong>
                        <span>{formatDate(event.created_at)}</span>
                      </li>
                    ))}
                  </ol>
                )}
              </section>
            </>
          ) : (
            <p className="page-message">{loading ? t('common.loading') : t('shipments.empty')}</p>
          )}
        </div>
      </div>
      {logisticsOpen && logistics ? <div className="modal-overlay" onMouseDown={(event) => event.target === event.currentTarget && !submitting && setLogisticsOpen(false)}><section className="modal shipment-logistics-modal" role="dialog" aria-modal="true"><header className="modal-header"><h2>{t('shipments.logistics')}</h2><button className="modal-close-btn" disabled={submitting} onClick={() => setLogisticsOpen(false)}><X /></button></header><form className="modal-body form-grid" onSubmit={saveLogistics}>
        <label>{t('shipments.purchaseOrder')}<select value={logistics.purchase_order_id} onChange={(event) => setLogistics({ ...logistics, purchase_order_id: event.target.value })}><option value="">{t('common.none')}</option>{purchaseOrders.filter((po) => logistics.purchase_order_ids.includes(Number(po.id))).map((po) => <option key={po.id} value={po.id}>{po.po_number}</option>)}</select></label>
        <fieldset className="shipment-po-links form-span-full"><legend>{t('shipments.linkedPurchaseOrders')}</legend>{purchaseOrders.map((po) => <label key={po.id}><input type="checkbox" checked={logistics.purchase_order_ids.includes(Number(po.id))} onChange={() => toggleLogisticsOrder(po.id)}/><span>{po.po_number}<small>{po.supplier?.name || ''}</small></span></label>)}</fieldset>
        <label>{t('shipments.billOfLading')}<input value={logistics.bill_of_lading} onChange={(event) => setLogistics({ ...logistics, bill_of_lading: event.target.value })} /></label>
        <label>{t('shipments.commercialInvoice')}<input value={logistics.commercial_invoice_number} onChange={(event) => setLogistics({ ...logistics, commercial_invoice_number: event.target.value })} /></label>
        <label>{t('shipments.incoterm')}<select value={logistics.incoterm} onChange={(event) => setLogistics({ ...logistics, incoterm: event.target.value })}><option value="">{t('common.none')}</option>{['EXW','FCA','CPT','CIP','DAP','DPU','DDP','FAS','FOB','CFR','CIF'].map((code) => <option key={code}>{code}</option>)}</select></label>
        <label>{t('shipments.transshipmentPort')}<input value={logistics.transshipment_port} onChange={(event) => setLogistics({ ...logistics, transshipment_port: event.target.value })} /></label>
        <label>{t('shipments.arrivalDate')}<input type="date" value={logistics.arrival_date} onChange={(event) => setLogistics({ ...logistics, arrival_date: event.target.value })} /></label>
        <fieldset className="shipment-containers-editor"><legend>{t('shipments.containers')}</legend>{logistics.containers.map((container,index) => <div className="shipment-container-card" key={container.client_key || container.id || index}><header><strong>{container.container_number || t('shipments.newContainer')}</strong><button type="button" className="icon-danger" onClick={() => setLogistics((current) => ({ ...current, containers: current.containers.filter((_,rowIndex) => rowIndex !== index), items: current.items.map((item) => item.shipment_container_id === container.id || item.shipment_container_key === container.client_key ? { ...item, shipment_container_id: null, shipment_container_key: '' } : item) }))}><Trash2 size={16}/></button></header><div className="shipment-container-fields"><label>{t('shipments.containerNumber')}<input required value={container.container_number} onChange={(event) => updateContainer(index,'container_number',event.target.value.toUpperCase())}/></label><label>{t('shipments.sealNumber')}<input value={container.seal_number} onChange={(event) => updateContainer(index,'seal_number',event.target.value)}/></label><label>{t('shipments.containerType')}<select value={container.container_type} onChange={(event) => updateContainer(index,'container_type',event.target.value)}><option value="">—</option>{['20GP','40GP','40HQ'].map((type) => <option key={type}>{type}</option>)}</select></label><label>{t('shipments.containerStatus')}<select value={container.status} onChange={(event) => updateContainer(index,'status',event.target.value)}>{['planned','booked','loading','loaded','departed','in_transit','arrived','customs','customs_cleared','delivered'].map((status) => <option key={status} value={status}>{status.replaceAll('_',' ')}</option>)}</select></label><label>{t('shipments.bookingReference')}<input value={container.booking_reference} onChange={(event) => updateContainer(index,'booking_reference',event.target.value)}/></label><label>{t('shipments.billOfLading')}<input value={container.bill_of_lading} onChange={(event) => updateContainer(index,'bill_of_lading',event.target.value)}/></label><label>{t('shipments.forwarder')}<input value={container.forwarder} onChange={(event) => updateContainer(index,'forwarder',event.target.value)}/></label><label>{t('shipments.voyage')}<input value={container.voyage} onChange={(event) => updateContainer(index,'voyage',event.target.value)}/></label><label>{t('shipments.origin')}<input value={container.origin_port} onChange={(event) => updateContainer(index,'origin_port',event.target.value)}/></label><label>{t('shipments.destination')}<input value={container.destination_port} onChange={(event) => updateContainer(index,'destination_port',event.target.value)}/></label><label>ETD<input type="datetime-local" value={container.etd} onChange={(event) => updateContainer(index,'etd',event.target.value)}/></label><label>ETA<input type="datetime-local" value={container.eta} onChange={(event) => updateContainer(index,'eta',event.target.value)}/></label><label>{t('shipments.actualDeparture')}<input type="datetime-local" value={container.actual_departure} onChange={(event) => updateContainer(index,'actual_departure',event.target.value)}/></label><label>{t('shipments.actualArrival')}<input type="datetime-local" value={container.actual_arrival} onChange={(event) => updateContainer(index,'actual_arrival',event.target.value)}/></label><label>{t('shipments.capacityCbm')}<input type="number" min="0" step="0.001" value={container.capacity_cbm} onChange={(event) => updateContainer(index,'capacity_cbm',event.target.value)}/></label><label>{t('shipments.capacityWeight')}<input type="number" min="0" step="0.001" value={container.capacity_weight_kg} onChange={(event) => updateContainer(index,'capacity_weight_kg',event.target.value)}/></label></div></div>)}<button type="button" className="secondary" onClick={() => setLogistics((current) => ({ ...current, containers:[...current.containers,{client_key:`new-${Date.now()}`,container_number:'',seal_number:'',container_type:'',booking_reference:'',bill_of_lading:'',forwarder:'',vessel_name:'',voyage:'',origin_port:'',destination_port:'',etd:'',eta:'',actual_departure:'',actual_arrival:'',status:'planned',capacity_cbm:'',capacity_weight_kg:'',gross_weight_kg:'',volume_m3:'',notes:''}] }))}><Plus size={16}/>{t('shipments.addContainer')}</button></fieldset>
        <fieldset className="shipment-allocations-editor"><legend>{t('shipments.itemAllocation')}</legend><div className="shipment-available-items">{logistics.available_order_items.filter((item) => !logistics.items.some((row) => Number(row.purchase_order_item_id) === Number(item.id))).map((item) => <button type="button" className="secondary" key={item.id} onClick={() => addAllocation(item)}><Plus size={14}/>{item.po_number} · {item.description}</button>)}</div>{logistics.items.map((item,index) => <div className="shipment-allocation-row" key={item.id || item.purchase_order_item_id}><span><strong>{item.description}</strong><small>{item.unit}</small></span><label>{t('shipments.plannedQuantity')}<input required type="number" min="0.001" step="0.001" value={item.planned_quantity || item.quantity} onChange={(event) => updateAllocationQuantity(index,event.target.value)}/></label><label>{t('shipments.loadedQuantity')}<input type="number" min="0" step="0.001" value={item.loaded_quantity ?? ''} onChange={(event) => setLogistics((current) => ({ ...current, items: current.items.map((row,rowIndex) => rowIndex === index ? { ...row, loaded_quantity:event.target.value } : row) }))}/></label><label>{t('shipments.container')}<select value={item.shipment_container_id ? `id:${item.shipment_container_id}` : item.shipment_container_key} onChange={(event) => { const value=event.target.value; setLogistics((current) => ({ ...current, items: current.items.map((row,rowIndex) => rowIndex === index ? { ...row, shipment_container_id:value.startsWith('id:') ? Number(value.slice(3)) : null, shipment_container_key:value.startsWith('id:') ? '' : value } : row) })) }}><option value="">—</option>{logistics.containers.map((container) => <option key={container.client_key || container.id} value={container.id ? `id:${container.id}` : container.client_key}>{container.container_number || t('shipments.newContainer')}</option>)}</select></label><button type="button" className="icon-danger" onClick={() => setLogistics((current) => ({ ...current, items: current.items.filter((_,rowIndex) => rowIndex !== index) }))}><Trash2 size={16}/></button></div>)}</fieldset>
        <label className="form-span-full">{t('warehouseOps.notes')}<textarea rows="3" value={logistics.notes} onChange={(event) => setLogistics({ ...logistics, notes: event.target.value })}/></label><div className="form-actions form-span-full"><button disabled={submitting}>{submitting ? t('common.loading') : t('common.save')}</button><button type="button" className="secondary" onClick={() => setLogisticsOpen(false)}>{t('common.cancel')}</button></div>
      </form></section></div> : null}
    </div>
  )
}
