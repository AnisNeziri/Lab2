import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Bell, X, AlertTriangle, Package, Trash2 } from 'lucide-react'
import { clearNotifications, getNotifications, markNotificationRead, markAllNotificationsRead } from '../api/notifications'
import { useNotificationStore } from '../store/notificationStore'
import { useSettingsStore } from '../store/settingsStore'
import { useDialog } from '../hooks/useDialog'

export default function NotificationCenter({ onNavigate }) {
  const language=useSettingsStore(state=>state.language)
  const copy=(en,sq)=>language==='sq'?sq:en
  const notifications = useNotificationStore((state) => state.notifications)
  const unreadCount = useNotificationStore((state) => state.unreadCount)
  const setNotifications = useNotificationStore((state) => state.setNotifications)
  const markAsRead = useNotificationStore((state) => state.markAsRead)
  const markAllAsRead = useNotificationStore((state) => state.markAllAsRead)
  const [isOpen, setIsOpen] = useState(false)
  const [clearBusy, setClearBusy] = useState(false)
  const [error, setError] = useState('')
  const mutationPending = useRef(false)
  const panelRef = useDialog(()=>setIsOpen(false), clearBusy, isOpen)
  const bellRef = useRef(null)
  const [panelStyle, setPanelStyle] = useState({ top: 72, left: 16 })

  const updatePanelPosition = useCallback(() => {
    if (!bellRef.current) return
    const rect = bellRef.current.getBoundingClientRect()
    const width = Math.min(360, window.innerWidth - 32)
    let left = rect.left
    if (left + width > window.innerWidth - 16) {
      left = Math.max(16, window.innerWidth - width - 16)
    }
    setPanelStyle({
      top: rect.bottom + 8,
      left,
    })
  }, [])

  const toggleOpen = () => {
    setIsOpen((open) => {
      const next = !open
      if (next) {
        updatePanelPosition()
      }
      return next
    })
  }

  const fetchNotifications = useCallback(async () => {
    try {
      const data = await getNotifications()
      setNotifications(data.notifications || [])
      setError('')
    } catch (cause) {
      setError(cause.message)
    }
  }, [setNotifications])

  useEffect(() => {
    fetchNotifications()
  }, [fetchNotifications])

  useEffect(() => {
    const refreshNotifications = () => void fetchNotifications()
    window.addEventListener('notifications-refresh', refreshNotifications)
    return () => window.removeEventListener('notifications-refresh', refreshNotifications)
  }, [fetchNotifications])

  useEffect(() => {
    if (!isOpen) {
      return undefined
    }

    updatePanelPosition()
    window.addEventListener('resize', updatePanelPosition)
    window.addEventListener('scroll', updatePanelPosition, true)

    function handlePointerDown(event) {
      if (!mutationPending.current && panelRef.current && !panelRef.current.contains(event.target)) {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    return () => {
      window.removeEventListener('resize', updatePanelPosition)
      window.removeEventListener('scroll', updatePanelPosition, true)
      document.removeEventListener('mousedown', handlePointerDown)
    }
  }, [isOpen, updatePanelPosition])

  const handleMarkAsRead = async (id) => {
    try {
      await markNotificationRead(id)
      markAsRead(id)
    } catch (cause) {
      setError(cause.message)
    }
  }

  const handleMarkAllAsRead = async () => {
    if(mutationPending.current)return
    mutationPending.current=true;setClearBusy(true);setError('')
    try {
      await markAllNotificationsRead()
      markAllAsRead()
    } catch (cause) { setError(cause.message) }
    finally { mutationPending.current=false;setClearBusy(false) }
  }

  const handleClear = async () => {
    if (!notifications.length || mutationPending.current) return
    mutationPending.current=true
    setClearBusy(true)
    setError('')
    try {
      await clearNotifications('all')
      setNotifications([])
    } catch (cause) {
      setError(cause.message)
    } finally {
      mutationPending.current=false
      setClearBusy(false)
    }
  }

  const handleNotificationClick = (notification) => {
    handleMarkAsRead(notification.id)
    const data = notification.data || {}
    let path = '/dashboard'
    if (['automation','shipment_intelligence'].includes(notification.type) && /^\/(?!\/)/.test(data.url || '')) path=data.url
    else if (data.order_intake_id) path = `/order-hub?intake=${data.order_intake_id}`
    else if (data.document_id) path = `/documents?document=${data.document_id}`
    else if (data.sales_order_id) path = `/fulfillment?order=${data.sales_order_id}`
    else if (data.shipment_id) path = `/shipments/my-shipments?shipment=${data.shipment_id}`
    else if (data.invoice_id) path = `/invoices?invoice=${data.invoice_id}`
    else if (data.product_id) path = '/stock'
    onNavigate?.(path)
    setIsOpen(false)
  }

  const dropdown = isOpen
    ? createPortal(
        <>
          <div className="notification-backdrop" onClick={() => {if(!mutationPending.current)setIsOpen(false)}} />
          <div className="notification-dropdown" ref={panelRef} style={panelStyle} role="dialog" aria-modal="true" aria-label={copy('Notifications','Njoftimet')}>
            <div className="notification-header">
              <h3>{copy('Notifications','Njoftimet')}</h3>
              <div className="notification-header-actions">
                {unreadCount > 0 && (
                  <button type="button" className="mark-read-btn" disabled={clearBusy} onClick={handleMarkAllAsRead}>
                    {copy('Mark all read','Shëno të gjitha të lexuara')}
                  </button>
                )}
                <>
                  <button type="button" className="mark-read-btn notification-clear-btn" onClick={handleClear} disabled={clearBusy || notifications.length === 0} aria-label={copy('Clear notifications','Pastro njoftimet')}>
                    <Trash2 size={13} />
                    {clearBusy ? copy('Working…','Duke punuar…') : copy('Clear','Pastro')}
                  </button>
                </>
                <button type="button" className="close-btn" disabled={clearBusy} aria-label={copy('Close notifications','Mbyll njoftimet')} onClick={() => setIsOpen(false)}>
                  <X size={16} />
                </button>
              </div>
            </div>

            {error&&<div className="notification-error" role="alert"><p>{error}</p><button type="button" onClick={fetchNotifications}>{copy('Retry loading','Provo ngarkimin përsëri')}</button></div>}

            <div className="notification-list">
              {notifications.length === 0 ? (
                <div className="notification-empty">
                  <Package size={32} />
                  <p>{copy('No notifications yet','Nuk ka njoftime ende')}</p>
                </div>
              ) : (
                notifications.map((notification) => (
                  <button
                    key={notification.id}
                    type="button"
                    className={`notification-item ${notification.read_at ? 'read' : 'unread'}`}
                    onClick={() => handleNotificationClick(notification)}
                  >
                    <div className="notification-icon">
                      <AlertTriangle size={18} />
                    </div>
                    <div className="notification-content">
                      <p className="notification-title">{notification.data?.[`title_${language}`]||notification.title}</p>
                      <p className="notification-message">{notification.message}</p>
                      <p className="notification-time">
                        {new Date(notification.created_at).toLocaleTimeString()}
                      </p>
                    </div>
                  </button>
                ))
              )}
            </div>
          </div>
        </>,
        document.body
      )
    : null

  return (
    <div className="notification-center">
      <button
        type="button"
        className="notification-bell"
        ref={bellRef}
        aria-expanded={isOpen}
        aria-label={copy('Open notifications','Hap njoftimet')}
        onClick={toggleOpen}
      >
        <Bell size={20} />
        {unreadCount > 0 && <span className="notification-badge">{unreadCount}</span>}
      </button>
      {dropdown}
    </div>
  )
}
