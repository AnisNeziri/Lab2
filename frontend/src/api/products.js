const API_BASE = '/api'

export async function getProducts() {
  const response = await fetch(`${API_BASE}/products`)

  if (!response.ok) {
    throw new Error('Failed to load products')
  }

  return response.json()
}

export async function createProduct(product) {
  const response = await fetch(`${API_BASE}/products`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(product),
  })

  if (!response.ok) {
    const error = await response.json()
    throw error
  }

  return response.json()
}

export async function deleteProduct(id) {
  const response = await fetch(`${API_BASE}/products/${id}`, {
    method: 'DELETE',
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    throw new Error('Failed to delete product')
  }
}
