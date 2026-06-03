import { useEffect, useState } from 'react'
import { getProductDetail } from '../api/products'

function ProductDetail({ productId, onClose }) {
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
      <div className="modal" onClick={(event) => event.stopPropagation()}>
        <div className="modal-header">
          <h2>Product details</h2>
          <button type="button" className="secondary" onClick={onClose}>
            Close
          </button>
        </div>

        {loading && <p>Loading...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && data && (
          <div className="modal-body">
            <dl className="detail-list">
              <dt>Name</dt>
              <dd>{data.product.name}</dd>
              <dt>SKU</dt>
              <dd>{data.product.sku}</dd>
              <dt>Category</dt>
              <dd>{data.product.category?.name ?? '—'}</dd>
              <dt>Quantity</dt>
              <dd
                className={
                  data.product.quantity <= data.product.min_quantity ? 'low-stock' : ''
                }
              >
                {data.product.quantity}
              </dd>
              <dt>Min quantity</dt>
              <dd>{data.product.min_quantity}</dd>
              <dt>Price</dt>
              <dd>${Number(data.product.price).toFixed(2)}</dd>
              <dt>Stock value</dt>
              <dd>
                ${(data.product.quantity * Number(data.product.price)).toFixed(2)}
              </dd>
              <dt>Description</dt>
              <dd>{data.product.description || '—'}</dd>
            </dl>

            <h3>Stock history</h3>
            {data.movements.length === 0 ? (
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
                  </tr>
                </thead>
                <tbody>
                  {data.movements.map((movement) => (
                    <tr key={movement.id}>
                      <td>{new Date(movement.created_at).toLocaleString()}</td>
                      <td>
                        <span className={`badge badge-${movement.type}`}>
                          {movement.type === 'in' ? 'Stock in' : 'Stock out'}
                        </span>
                      </td>
                      <td>{movement.quantity}</td>
                      <td>{movement.quantity_before}</td>
                      <td>{movement.quantity_after}</td>
                      <td>{movement.reason ?? '—'}</td>
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
