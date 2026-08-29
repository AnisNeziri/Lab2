import { apiRequest } from './client'

export function getSystemMode() {
  return apiRequest('/system/mode', {}, 'Unable to determine system mode')
}
