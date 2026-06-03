const API_BASE = '/api'

export async function getSuppliers() {
  const response = await fetch(`${API_BASE}/suppliers`)

  if (!response.ok) {
    throw new Error('Failed to load suppliers')
  }

  return response.json()
}

export async function createSupplier(payload) {
  const response = await fetch(`${API_BASE}/suppliers`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    const error = await response.json()
    throw error
  }

  return response.json()
}

export async function updateSupplier(id, payload) {
  const response = await fetch(`${API_BASE}/suppliers/${id}`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    const error = await response.json()
    throw error
  }

  return response.json()
}

export async function deleteSupplier(id) {
  const response = await fetch(`${API_BASE}/suppliers/${id}`, {
    method: 'DELETE',
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    const error = await response.json()
    throw error
  }
}
