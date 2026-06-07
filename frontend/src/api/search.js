import { apiRequest } from './client'

export function globalSearch(query) {
  return apiRequest(`/search?q=${encodeURIComponent(query)}`)
}
