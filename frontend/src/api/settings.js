import { apiRequest } from './client'

export function getPreferences() {
  return apiRequest('/settings/preferences', {}, 'Could not load settings.')
}

export function updatePreferences(preferences) {
  return apiRequest('/settings/preferences', {
    method: 'PUT',
    body: JSON.stringify(preferences),
  }, 'Could not save settings.')
}
