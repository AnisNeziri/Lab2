import { useEffect, useState } from 'react'
import { X } from 'lucide-react'
import { getProductDetail } from '../api/products'
import { formatQuantity } from '../utils/formatQuantity'
import { getStockStatus } from '../utils/stockStatus'
import { useTranslation } from '../hooks/useTranslation'

const trackingLabelKeys = {
  none: 'productTracking.none',
  batch: 'productTracking.batch',
  serial: 'productTracking.serial',
  batch_expiry: 'productTracking.batchExpiry',
}

function ProductDetail({ productId, onClose }) {
  const { t } = useTranslation()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadDetail() {
      try {
        setLoading(true)
        setError('')
        const detail = await getProductDetail(productId)
        setData(detail)
      } catch {
        setError('Could not load product details.')
      } finally {
        setLoading(false)
      }
    }

    loadDetail()
  }, [productId])

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal product-detail-modal" role="dialog" aria-modal="true" aria-labelledby="product-detail-title" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header">
          <h2 id="product-detail-title">Product details</h2>
          <button type="button" className="modal-close-btn" onClick={onClose} aria-label="Close product details" title="Close">
            <X size={20} />
          </button>
        </div>

        {loading && <p>Loading...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && data?.product && (
          <div className="modal-body">
            {data.product.image_url ? (
              <div className="product-detail-image-wrap">
                <img className="product-detail-image" src={data.product.image_url} alt={data.product.name} />
              </div>
            ) : null}
            <dl className="detail-list">
              <dt>Name</dt>
              <dd>{data.product.name}</dd>
              <dt>SKU</dt>
              <dd>{data.product.sku}</dd>
              <dt>Category</dt>
              <dd>{data.product.category?.name ?? '-'}</dd>
              <dt>Supplier</dt>
              <dd>{data.product.supplier?.name ?? '-'}</dd>
              {data.product.supplier?.email && (
                <>
                  <dt>Supplier email</dt>
                  <dd>{data.product.supplier.email}</dd>
                </>
              )}
              {data.product.supplier?.phone && (
                <>
                  <dt>Supplier phone</dt>
                  <dd>{data.product.supplier.phone}</dd>
                </>
              )}
              <dt>Quantity</dt>
              <dd className={getStockStatus(
                data.product.quantity,
                data.product.min_quantity,
                data.product.high_stock_threshold,
              ).key === 'low' ? 'low-stock' : ''}>
                {formatQuantity(data.product.quantity, data.product.unit)} {data.product.unit ?? 'pcs'}
              </dd>
              <dt>Min quantity</dt>
              <dd>{formatQuantity(data.product.min_quantity, data.product.unit)}</dd>
              <dt>High stock at</dt>
              <dd>{Number(data.product.high_stock_threshold) > 0 ? formatQuantity(data.product.high_stock_threshold, data.product.unit) : 'Auto'}</dd>
              <dt>{t('productTracking.mode')}</dt>
              <dd>{t(trackingLabelKeys[data.product.tracking_mode || 'none'] || 'productTracking.none')}</dd>
              {['batch', 'batch_expiry'].includes(data.product.tracking_mode) ? (
                <>
                  <dt>{t('productTracking.nearExpiryDays')}</dt>
                  <dd>{data.product.near_expiry_days ?? 30}</dd>
                  <dt>FEFO</dt>
                  <dd>{data.product.fefo_enabled !== false ? t('productTracking.enabled') : t('productTracking.disabled')}</dd>
                </>
              ) : null}
              <dt>{t('productPlanning.safetyStock')}</dt>
              <dd>{formatQuantity(data.product.safety_stock ?? 0, data.product.unit)}</dd>
              <dt>{t('productPlanning.reorderPoint')}</dt>
              <dd>{data.product.reorder_point == null ? t('productPlanning.automatic') : formatQuantity(data.product.reorder_point, data.product.unit)}</dd>
              <dt>{t('productPlanning.historyDays')}</dt>
              <dd>{data.product.replenishment_history_days ?? 90}</dd>
              <dt>{t('productPlanning.reviewDays')}</dt>
              <dd>{data.product.replenishment_review_days ?? 14}</dd>
              <dt>{t('productPlanning.weight')}</dt>
              <dd>{data.product.weight_kg == null ? '—' : Number(data.product.weight_kg).toLocaleString()}</dd>
              <dt>{t('productPlanning.volume')}</dt>
              <dd>{data.product.volume_m3 == null ? '—' : Number(data.product.volume_m3).toLocaleString()}</dd>
              <dt>Purchase price</dt>
              <dd>{data.product.purchase_price != null ? `€${Number(data.product.purchase_price).toFixed(2)}` : '-'}</dd>
              <dt>Selling price</dt>
              <dd>€{Number(data.product.selling_price ?? data.product.price ?? 0).toFixed(2)}</dd>
              <dt>Stock value</dt>
              <dd>
                €{(data.product.quantity * Number(data.product.selling_price ?? data.product.price ?? 0)).toFixed(2)}
              </dd>
              <dt>Description</dt>
              <dd>{data.product.description || '-'}</dd>
            </dl>

            <h3>{t('productUnits.conversions')}</h3>
            <div className="unit-chip-list">
              <div className="unit-chip"><strong>{data.product.unit || 'pcs'}</strong><small>{t('productUnits.baseInventoryUnit')}</small></div>
              {(data.product.units || []).filter((unit) => unit.is_active !== false && unit.conversion_mode === 'fixed').map((unit) => (
                <div className="unit-chip" key={unit.id}>
                  <strong>{unit.label || unit.code}</strong>
                  <small>{`1 ${unit.code} = ${formatQuantity(unit.factor_to_base, data.product.unit)} ${data.product.unit}`}</small>
                  {Number(unit.factor_to_base) > 0 ? (
                    <small className="unit-chip-stock">
                      {t('productUnits.currentPackStock', {
                        packs: Math.floor(Number(data.product.quantity || 0) / Number(unit.factor_to_base)),
                        packUnit: unit.code,
                        remainder: formatQuantity(Number(data.product.quantity || 0) % Number(unit.factor_to_base), data.product.unit),
                        baseUnit: data.product.unit || 'pcs',
                      })}
                    </small>
                  ) : null}
                </div>
              ))}
            </div>

            <h3>{t('productUnits.warehouseBalances')}</h3>
            {data.product.warehouse_stock?.length ? (
              <div className="table-wrap product-warehouse-table"><table className="product-table"><thead><tr><th>{t('warehouseOps.warehouse')}</th><th>{t('warehouseOps.available')}</th><th>{t('warehouseOps.reserved')}</th><th>{t('warehouseOps.damaged')}</th><th>{t('warehouseOps.location')}</th></tr></thead><tbody>
                {data.product.warehouse_stock.map((balance) => <tr key={balance.id}><td>{balance.warehouse?.name}</td><td>{formatQuantity(balance.available_quantity, data.product.unit)}</td><td>{formatQuantity(balance.reserved_quantity, data.product.unit)}</td><td>{formatQuantity(balance.damaged_quantity, data.product.unit)}</td><td>{balance.location?.path || '—'}</td></tr>)}
              </tbody></table></div>
            ) : <p>{t('productUnits.noWarehouseStock')}</p>}

            <h3>Stock history</h3>
            {!Array.isArray(data.movements) || data.movements.length === 0 ? (
              <p>No stock movements for this product.</p>
            ) : (
              <table className="product-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Before</th>
                    <th>After</th>
                    <th>Reason</th>
                    <th>{t('warehouseOps.warehouse')}</th>
                  </tr>
                </thead>
                <tbody>
                {(data.movements ?? []).map((movement) => (
                    <tr key={movement.id}>
                      <td>{new Date(movement.created_at).toLocaleString()}</td>
                      <td>
                        <span className={`badge badge-${movement.type}`}>
                          {movement.type === 'in' ? 'Stock in' : 'Stock out'}
                        </span>
                      </td>
                      <td>{formatQuantity(movement.quantity, data.product.unit)}</td>
                      <td>{formatQuantity(movement.quantity_before, data.product.unit)}</td>
                      <td>{formatQuantity(movement.quantity_after, data.product.unit)}</td>
                      <td>{movement.reason ?? '-'}</td>
                      <td>{movement.warehouse?.name || '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

export default ProductDetail
