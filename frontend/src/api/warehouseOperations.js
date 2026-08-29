import { apiRequest, authenticatedFetch, buildApiUrl, parseApiResponse } from './client'

export const getWarehouses = () => apiRequest('/warehouses', {}, 'Could not load warehouses.')
export const createWarehouse = (payload) => apiRequest('/warehouses', { method: 'POST', body: JSON.stringify(payload) })
export const updateWarehouse = (id, payload) => apiRequest(`/warehouses/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
export const deleteWarehouse = (id) => apiRequest(`/warehouses/${id}`, { method: 'DELETE' })

export const getWarehouseLocations = (warehouseId) => apiRequest(buildApiUrl('/warehouse-locations', { warehouse_id: warehouseId }))
export const createWarehouseLocation = (payload) => apiRequest('/warehouse-locations', { method: 'POST', body: JSON.stringify(payload) })
export const updateWarehouseLocation = (id, payload) => apiRequest(`/warehouse-locations/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
export const deleteWarehouseLocation = (id) => apiRequest(`/warehouse-locations/${id}`, { method: 'DELETE' })

export const getStockTransfers = (filters = {}) => apiRequest(buildApiUrl('/stock-transfers', filters))
export const getStockTransfer = (id) => apiRequest(`/stock-transfers/${id}`)
export const createStockTransfer = (payload) => apiRequest('/stock-transfers', { method: 'POST', body: JSON.stringify(payload) })
export const updateStockTransfer = (id, payload) => apiRequest(`/stock-transfers/${id}`, { method: 'PUT', body: JSON.stringify(payload) })
export const dispatchStockTransfer = (id, idempotencyKey) => apiRequest(`/stock-transfers/${id}/dispatch`, { method: 'POST', body: JSON.stringify({ idempotency_key: idempotencyKey }) })
export const receiveStockTransfer = (id, payload) => apiRequest(`/stock-transfers/${id}/receive`, { method: 'POST', body: JSON.stringify(payload) })
export const cancelStockTransfer = (id, reason) => apiRequest(`/stock-transfers/${id}/cancel`, { method: 'POST', body: JSON.stringify({ reason }) })

export const getGoodsReceipts = (filters = {}) => apiRequest(buildApiUrl('/goods-receipts', filters))
export const getGoodsReceipt = (id) => apiRequest(`/goods-receipts/${id}`)

export async function downloadGoodsReceiptPdf(id, receiptNumber = id) {
  const response = await authenticatedFetch(buildApiUrl(`/goods-receipts/${id}/pdf`))
  if (!response.ok) await parseApiResponse(response, 'Could not download the receipt.')
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${receiptNumber}.pdf`
  link.click()
  URL.revokeObjectURL(url)
}
