import { apiRequest } from './client'

export function getVessels(params = {}) {
  const search = new URLSearchParams()
  if (params.origin) search.set('origin', params.origin)
  if (params.destination) search.set('destination', params.destination)
  if (params.search) search.set('search', params.search)
  const query = search.toString()
  return apiRequest(`/tracking/vessels${query ? `?${query}` : ''}`, {}, 'Failed to load vessels')
}

export function getVessel(id) {
  return apiRequest(`/tracking/vessels/${id}`, {}, 'Failed to load vessel')
}

export function getTrackingPorts() {
  return apiRequest('/tracking/ports', {}, 'Failed to load ports')
}

export function getTrackingMetrics(params = {}) {
  const search = new URLSearchParams()
  if (params.origin) search.set('origin', params.origin)
  if (params.destination) search.set('destination', params.destination)
  if (params.search) search.set('search', params.search)
  const query = search.toString()
  return apiRequest(`/tracking/metrics${query ? `?${query}` : ''}`, {}, 'Failed to load metrics')
}
