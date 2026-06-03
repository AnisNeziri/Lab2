import { useEffect, useState } from 'react'
import { getReports } from '../api/reports'

function Reports() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadReports() {
      try {
        setLoading(true)
        setError('')
        const reports = await getReports()
        setData(reports)
      } catch {
        setError('Could not load reports.')
      } finally {
        setLoading(false)
      }
    }

    loadReports()
  }, [])

  if (loading) {
    return <p className="page-message">Loading reports...</p>
  }

  if (error) {
    return <p className="error page-message">{error}</p>
  }

  return (
    <main className="reports-page">
      <section className="stats-grid stats-grid-3">
        <article className="stat-card">
          <p className="stat-label">Total stock in</p>
          <p className="stat-value">{data.stock_summary.total_stock_in}</p>
        </article>
        <article className="stat-card">
          <p className="stat-label">Total stock out</p>
          <p className="stat-value">{data.stock_summary.total_stock_out}</p>
        </article>
        <article className="stat-card">
          <p className="stat-label">Stock movements</p>
          <p className="stat-value">{data.stock_summary.movement_count}</p>
        </article>
      </section>

      <section className="card">
        <h2>Inventory by category</h2>
        <table className="product-table">
          <thead>
            <tr>
              <th>Category</th>
              <th>Products</th>
              <th>Units</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            {data.categories.map((category) => (
              <tr key={category.id}>
                <td>{category.name}</td>
                <td>{category.product_count}</td>
                <td>{category.total_units}</td>
                <td>${Number(category.total_value).toFixed(2)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      <section className="card">
        <h2>Top products by stock value</h2>
        {data.top_products.length === 0 ? (
          <p>No products available.</p>
        ) : (
          <table className="product-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Qty</th>
                <th>Unit price</th>
                <th>Total value</th>
              </tr>
            </thead>
            <tbody>
              {data.top_products.map((product) => (
                <tr key={product.id}>
                  <td>{product.name}</td>
                  <td>{product.sku}</td>
                  <td>{product.category ?? '—'}</td>
                  <td>{product.quantity}</td>
                  <td>${Number(product.price).toFixed(2)}</td>
                  <td>${Number(product.value).toFixed(2)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}

export default Reports
