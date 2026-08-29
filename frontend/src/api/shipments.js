import { apiRequest } from './client'

export function getPorts() {
  return apiRequest('/shipments/ports', {}, 'Failed to load ports')
}

export function getShipments(params = {}) {
  const search = new URLSearchParams()
  if (params.archived !== undefined) search.set('archived', params.archived ? '1' : '0')
  if (params.saved) search.set('saved', '1')
  if (params.favorite) search.set('favorite', '1')
  const query = search.toString()
  return apiRequest(`/shipments${query ? `?${query}` : ''}`, {}, 'Failed to load shipments')
}

export function getShipment(id) {
  return apiRequest(`/shipments/${id}`, {}, 'Failed to load shipment')
}

export function validateTrackingNumber(trackingNumber, transportMode) {
  return apiRequest('/shipments/validate', {
    method: 'POST',
    body: JSON.stringify({ tracking_number: trackingNumber, transport_mode: transportMode }),
  }, 'Failed to validate tracking number')
}

export function trackShipment(payload) {
  return apiRequest('/shipments/track', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Failed to track shipment')
}

export function createAisShipment(payload) {
  return apiRequest('/shipments/ais', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Failed to register vessel tracking')
}

export function lookupVessel(identifier) {
  return apiRequest('/shipments/vessels/lookup', {
    method: 'POST',
    body: JSON.stringify({ identifier }),
  }, 'Failed to find vessel')
}

export function refreshShipment(id) {
  return apiRequest(`/shipments/${id}/refresh`, { method: 'POST' }, 'Failed to refresh shipment')
    .then((data) => data.shipment ?? data)
}

export function saveShipment(id) {
  return apiRequest(`/shipments/${id}/save`, { method: 'POST' }, 'Failed to save shipment')
}

export function favoriteShipment(id) {
  return apiRequest(`/shipments/${id}/favorite`, { method: 'POST' }, 'Failed to update favorite')
}

export function archiveShipment(id) {
  return apiRequest(`/shipments/${id}/archive`, { method: 'POST' }, 'Failed to archive shipment')
}

export function restoreShipment(id) {
  return apiRequest(`/shipments/${id}/restore`, { method: 'POST' }, 'Failed to restore shipment')
}

export function removeShipment(id) {
  return apiRequest(`/shipments/${id}`, { method: 'DELETE' }, 'Failed to remove shipment')
}

export function getShipmentHistory(id) {
  return apiRequest(`/shipments/${id}/history`, {}, 'Failed to load history')
}

export function getShipmentAlerts() {
  return apiRequest('/shipments/alerts', {}, 'Failed to load alerts')
}

export function clearShipmentAlerts() {
  return apiRequest('/shipments/alerts/clear', { method: 'POST' }, 'Failed to clear shipment alerts')
}

export function getPurchaseOrders() {
  return apiRequest('/purchase-orders', {}, 'Failed to load purchase orders')
}

export function getWarehouses() {
  return apiRequest('/warehouses', {}, 'Failed to load warehouses')
}

export function updateShipmentLogistics(id, payload) {
  return apiRequest(`/shipments/${id}/logistics`, { method: 'PUT', body: JSON.stringify(payload) }, 'Could not update shipment logistics')
}
