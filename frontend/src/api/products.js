const API_BASE = '/api'

export async function getProducts(filters = {}) {
  const params = new URLSearchParams()

  if (filters.search) {
    params.set('search', filters.search)
  }

  if (filters.category_id) {
    params.set('category_id', filters.category_id)
  }

  if (filters.low_stock) {
    params.set('low_stock', '1')
  }

  if (filters.sort) {
    params.set('sort', filters.sort)
  }

  if (filters.direction) {
    params.set('direction', filters.direction)
  }

  if (filters.page) {
    params.set('page', filters.page)
  }

  if (filters.per_page) {
    params.set('per_page', filters.per_page)
  }

  const query = params.toString()
  const url = query ? `${API_BASE}/products?${query}` : `${API_BASE}/products`
  const response = await fetch(url)

  if (!response.ok) {
    throw new Error('Failed to load products')
  }

  return response.json()
}

export async function getProductDetail(id) {
  const response = await fetch(`${API_BASE}/products/${id}`)

  if (!response.ok) {
    throw new Error('Failed to load product')
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

export async function updateProduct(id, product) {
  const response = await fetch(`${API_BASE}/products/${id}`, {
    method: 'PUT',
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

export async function exportProducts() {
  const response = await fetch(`${API_BASE}/products/export`)

  if (!response.ok) {
    throw new Error('Failed to export products')
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'products.csv'
  link.click()
  URL.revokeObjectURL(url)
}
