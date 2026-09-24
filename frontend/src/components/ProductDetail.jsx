import { Fragment, useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { Boxes, ImageOff, Package, X } from 'lucide-react'
import { getProductDetail } from '../api/products'
import { formatQuantity } from '../utils/formatQuantity'
import { getStockStatus } from '../utils/stockStatus'
import { getInventoryQuantity, getWarehouseOnHand } from '../utils/inventoryQuantity'
import { useTranslation } from '../hooks/useTranslation'
import EntityContext from './EntityContext'
import { Link } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'

const trackingLabelKeys = { none: 'productTracking.none', batch: 'productTracking.batch', serial: 'productTracking.serial', batch_expiry: 'productTracking.batchExpiry' }
const money = (value) => value == null || value === '' ? '—' : `€${Number(value).toFixed(2)}`

function ProductDetail({ productId, onClose }) {
  const { t, language } = useTranslation()
  const canViewOrders=useAuthStore(s=>s.permissions.includes('fulfillment.view'))
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [imageFailed, setImageFailed] = useState(false)

  useEffect(() => {
    let active = true
    setImageFailed(false); setLoading(true); setError('')
    getProductDetail(productId)
      .then((detail) => { if (active) setData(detail) })
      .catch(() => { if (active) setError('Could not load product details.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [productId])

  useEffect(() => {
    // ProductDetail is the only active modal here. Treat a leftover `hidden`
    // value from an earlier render as stale so closing always restores scrolling.
    const previousOverflow = document.body.style.overflow === 'hidden' ? '' : document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [])

  useEffect(() => {
    const closeOnEscape = (event) => { if (event.key === 'Escape') onClose() }
    window.addEventListener('keydown', closeOnEscape)
    return () => window.removeEventListener('keydown', closeOnEscape)
  }, [onClose])

  const product = data?.product
  const stock = useMemo(() => product ? {
    onHand: getInventoryQuantity(product, 'on_hand'), available: getInventoryQuantity(product, 'available'),
    reserved: getInventoryQuantity(product, 'reserved'), incoming: getInventoryQuantity(product, 'incoming'), projected: getInventoryQuantity(product, 'projected'),
  } : null, [product])
  const status = product && stock ? getStockStatus(stock.available, product.min_quantity, product.high_stock_threshold) : null
  const margin = product?.purchase_price != null && Number(product.selling_price ?? product.price) > 0 ? Number(product.selling_price ?? product.price) - Number(product.purchase_price) : null

  return createPortal(<div className="modal-overlay product-detail-overlay" onClick={onClose}>
    <section className="modal product-detail-modal" role="dialog" aria-modal="true" aria-labelledby="product-detail-title" onClick={(event) => event.stopPropagation()}>
      <div className="modal-header product-detail-header"><div><small>Inventory product</small><h2 id="product-detail-title">{product?.name || 'Product details'}</h2></div><button type="button" className="modal-close-btn" onClick={onClose} aria-label="Close product details"><X size={20} /></button></div>
      {loading && <div className="product-detail-state"><Package size={30} /><p>Loading product details…</p></div>}
      {error && <div className="product-detail-state is-error"><p>{error}</p><button type="button" className="secondary" onClick={onClose}>Close</button></div>}
      {!loading && !error && product && stock && <div className="modal-body product-detail-body">
        <section className="product-detail-overview">
          <div className="product-detail-image-wrap">{product.image_url && !imageFailed ? <img className="product-detail-image" src={product.image_url} alt={product.name} onError={() => setImageFailed(true)} /> : <div className="product-detail-image-empty"><ImageOff size={34} /><span>No product image</span></div>}</div>
          <div className="product-detail-identity"><div className="product-detail-title-row"><div><h3>{product.name}</h3><p>{product.category?.name || 'Uncategorised'} · {product.supplier?.name || 'No supplier'}</p></div><span className={`product-lifecycle product-lifecycle-${product.lifecycle_status || 'active'}`}>{t(`productMaster.${product.lifecycle_status || 'active'}`)}</span></div><div className="product-detail-identifiers"><span><small>SKU</small><strong>{product.sku || '—'}</strong></span><span><small>{t('productMaster.primaryBarcode')}</small><strong>{product.barcode || '—'}</strong></span><span><small>Unit</small><strong>{product.unit || 'pcs'}</strong></span></div>{product.description ? <p className="product-detail-description">{product.description}</p> : null}</div>
        </section>
        <section className="product-detail-stock-grid">
          <article className={`is-primary stock-${status?.key || 'normal'}`}><span>Available to sell</span><strong>{formatQuantity(stock.available, product.unit)}</strong><small>{product.unit || 'pcs'} · {status?.label || 'Stock'}</small></article>
          <article><span>{t('productUnits.onHand')}</span><strong>{formatQuantity(stock.onHand, product.unit)}</strong><small>{product.unit || 'pcs'}</small></article>
          <article><span>{t('productUnits.reserved')}</span><strong>{formatQuantity(stock.reserved, product.unit)}</strong><small>{product.unit || 'pcs'}</small></article>
          <article><span>{t('productUnits.incoming')}</span><strong>{formatQuantity(stock.incoming, product.unit)}</strong><small>Projected: {formatQuantity(stock.projected, product.unit)}</small></article>
        </section>
        <div className="product-detail-columns">
          <section className="product-detail-section"><h3>Product information</h3><dl className="detail-list"><dt>{t('productMaster.brand')}</dt><dd>{product.brand || '—'}</dd><dt>Purchase price</dt><dd>{money(product.purchase_price)}</dd><dt>Selling price</dt><dd>{money(product.selling_price ?? product.price)}</dd><dt>Margin per unit</dt><dd>{margin == null ? '—' : money(margin)}</dd><dt>Minimum quantity</dt><dd>{formatQuantity(product.min_quantity, product.unit)}</dd><dt>High stock at</dt><dd>{Number(product.high_stock_threshold) > 0 ? formatQuantity(product.high_stock_threshold, product.unit) : 'Automatic'}</dd><dt>Warehouse section</dt><dd>{product.location_code || '—'}</dd><dt>{t('productTracking.mode')}</dt><dd>{t(trackingLabelKeys[product.tracking_mode || 'none'])}</dd></dl></section>
          <section className="product-detail-section"><h3>Inventory status</h3><dl className="detail-list"><dt>{t('productUnits.damaged')}</dt><dd>{formatQuantity(getInventoryQuantity(product, 'damaged'), product.unit)}</dd><dt>{t('productUnits.quarantine')}</dt><dd>{formatQuantity(getInventoryQuantity(product, 'quarantine'), product.unit)}</dd><dt>{t('productUnits.blocked')}</dt><dd>{formatQuantity(getInventoryQuantity(product, 'blocked'), product.unit)}</dd><dt>{t('productPlanning.safetyStock')}</dt><dd>{formatQuantity(product.safety_stock ?? 0, product.unit)}</dd><dt>{t('productPlanning.reorderPoint')}</dt><dd>{product.reorder_point == null ? t('productPlanning.automatic') : formatQuantity(product.reorder_point, product.unit)}</dd><dt>Stock value at cost</dt><dd>{money(product.inventory_value ?? (stock.onHand * Number(product.weighted_average_cost ?? product.purchase_price ?? 0)))}</dd></dl></section>
        </div>
        <section className="product-detail-section"><h3><Boxes size={18} /> {t('productUnits.warehouseBalances')}</h3>{product.warehouse_stock?.length ? <div className="table-wrap product-warehouse-table"><table className="product-table"><thead><tr><th>{t('warehouseOps.warehouse')}</th><th>{t('productUnits.onHand')}</th><th>Available to sell</th><th>{t('productUnits.reserved')}</th><th>{t('warehouseOps.location')}</th></tr></thead><tbody>{product.warehouse_stock.map((balance) => <tr key={balance.id}><td>{balance.warehouse?.name || '—'}</td><td>{formatQuantity(getWarehouseOnHand(balance), product.unit)}</td><td>{formatQuantity(balance.available_quantity, product.unit)}</td><td>{formatQuantity(balance.reserved_quantity, product.unit)}</td><td>{balance.location?.path || '—'}</td></tr>)}</tbody></table></div> : <p>{t('productUnits.noWarehouseStock')}</p>}</section>
        <details className="product-detail-section product-detail-more"><summary>More product information</summary><dl className="detail-list"><dt>{t('productMaster.alternativeBarcodes')}</dt><dd>{(product.alternative_barcodes || []).filter((row) => row.is_active !== false).map((row) => row.label ? `${row.barcode} (${row.label})` : row.barcode).join(', ') || '—'}</dd><dt>{t('productPlanning.weight')}</dt><dd>{product.weight_kg ?? '—'}</dd><dt>{t('productPlanning.volume')}</dt><dd>{product.volume_m3 ?? '—'}</dd><dt>{t('productMaster.length')}</dt><dd>{product.length_cm ?? '—'}</dd><dt>{t('productMaster.width')}</dt><dd>{product.width_cm ?? '—'}</dd><dt>{t('productMaster.height')}</dt><dd>{product.height_cm ?? '—'}</dd><dt>{t('productMaster.origin')}</dt><dd>{product.country_of_origin || '—'}</dd><dt>{t('productMaster.hsCode')}</dt><dd>{product.hs_code || '—'}</dd>{Object.entries(product.attributes || {}).map(([key, value]) => <Fragment key={key}><dt>{key}</dt><dd>{String(value)}</dd></Fragment>)}</dl></details>
        <EntityContext entityType="product" entityId={product.id} />
        {canViewOrders&&<Link to={`/order-hub?product=${product.id}`} onClick={onClose}>{language==='sq'?'Shiko porositë për këtë produkt':'View orders for this product'}</Link>}
        <section className="product-detail-section"><h3>Stock history</h3>{!data.movements?.length ? <p>No stock movements for this product.</p> : <div className="table-wrap"><table className="product-table"><thead><tr><th>Date</th><th>Type</th><th>Quantity</th><th>Before</th><th>After</th><th>Reason</th></tr></thead><tbody>{data.movements.map((movement) => <tr key={movement.id}><td>{new Date(movement.created_at).toLocaleString()}</td><td><span className={`badge badge-${movement.type}`}>{movement.type === 'in' ? 'Stock in' : 'Stock out'}</span></td><td>{formatQuantity(movement.quantity, product.unit)}</td><td>{formatQuantity(movement.quantity_before, product.unit)}</td><td>{formatQuantity(movement.quantity_after, product.unit)}</td><td>{movement.reason || '—'}</td></tr>)}</tbody></table></div>}</section>
      </div>}
    </section>
  </div>, document.body)
}

export default ProductDetail
