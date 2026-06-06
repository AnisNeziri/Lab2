import { useState, useEffect, useCallback } from 'react'
import { Bell, X, AlertTriangle, Package } from 'lucide-react'
import { getProducts } from '../api/products'

export default function NotificationCenter() {
  const [notifications, setNotifications] = useState([])
  const [isOpen, setIsOpen] = useState(false)
  const [unreadCount, setUnreadCount] = useState(0)

  const fetchNotifications = useCallback(async () => {
    try {
      const data = await getProducts({ low_stock: true, per_page: 50 })
      const products = data.data || []

      setNotifications((previous) => {
        const readIds = new Set(previous.filter((item) => item.read).map((item) => item.id))

        const next = products.map((product) => ({
          id: product.id,
          type: 'low_stock',
          title: 'Low Stock Alert',
          message: `${product.name} is running low (${product.quantity} units left)`,
          productId: product.id,
          timestamp: new Date(),
          read: readIds.has(product.id),
        }))

        setUnreadCount(next.filter((item) => !item.read).length)
        return next
      })
    } catch {
      setNotifications([])
      setUnreadCount(0)
    }
  }, [])

  useEffect(() => {
    fetchNotifications()
    const interval = setInterval(fetchNotifications, 300000)
    return () => clearInterval(interval)
  }, [fetchNotifications])

  const markAsRead = (id) => {
    setNotifications((previous) =>
      previous.map((notif) =>
        notif.id === id ? { ...notif, read: true } : notif
      )
    )
    setUnreadCount((previous) => Math.max(0, previous - 1))
  }

  const markAllAsRead = () => {
    setNotifications((previous) => previous.map((notif) => ({ ...notif, read: true })))
    setUnreadCount(0)
  }

  return (
    <div className="notification-center">
      <button
        type="button"
        className="notification-bell"
        onClick={() => setIsOpen(!isOpen)}
      >
        <Bell size={20} />
        {unreadCount > 0 && (
          <span className="notification-badge">{unreadCount}</span>
        )}
      </button>

      {isOpen && (
        <div className="notification-dropdown">
          <div className="notification-header">
            <h3>Notifications</h3>
            <div className="notification-header-actions">
              {unreadCount > 0 && (
                <button
                  type="button"
                  className="mark-read-btn"
                  onClick={markAllAsRead}
                >
                  Mark all as read
                </button>
              )}
              <button
                type="button"
                className="close-btn"
                onClick={() => setIsOpen(false)}
              >
                <X size={16} />
              </button>
            </div>
          </div>

          <div className="notification-list">
            {notifications.length === 0 ? (
              <div className="notification-empty">
                <Package size={32} />
                <p>No notifications</p>
              </div>
            ) : (
              notifications.map((notification) => (
                <div
                  key={notification.id}
                  className={`notification-item ${notification.read ? 'read' : 'unread'}`}
                  onClick={() => markAsRead(notification.id)}
                >
                  <div className="notification-icon">
                    <AlertTriangle size={18} />
                  </div>
                  <div className="notification-content">
                    <p className="notification-title">{notification.title}</p>
                    <p className="notification-message">{notification.message}</p>
                    <p className="notification-time">
                      {notification.timestamp.toLocaleTimeString()}
                    </p>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      )}
    </div>
  )
}
