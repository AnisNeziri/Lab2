import { useEffect, useState } from 'react'
import { getReports } from '../api/reports'
import { Download, Upload } from 'lucide-react'
import { downloadExport } from '../api/export'
import { importList } from '../api/import'

const DATA_LISTS = [
  { id: 'products', label: 'Products' },
  { id: 'categories', label: 'Categories' },
  { id: 'suppliers', label: 'Suppliers' },
  { id: 'stock_movements', label: 'Stock movements' },
  { id: 'invoices', label: 'Invoices' },
]

const FORMATS = ['csv', 'json', 'xlsx']

function Reports() {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [busyKey, setBusyKey] = useState('')

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

  const handleExport = async (list, format) => {
    const key = `export-${list}-${format}`
    try {
      setBusyKey(key)
      setError('')
      setMessage('')
      await downloadExport(list, format)
    } catch (err) {
      setError(err.message || `Could not export ${list}.`)
    } finally {
      setBusyKey('')
    }
  }

  const handleImport = async (list, event) => {
    const file = event.target.files?.[0]
    event.target.value = ''

    if (!file) return

    const key = `import-${list}`
    try {
      setBusyKey(key)
      setError('')
      setMessage('')
      const result = await importList(list, file)
      setMessage(`Imported ${result.records_imported || 0} of ${result.records_total || 0} ${list} rows.`)
    } catch (err) {
      setError(err.message || `Could not import ${list}.`)
    } finally {
      setBusyKey('')
    }
  }

  if (loading) {
    return (
      <main className="reports-page page-stack">
        <p className="page-intro">Loading reports...</p>
      </main>
    )
  }

  if (!data) {
    return (
      <main className="reports-page page-stack">
        <p className="page-intro">{error || 'No report data.'}</p>
      </main>
    )
  }

  return (
    <main className="reports-page page-stack">
      <section className="card">
        <h2>Reports</h2>
        <p className="page-intro">Stock summaries plus export and import for the main data lists.</p>
        {error && <div className="form-error-banner">{error}</div>}
        {message && <div className="success-banner">{message}</div>}
      </section>

      <section className="card">
        <h3>Export / import</h3>
        <div className="table-wrap">
          <table className="product-table">
            <thead>
              <tr>
                <th>List</th>
                <th>Export</th>
                <th>Import</th>
              </tr>
            </thead>
            <tbody>
              {DATA_LISTS.map((list) => (
                <tr key={list.id}>
                  <td>{list.label}</td>
                  <td className="table-actions">
                    {FORMATS.map((format) => (
                      <button
                        key={format}
                        type="button"
                        className="secondary"
                        disabled={busyKey === `export-${list.id}-${format}`}
                        onClick={() => handleExport(list.id, format)}
                      >
                        <Download size={14} />
                        {busyKey === `export-${list.id}-${format}` ? '...' : format.toUpperCase()}
                      </button>
                    ))}
                  </td>
                  <td>
                    <label className="import-file-label">
                      <Upload size={14} />
                      {busyKey === `import-${list.id}` ? 'Importing...' : 'Choose file'}
                      <input
                        type="file"
                        accept=".csv,.json,.xlsx,.txt"
                        hidden
                        onChange={(event) => handleImport(list.id, event)}
                      />
                    </label>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

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
