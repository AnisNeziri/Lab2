import { useState, useEffect, useCallback, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Bell, X, AlertTriangle, Package } from 'lucide-react'
import { getNotifications, markNotificationRead, markAllNotificationsRead } from '../api/notifications'
import { useNotificationStore } from '../store/notificationStore'

export default function NotificationCenter({ onViewStock }) {
  const notifications = useNotificationStore((state) => state.notifications)
  const unreadCount = useNotificationStore((state) => state.unreadCount)
  const setNotifications = useNotificationStore((state) => state.setNotifications)
  const markAsRead = useNotificationStore((state) => state.markAsRead)
  const markAllAsRead = useNotificationStore((state) => state.markAllAsRead)
  const [isOpen, setIsOpen] = useState(false)
  const panelRef = useRef(null)

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
    if (!isOpen) {
      return undefined
    }

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
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isOpen])

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

  const handleNotificationClick = (notification) => {
    handleMarkAsRead(notification.id)
    if (onViewStock) {
      onViewStock()
      setIsOpen(false)
    }
  }

  const dropdown = isOpen
    ? createPortal(
        <>
          <div className="notification-backdrop" onClick={() => setIsOpen(false)} />
          <div className="notification-dropdown" ref={panelRef}>
            <div className="notification-header">
              <h3>Notifications</h3>
              <div className="notification-header-actions">
                {unreadCount > 0 && (
                  <button type="button" className="mark-read-btn" onClick={handleMarkAllAsRead}>
                    Mark all read
                  </button>
                )}
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
                      <p className="notification-title">{notification.title}</p>
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
        aria-expanded={isOpen}
        aria-label="Open notifications"
        onClick={() => setIsOpen((open) => !open)}
      >
        <Bell size={20} />
        {unreadCount > 0 && <span className="notification-badge">{unreadCount}</span>}
      </button>
      {dropdown}
    </div>
  )
}
