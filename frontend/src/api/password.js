import { apiRequest } from './client'

export function changePassword(payload) {
  return apiRequest('/change-password', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Could not update password.')
}
