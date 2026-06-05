import { apiRequest, buildApiUrl, parseApiResponse } from './client'

export async function getProducts(filters = {}) {
  return apiRequest(
    buildApiUrl('/products', {
      search: filters.search,
      category_id: filters.category_id,
      supplier_id: filters.supplier_id,
      low_stock: filters.low_stock ? '1' : '',
      sort: filters.sort,
      direction: filters.direction,
      page: filters.page,
      per_page: filters.per_page,
    }),
    {},
    'Failed to load products'
  )
}

export async function getProductDetail(id) {
  return apiRequest(buildApiUrl(`/products/${id}`), {}, 'Failed to load product')
}

export async function createProduct(product) {
  return apiRequest(
    buildApiUrl('/products'),
    {
      method: 'POST',
      body: JSON.stringify(product),
    },
    'Failed to save product'
  )
}

export async function updateProduct(id, product) {
  return apiRequest(
    buildApiUrl(`/products/${id}`),
    {
      method: 'PUT',
      body: JSON.stringify(product),
    },
    'Failed to update product'
  )
}

export async function deleteProduct(id) {
  await apiRequest(
    buildApiUrl(`/products/${id}`),
    {
      method: 'DELETE',
    },
    'Failed to delete product'
  )
}

export async function exportProducts() {
  const token = localStorage.getItem('api_token')
  const response = await fetch(buildApiUrl('/products/export'), {
    headers: {
      Accept: 'text/csv',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  })

  if (!response.ok) {
    await parseApiResponse(response, 'Failed to export products')
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'products.csv'
  link.click()
  URL.revokeObjectURL(url)
}
