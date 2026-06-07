import { create } from 'zustand'

export const useNotificationStore = create((set, get) => ({
  notifications: [],
  unreadCount: 0,

  setNotifications: (notifications) => {
    set({
      notifications,
      unreadCount: notifications.filter((n) => !n.read_at).length,
    })
  },

  addNotification: (notification) => {
    const existing = get().notifications.find((n) => n.id === notification.id)
    if (existing) return

    set((state) => {
      const notifications = [notification, ...state.notifications]
      return {
        notifications,
        unreadCount: notifications.filter((n) => !n.read_at).length,
      }
    })
  },

  markAsRead: (id) => {
    set((state) => {
      const notifications = state.notifications.map((n) =>
        n.id === id ? { ...n, read_at: new Date().toISOString() } : n
      )
      return {
        notifications,
        unreadCount: notifications.filter((n) => !n.read_at).length,
      }
    })
  },

  markAllAsRead: () => {
    set((state) => ({
      notifications: state.notifications.map((n) => ({
        ...n,
        read_at: n.read_at || new Date().toISOString(),
      })),
      unreadCount: 0,
    }))
  },
}))
