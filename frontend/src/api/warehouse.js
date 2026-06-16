import { apiRequest } from './client'

export function getWarehouseLayout() {
  return apiRequest('/warehouse/layout')
}

export function updateWarehouseLayout(payload) {
  return apiRequest('/warehouse/layout', {
    method: 'PUT',
    body: JSON.stringify(payload),
  }, 'Could not update warehouse.')
}

export function getWarehouseSections() {
  return apiRequest('/warehouse/sections')
}

export function getSectionDistribution() {
  return apiRequest('/warehouse/sections/distribution')
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
