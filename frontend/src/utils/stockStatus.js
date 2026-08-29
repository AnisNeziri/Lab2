export function getStockStatus(quantity, minQuantity, highStockThreshold = 0) {
  const qty = Number(quantity ?? 0)
  const min = Number(minQuantity ?? 0)
  const high = Number(highStockThreshold ?? 0)

  if (!Number.isFinite(qty) || qty <= 0) {
    return { key: 'out', label: 'Out of Stock', className: 'out-of-stock' }
  }
  if (Number.isFinite(min) && qty <= min) {
    return { key: 'low', label: 'Low Stock', className: 'low-stock' }
  }
  if (Number.isFinite(high) && high > 0 && qty >= high) {
    return { key: 'high', label: 'High Stock', className: 'high-stock' }
  }
  return { key: 'normal', label: 'In Stock', className: 'in-stock' }
}
