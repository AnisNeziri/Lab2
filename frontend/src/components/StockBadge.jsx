import { getStockStatus } from '../utils/stockStatus'

export default function StockBadge({ quantity, minQuantity, highStockThreshold }) {
  const { label: status, className } = getStockStatus(quantity, minQuantity, highStockThreshold)

  return (
    <span className={`stock-badge ${className}`}>
      {status}
    </span>
  )
}
