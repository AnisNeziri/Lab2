import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {Link} from 'react-router-dom'
import {shipmentIntelligenceLabel} from '../../components/shipmentIntelligencePresentation'
import { ClipboardList, Navigation, RotateCcw, Ship } from 'lucide-react'
import PageHeader from '../../components/PageHeader'
import SearchField from '../../components/SearchField'
import GlobalVesselMap from '../../components/tracking/GlobalVesselMap'
import { useTranslation } from '../../hooks/useTranslation'
import { getTrackingPorts, getVessels } from '../../api/tracking'
import {
  aisPositionLabel,
  navigationStatusLabel,
  relativeAisTime,
} from '../../utils/aisTracking'

const REFRESH_MS = 10000

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

export default function GlobalMap() {
  const { t, language } = useTranslation()
  const [vessels, setVessels] = useState([])
  const [metrics, setMetrics] = useState(null)
  const [ports, setPorts] = useState([])
  const [destination, setDestination] = useState('')
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const requestRef = useRef(0)

  const filters = useMemo(() => ({
    destination: destination || undefined,
    search: search || undefined,
  }), [destination, search])

  const loadData = useCallback(async ({ silent = false } = {}) => {
    const requestId = ++requestRef.current
    try {
      if (!silent) setError('')
      const data = await getVessels(filters)
      if (requestId !== requestRef.current) return
      setVessels(data.vessels || [])
      setMetrics(data.metrics || null)
      setSelected((current) => {
        if (!data.vessels?.length) return null
        if (current) {
          return data.vessels.find((item) => item.id === current.id) || data.vessels[0]
        }
        return data.vessels[0]
      })
    } catch {
      if (!silent && requestId === requestRef.current) {
        setError(t('tracking.global.loadError'))
      }
    } finally {
      if (!silent) setLoading(false)
    }
  }, [filters, t])

  useEffect(() => {
    getTrackingPorts()
      .then(setPorts)
      .catch(() => {})
  }, [])

  useEffect(() => {
    setLoading(true)
    loadData()
  }, [filters])

  useEffect(() => {
    const timer = setInterval(() => loadData({ silent: true }), REFRESH_MS)
    return () => clearInterval(timer)
  }, [loadData])

  function resetFilters() {
    setDestination('')
    setSearch('')
    setSelected(null)
  }

  const destinationOptions = useMemo(() => {
    const fromMetrics = metrics?.destination_ports || []
    const fromPorts = ports.map((port) => port.name)
    return [...new Set([...fromMetrics, ...fromPorts])].sort()
  }, [metrics, ports])

  return (
    <div className="page tracking-global-page">
      <PageHeader title={t('nav.globalMap')} description={language === 'sq' ? 'Pozicionet më të fundit të marra. Kontrolloni gjithmonë kohën e sinjalit.' : 'Latest received vessel positions. Always check the signal timestamp.'} actions={<Link className="btn-secondary" to="/shipments/my-shipments">{t('nav.myShipments')}</Link>}/>
      <div className="tracking-metrics-grid">
        <div className="tracking-metric-card">
          <Ship size={18} />
          <div>
            <span>{t('tracking.global.totalVessels')}</span>
            <strong>{metrics?.total_vessels ?? 0}</strong>
          </div>
        </div>
        <div className="tracking-metric-card">
          <Navigation size={18} />
          <div>
            <span>{t('tracking.global.activeVessels')}</span>
            <strong>{metrics?.active_vessels ?? 0}</strong>
          </div>
        </div>
        <div className="tracking-metric-card">
          <ClipboardList size={18} />
          <div>
            <span>{t('tracking.global.linkedOrders')}</span>
            <strong>{metrics?.linked_purchase_orders ?? 0}</strong>
          </div>
        </div>
        <div className="tracking-metric-card">
          <Navigation size={18} />
          <div>
            <span>{t('tracking.global.destinationPorts')}</span>
            <strong>{metrics?.destination_ports?.length ?? 0}</strong>
          </div>
        </div>
      </div>

      <div className="tracking-filters">
        {selected?.intelligence&&<div className="tracking-provider-notice"><strong>{shipmentIntelligenceLabel(selected.intelligence.risk,language)}</strong> · {selected.intelligence.eta?.range_start||'—'} — {selected.intelligence.eta?.range_end||'—'} · <Link to={'/shipments/my-shipments?view=intelligence&shipment='+selected.id}>{t('shipments.intelligence')}</Link></div>}
        <label>
          {t('tracking.global.destinationFilter')}
          <select aria-label={t('tracking.global.destinationFilter')} value={destination} onChange={(e) => setDestination(e.target.value)}>
            <option value="">{t('tracking.global.allDestinations')}</option>
            {destinationOptions.map((name) => (
              <option key={name} value={name}>{name}</option>
            ))}
          </select>
        </label>
        <SearchField label={language === 'sq' ? 'Kërko anije' : 'Search vessels'} placeholder={t('tracking.global.searchPlaceholder')} value={search} onChange={(e) => setSearch(e.target.value)}/>
        <button type="button" className="btn-secondary" onClick={resetFilters}>
          {t('tracking.global.resetFilters')}
        </button>
        <button type="button" className="btn-secondary" onClick={loadData}>
          <RotateCcw size={16} />
          {t('tracking.global.refresh')}
        </button>
      </div>

      {error ? <div className="auth-error">{error}</div> : null}

      <div className="tracking-global-layout">
        <div className="tracking-map-panel">
          {loading && !vessels.length ? <p className="page-message">{t('common.loading')}</p> : null}
          <GlobalVesselMap
            vessels={vessels}
            selectedId={selected?.id}
            onSelectVessel={setSelected}
            pendingLabel={t('tracking.global.awaitingSignal')}
            noPositionsLabel={t('tracking.global.noTrackedVessels')}
          />
        </div>

        <aside className="tracking-vessel-panel">
          {selected ? (
            <>
              <h2>{selected.name}</h2>
              {selected.destination_port ? <p className="tracking-vessel-route">{t('tracking.my.destination')}: {selected.destination_port}</p> : null}
              <dl className="tracking-detail-list">
                <div><dt>{t('tracking.global.status')}</dt><dd><span className={`ais-state-badge ${selected.position_state || ''}`}>{aisPositionLabel(selected, t)}</span></dd></div>
                {selected.eta ? <div><dt>{t('tracking.global.eta')}</dt><dd>{formatDate(selected.eta)}</dd></div> : null}
                <div><dt>{t('tracking.global.location')}</dt><dd>{selected.location_label}</dd></div>
                {selected.distance_to_port_km != null ? <div><dt>{t('tracking.global.distance')}</dt><dd>{selected.distance_to_port_km} {t('common.km')}</dd></div> : null}
                {selected.speed_knots != null ? <div><dt>{t('tracking.global.speed')}</dt><dd>{selected.speed_knots} kn</dd></div> : null}
                {selected.course != null ? <div><dt>{t('shipments.course')}</dt><dd>{selected.course}°</dd></div> : null}
                {selected.heading != null ? <div><dt>{t('shipments.heading')}</dt><dd>{selected.heading}°</dd></div> : null}
                {selected.navigation_status != null ? <div><dt>{t('shipments.navigationStatus')}</dt><dd>{navigationStatusLabel(selected.navigation_status, t)}</dd></div> : null}
                <div><dt>MMSI</dt><dd>{selected.mmsi}</dd></div>
                {selected.imo ? <div><dt>IMO</dt><dd>{selected.imo}</dd></div> : null}
                {selected.purchase_order_number ? <div><dt>{t('shipments.purchaseOrder')}</dt><dd>{selected.purchase_order_number}</dd></div> : null}
                <div><dt>{t('tracking.global.lastUpdate')}</dt><dd title={formatDate(selected.position_updated_at)}>{relativeAisTime(selected.position_updated_at, t)}</dd></div>
                <div><dt>{t('tracking.global.positionData')}</dt><dd>{aisPositionLabel(selected, t)}</dd></div>
              </dl>
            </>
          ) : (
            <p className="page-message">{t('tracking.global.selectVessel')}</p>
          )}
        </aside>
      </div>
    </div>
  )
}
