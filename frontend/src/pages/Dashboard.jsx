import { useEffect, useState } from 'react'
import { getDashboard } from '../api/dashboard'

function Dashboard() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadDashboard() {
      try {
        setLoading(true)
        setError('')
        const summary = await getDashboard()
        setData(summary)
      } catch {
        setError('Could not load dashboard data.')
      } finally {
        setLoading(false)
      }
    }

    loadDashboard()
  }, [])

  if (loading) {
    return <p className="page-message">Loading dashboard...</p>
  }

  if (error) {
    return <p className="error page-message">{error}</p>
  }

  return (
    <main className="dashboard-page">
      <section className="stats-grid">
        <article className="stat-card">
          <p className="stat-label">Total products</p>
          <p className="stat-value">{data.total_products}</p>
        </article>

        <article className="stat-card">
          <p className="stat-label">Total units in stock</p>
          <p className="stat-value">{data.total_units}</p>
        </article>

        <article className="stat-card">
          <p className="stat-label">Inventory value</p>
          <p className="stat-value">${Number(data.total_value).toFixed(2)}</p>
        </article>

        <article className="stat-card warning">
          <p className="stat-label">Low stock items</p>
          <p className="stat-value">{data.low_stock_count}</p>
          <p className="stat-note">At or below each product&apos;s minimum quantity</p>
        </article>
      </section>

      <section className="card">
        <h2>Low stock alerts</h2>

        {data.low_stock_products.length === 0 ? (
          <p>All products are above the low stock threshold.</p>
        ) : (
          <table className="product-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Min</th>
              </tr>
            </thead>
            <tbody>
              {data.low_stock_products.map((product) => (
                <tr key={product.id}>
                  <td>{product.name}</td>
                  <td>{product.category?.name ?? '-'}</td>
                  <td>{product.sku}</td>
                  <td className="low-stock">{product.quantity}</td>
                  <td>{product.min_quantity}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section className="card">
        <h2>Recent stock activity</h2>

        {data.recent_movements.length === 0 ? (
          <p>No stock movements yet.</p>
        ) : (
          <table className="product-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              {data.recent_movements.map((movement) => (
                <tr key={movement.id}>
                  <td>{new Date(movement.created_at).toLocaleString()}</td>
                  <td>{movement.product?.name ?? '-'}</td>
                  <td>
                    <span className={`badge badge-${movement.type}`}>
                      {movement.type === 'in' ? 'Stock in' : 'Stock out'}
                    </span>
                  </td>
                  <td>{movement.quantity}</td>
                  <td>{movement.reason ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}

export default Dashboard
