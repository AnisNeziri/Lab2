import { apiRequest } from './client'

export function getNotifications() {
  return apiRequest('/notifications')
}

export function markNotificationRead(id) {
  return apiRequest(`/notifications/${id}/read`, { method: 'POST' })
}

export function markAllNotificationsRead() {
  return apiRequest('/notifications/read-all', { method: 'POST' })
}

export function clearNotifications(scope = 'all') {
  return apiRequest('/notifications/clear', {
    method: 'POST',
    body: JSON.stringify({ scope }),
  })
}
