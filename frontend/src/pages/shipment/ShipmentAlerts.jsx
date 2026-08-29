import { useEffect, useState } from 'react'
import { AlertTriangle, Bell, Clock, MapPin, Package, Radio, Ship, Trash2 } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from '../../hooks/useTranslation'
import { clearShipmentAlerts, getShipmentAlerts } from '../../api/shipments'

function formatDate(value) {
  if (!value) return '—'
  return new Date(value).toLocaleString()
}

const ICONS = {
  vessel_tracking_started: Ship,
  vessel_position_available: Radio,
  shipment_delayed: AlertTriangle,
  eta_changed: Clock,
  arrived_at_port: MapPin,
  close_to_destination: MapPin,
  delivered: Package,
}

export default function ShipmentAlerts() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [alerts, setAlerts] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [clearing, setClearing] = useState(false)

  const loadAlerts = () => {
    setLoading(true)
    getShipmentAlerts()
      .then((data) => setAlerts(data.alerts || []))
      .catch(() => setError(t('tracking.alerts.loadError')))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadAlerts()
  }, [t])

  const handleClear = async () => {
    if (!alerts.length || clearing) return
    setClearing(true)
    try {
      await clearShipmentAlerts()
      setAlerts([])
    } catch {
      setError(t('tracking.alerts.clearError'))
    } finally {
      setClearing(false)
    }
  }

  return (
    <div className="page">
      {error ? <div className="auth-error">{error}</div> : null}

      {loading ? <p className="page-message">{t('common.loading')}</p> : null}

      {!loading ? (
        <div className="tracking-alerts-toolbar">
          <span>{alerts.length} {t('tracking.alerts.title').toLowerCase()}</span>
          <button type="button" className="btn-secondary" onClick={handleClear} disabled={clearing || alerts.length === 0}>
            <Trash2 size={15} />
            {clearing ? t('common.saving') : t('tracking.alerts.clear')}
          </button>
        </div>
      ) : null}

      {!loading && !alerts.length ? (
        <div className="tracking-alerts-empty">
          <Bell size={28} />
          <p>{t('tracking.alerts.empty')}</p>
        </div>
      ) : null}

      <div className="tracking-alerts-list">
        {alerts.map((alert) => {
          const Icon = ICONS[alert.type] || Bell
          return (
            <article key={alert.id} className="tracking-alert-card">
              <div className="tracking-alert-icon">
                <Icon size={18} />
              </div>
              <div className="tracking-alert-body">
                <h2>{alert.title}</h2>
                <p>{alert.message}</p>
                <div className="tracking-alert-meta">
                  {alert.data?.tracking_number ? <span>#{alert.data.tracking_number}</span> : null}
                  {alert.data?.destination_port ? <span>{alert.data.destination_port}</span> : null}
                  {alert.data?.eta ? <span>ETA {formatDate(alert.data.eta)}</span> : null}
                  <span>{formatDate(alert.created_at)}</span>
                </div>
                {alert.data?.shipment_id ? (
                  <button
                    type="button"
                    className="auth-switch-link"
                    onClick={() => navigate(`/shipments/my-shipments?shipment=${alert.data.shipment_id}`)}
                  >
                    {t('tracking.alerts.viewShipment')}
                  </button>
                ) : null}
              </div>
            </article>
          )
        })}
      </div>
    </div>
  )
}
