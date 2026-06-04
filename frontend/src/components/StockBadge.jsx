export default function StockBadge({ quantity, minQuantity }) {
  const getStockStatus = () => {
    if (quantity === 0) return { status: 'Out of Stock', className: 'out-of-stock' }
    if (quantity <= minQuantity) return { status: 'Low Stock', className: 'low-stock' }
    return { status: 'In Stock', className: 'in-stock' }
  }

  const { status, className } = getStockStatus()

  return (
    <span className={`stock-badge ${className}`}>
      {status}
    </span>
  )
}
