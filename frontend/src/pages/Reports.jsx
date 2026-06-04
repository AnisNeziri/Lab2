import { useEffect, useState } from 'react'
import { getReports } from '../api/reports'
import { Download } from 'lucide-react'
import { getProducts } from '../api/products'

function Reports() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [exporting, setExporting] = useState(false)

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

  const handleExportCSV = async () => {
    try {
      setExporting(true)
      const response = await getProducts({ per_page: 1000 })
      const products = response.data || response

      // Create CSV content
      const headers = ['Name', 'SKU', 'Category', 'Quantity', 'Min Quantity', 'Price', 'Total Value']
      const csvContent = [
        headers.join(','),
        ...products.map(product => [
          `"${product.name}"`,
          `"${product.sku}"`,
          `"${product.category?.name || ''}"`,
          product.quantity,
          product.min_quantity,
          product.price,
          (product.quantity * product.price).toFixed(2)
        ].join(','))
      ].join('\n')

      // Create and download CSV file
      const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
      const link = document.createElement('a')
      const url = URL.createObjectURL(blob)
      link.setAttribute('href', url)
      link.setAttribute('download', `products-export-${new Date().toISOString().split('T')[0]}.csv`)
      link.style.visibility = 'hidden'
      document.body.appendChild(link)
      link.click()
      document.body.removeChild(link)
    } catch (err) {
      setError('Failed to export products to CSV.')
    } finally {
      setExporting(false)
    }
  }

  if (loading) {
    return <p className="page-message">Loading reports...</p>
  }

  if (error) {
    return <p className="error page-message">{error}</p>
  }

  return (
    <main className="reports-page">
      <div className="section-header">
        <h1>Reports</h1>
        <div className="section-header-actions">
          <button
            type="button"
            className="secondary"
            onClick={handleExportCSV}
            disabled={exporting}
          >
            <Download size={16} style={{ marginRight: '0.5rem' }} />
            {exporting ? 'Exporting...' : 'Export Products CSV'}
          </button>
        </div>
      </div>

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
        <h2>Inventory by supplier</h2>
        <table className="product-table">
          <thead>
            <tr>
              <th>Supplier</th>
              <th>Products</th>
              <th>Units</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            {data.suppliers.map((supplier) => (
              <tr key={supplier.id}>
                <td>{supplier.name}</td>
                <td>{supplier.product_count}</td>
                <td>{supplier.total_units}</td>
                <td>${Number(supplier.total_value).toFixed(2)}</td>
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
                  <td>{product.category ?? '-'}</td>
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
