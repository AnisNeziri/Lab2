import { apiRequest, buildApiUrl } from './client'

export function getWarehouseLayout(warehouseId) {
  return apiRequest(buildApiUrl('/warehouse/layout', { warehouse_id: warehouseId }))
}

export function updateWarehouseLayout(payload) {
  return apiRequest('/warehouse/layout', {
    method: 'PUT',
    body: JSON.stringify(payload),
  }, 'Could not update warehouse.')
}

export function getWarehouseSections(warehouseId) {
  return apiRequest(buildApiUrl('/warehouse/sections', { warehouse_id: warehouseId }))
}

export function getSectionDistribution(warehouseId) {
  return apiRequest(buildApiUrl('/warehouse/sections/distribution', { warehouse_id: warehouseId }))
}

export function createWarehouseSection(payload) {
  return apiRequest('/warehouse/sections', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Could not create section.')
}

export function updateWarehouseSection(id, payload) {
  return apiRequest(`/warehouse/sections/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }, 'Could not update section.')
}

export function deleteWarehouseSection(id) {
  return apiRequest(`/warehouse/sections/${id}`, {
    method: 'DELETE',
  }, 'Could not delete section.')
}
