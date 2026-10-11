import { apiRequest, buildApiUrl, authenticatedFetch, parseApiResponse } from './client'

export async function getStockMovements(filters = {}) {
  return apiRequest(
    buildApiUrl('/stock-movements', {
      product_id: filters.product_id,
      type: filters.type,
      page: filters.page,
      per_page: filters.per_page,
      from: filters.from,
      to: filters.to,
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
  const fileHandle = window.showSaveFilePicker ? await window.showSaveFilePicker({ suggestedName: 'stock-movements.csv', types: [{ description: 'CSV', accept: { 'text/csv': ['.csv'] } }] }) : null
  const response = await authenticatedFetch(
    buildApiUrl('/stock-movements/export', {
      product_id: filters.product_id,
      type: filters.type,
      page: filters.page,
      per_page: filters.per_page,
      from: filters.from,
      to: filters.to,
    }),
    {
      headers: { Accept: 'text/csv' },
    }
  )

  if (!response.ok) {
    await parseApiResponse(response, 'Failed to export stock movements')
  }

  if (fileHandle) {
    const writable = await fileHandle.createWritable()
    try { await response.body.pipeTo(writable) } catch (error) { await writable.abort().catch(() => {}); throw error }
    return
  }
  // Browsers without a filesystem stream must keep downloads bounded.
  const reader = response.body.getReader()
  const chunks = []
  let bytes = 0
  try {
    while (true) {
      const { done, value } = await reader.read()
      if (done) break
      bytes += value.byteLength
      if (bytes > 16 * 1024 * 1024) {
        await reader.cancel()
        throw new Error('Export exceeds 16 MB. Narrow the product or date filters and try again.')
      }
      chunks.push(value)
    }
  } finally { reader.releaseLock() }
  const blob = new Blob(chunks, { type: 'text/csv' })
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
