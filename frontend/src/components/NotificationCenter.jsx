import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Bell, X, AlertTriangle, Package, Trash2 } from 'lucide-react'
import { clearNotifications, getNotifications, markNotificationRead, markAllNotificationsRead } from '../api/notifications'
import { useNotificationStore } from '../store/notificationStore'
import { useSettingsStore } from '../store/settingsStore'

export default function NotificationCenter({ onNavigate }) {
  const language=useSettingsStore(state=>state.language)
  const notifications = useNotificationStore((state) => state.notifications)
  const unreadCount = useNotificationStore((state) => state.unreadCount)
  const setNotifications = useNotificationStore((state) => state.setNotifications)
  const markAsRead = useNotificationStore((state) => state.markAsRead)
  const markAllAsRead = useNotificationStore((state) => state.markAllAsRead)
  const [isOpen, setIsOpen] = useState(false)
  const [clearBusy, setClearBusy] = useState(false)
  const panelRef = useRef(null)
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
    } catch {
      setNotifications([])
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
      if (panelRef.current && !panelRef.current.contains(event.target)) {
        setIsOpen(false)
      }
    }

    function handleEscape(event) {
      if (event.key === 'Escape') {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleEscape)
    return () => {
      window.removeEventListener('resize', updatePanelPosition)
      window.removeEventListener('scroll', updatePanelPosition, true)
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isOpen, updatePanelPosition])

  const handleMarkAsRead = async (id) => {
    markAsRead(id)
    try {
      await markNotificationRead(id)
    } catch {
    }
  }

  const handleMarkAllAsRead = async () => {
    markAllAsRead()
    try {
      await markAllNotificationsRead()
    } catch {
    }
  }

  const handleClear = async () => {
    if (!notifications.length || clearBusy) return
    setClearBusy(true)
    try {
      await clearNotifications('all')
      setNotifications([])
    } catch {
    } finally {
      setClearBusy(false)
    }
  }

  const handleNotificationClick = (notification) => {
    handleMarkAsRead(notification.id)
    const data = notification.data || {}
    let path = '/dashboard'
    if (data.order_intake_id) path = `/order-hub?intake=${data.order_intake_id}`
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
          <div className="notification-backdrop" onClick={() => setIsOpen(false)} />
          <div className="notification-dropdown" ref={panelRef} style={panelStyle}>
            <div className="notification-header">
              <h3>Notifications</h3>
              <div className="notification-header-actions">
                {unreadCount > 0 && (
                  <button type="button" className="mark-read-btn" onClick={handleMarkAllAsRead}>
                    Mark all read
                  </button>
                )}
                <>
                  <button type="button" className="mark-read-btn notification-clear-btn" onClick={handleClear} disabled={clearBusy || notifications.length === 0} aria-label="Clear notifications">
                    <Trash2 size={13} />
                    {clearBusy ? 'Clearing…' : 'Clear'}
                  </button>
                </>
                <button type="button" className="close-btn" onClick={() => setIsOpen(false)}>
                  <X size={16} />
                </button>
              </div>
            </div>

            <div className="notification-list">
              {notifications.length === 0 ? (
                <div className="notification-empty">
                  <Package size={32} />
                  <p>No notifications yet</p>
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
        aria-label="Open notifications"
        onClick={toggleOpen}
      >
        <Bell size={20} />
        {unreadCount > 0 && <span className="notification-badge">{unreadCount}</span>}
      </button>
      {dropdown}
    </div>
  )
}
