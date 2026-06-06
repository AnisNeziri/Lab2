import { apiRequest, buildApiUrl, authenticatedFetch, parseApiResponse } from './client'

export async function getStockMovements(filters = {}) {
  return apiRequest(
    buildApiUrl('/stock-movements', {
      product_id: filters.product_id,
      type: filters.type,
    }),
    {},
    'Failed to load stock movements'
  )
}

export async function lookupProductBySku(sku) {
  return apiRequest(
    buildApiUrl('/products/lookup', { sku }),
    {},
    'Product not found'
  )
}

export async function exportStockMovements(filters = {}) {
  const response = await authenticatedFetch(
    buildApiUrl('/stock-movements/export', {
      product_id: filters.product_id,
      type: filters.type,
    }),
    {
      headers: { Accept: 'text/csv' },
    }
  )

  if (!response.ok) {
    await parseApiResponse(response, 'Failed to export stock movements')
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'stock-movements.csv'
  link.click()
  URL.revokeObjectURL(url)
}

export async function createStockMovement(movement) {
  return apiRequest(
    buildApiUrl('/stock-movements'),
    {
      method: 'POST',
      body: JSON.stringify(movement),
    },
    'Failed to record stock movement'
  )
}
