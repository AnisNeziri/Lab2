import { apiRequest, buildApiUrl } from './client'

export async function getSuppliers() {
  return apiRequest(buildApiUrl('/suppliers'), {}, 'Failed to load suppliers')
}

export async function createSupplier(payload) {
  return apiRequest(
    buildApiUrl('/suppliers'),
    {
      method: 'POST',
      body: JSON.stringify(payload),
    },
    'Failed to save supplier'
  )
}

export async function updateSupplier(id, payload) {
  return apiRequest(
    buildApiUrl(`/suppliers/${id}`),
    {
      method: 'PUT',
      body: JSON.stringify(payload),
    },
    'Failed to update supplier'
  )
}

export async function deleteSupplier(id) {
  await apiRequest(
    buildApiUrl(`/suppliers/${id}`),
    {
      method: 'DELETE',
    },
    'Failed to delete supplier'
  )
}
