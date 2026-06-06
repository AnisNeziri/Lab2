import { useCallback, useEffect, useState } from 'react'
import { getAllProducts } from '../api/products'
import {
  createStockMovement,
  exportStockMovements,
  getStockMovements,
  lookupProductBySku,
} from '../api/stock'

const emptyForm = {
  product_id: '',
  type: 'in',
  quantity: 1,
  reason: '',
}

function Stock() {
  const [products, setProducts] = useState([])
  const [movements, setMovements] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [filters, setFilters] = useState({ product_id: '', type: '' })
  const [skuLookup, setSkuLookup] = useState('')
  const [lookupMessage, setLookupMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [exporting, setExporting] = useState(false)

  const loadMovements = useCallback(async (activeFilters = filters) => {
    const movementFilters = {}
    if (activeFilters.product_id) {
      movementFilters.product_id = Number(activeFilters.product_id)
    }
    if (activeFilters.type) {
      movementFilters.type = activeFilters.type
    }
    return getStockMovements(movementFilters)
  }, [filters])

  async function loadData() {
    try {
      setLoading(true)
      setError('')
      const [productsList, movementsData] = await Promise.all([
        getAllProducts(),
        loadMovements(),
      ])
      setProducts(productsList)
      setMovements(movementsData)
    } catch {
      setError('Could not load stock data.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
  }, [])

  async function applyFilters(event) {
    event.preventDefault()
    try {
      setLoading(true)
      setError('')
      const movementsData = await loadMovements(filters)
      setMovements(movementsData)
    } catch {
      setError('Could not filter stock movements.')
    } finally {
      setLoading(false)
    }
  }

  function clearFilters() {
    const cleared = { product_id: '', type: '' }
    setFilters(cleared)
    setLoading(true)
    loadMovements(cleared)
      .then((movementsData) => setMovements(movementsData))
      .catch(() => setError('Could not load stock movements.'))
      .finally(() => setLoading(false))
  }

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({
      ...current,
      [name]: value,
    }))
  }

  function handleFilterChange(event) {
    const { name, value } = event.target
    setFilters((current) => ({
      ...current,
      [name]: value,
    }))
  }

  async function handleSkuLookup(event) {
    event.preventDefault()
    setLookupMessage('')
    setFormError('')

    if (!skuLookup.trim()) {
      return
    }

    try {
      const product = await lookupProductBySku(skuLookup.trim())
      setForm((current) => ({
        ...current,
        product_id: String(product.id),
      }))
      setLookupMessage(`Selected: ${product.name} (${product.quantity} in stock)`)
    } catch {
      setLookupMessage('No product found for that SKU.')
    }
  }

  async function handleExport() {
    try {
      setExporting(true)
      const exportFilters = {}
      if (filters.product_id) {
        exportFilters.product_id = Number(filters.product_id)
      }
      if (filters.type) {
        exportFilters.type = filters.type
      }
      await exportStockMovements(exportFilters)
    } catch {
      setError('Could not export stock movements.')
    } finally {
      setExporting(false)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError('')

    try {
      await createStockMovement({
        product_id: Number(form.product_id),
        type: form.type,
        quantity: Number(form.quantity),
        reason: form.reason || null,
      })

      setForm(emptyForm)
      setSkuLookup('')
      setLookupMessage('')
      await loadData()
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else if (err.message) {
        setFormError(err.message)
      } else {
        setFormError('Could not record stock movement.')
      }
    }
  }

  return (
    <main className="stock-page">
      <section className="card">
        <h2>Adjust stock</h2>
        <p className="card-description">
          Record stock coming in or going out. Product quantity updates automatically.
        </p>

        <form className="sku-lookup-form" onSubmit={handleSkuLookup}>
          <label>
            Find by SKU
            <div className="form-row">
              <input
                value={skuLookup}
                onChange={(event) => setSkuLookup(event.target.value)}
                placeholder="e.g. ELEC-001"
              />
              <button type="submit" className="secondary">
                Find product
              </button>
            </div>
          </label>
          {lookupMessage && <p className="lookup-message">{lookupMessage}</p>}
        </form>

        <form className="stock-form" onSubmit={handleSubmit}>
          <label>
            Product
            <select name="product_id" value={form.product_id} onChange={handleChange} required>
              <option value="">Select a product</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name} ({product.sku}) - {product.quantity} in stock
                </option>
              ))}
            </select>
          </label>

          <div className="form-row">
            <label>
              Type
              <select name="type" value={form.type} onChange={handleChange} required>
                <option value="in">Stock in</option>
                <option value="out">Stock out</option>
              </select>
            </label>

            <label>
              Quantity
              <input
                name="quantity"
                type="number"
                min="1"
                value={form.quantity}
                onChange={handleChange}
                required
              />
            </label>
          </div>

          <label>
            Reason (optional)
            <input
              name="reason"
              value={form.reason}
              onChange={handleChange}
              placeholder="e.g. New delivery, sold to customer"
            />
          </label>

          {formError && <p className="error">{formError}</p>}

          <button type="submit">Record movement</button>
        </form>
      </section>

      <section className="card">
        <div className="section-header">
          <h2>Recent movements</h2>
          <div className="section-header-actions">
            {!loading && <p className="result-count">{movements.length} record(s)</p>}
            <button
              type="button"
              className="secondary"
              onClick={handleExport}
              disabled={exporting || loading}
            >
              {exporting ? 'Exporting...' : 'Export CSV'}
            </button>
          </div>
        </div>

        <form className="filter-form" onSubmit={applyFilters}>
          <label>
            Product
            <select name="product_id" value={filters.product_id} onChange={handleFilterChange}>
              <option value="">All products</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Type
            <select name="type" value={filters.type} onChange={handleFilterChange}>
              <option value="">All types</option>
              <option value="in">Stock in</option>
              <option value="out">Stock out</option>
            </select>
          </label>

          <div className="form-actions">
            <button type="submit">Apply filters</button>
            <button type="button" className="secondary" onClick={clearFilters}>
              Clear
            </button>
          </div>
        </form>

        {loading && <p>Loading stock history...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && movements.length === 0 && (
          <p>No stock movements recorded yet.</p>
        )}

        {!loading && movements.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Before</th>
                <th>After</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              {movements.map((movement) => (
                <tr key={movement.id}>
                  <td>{new Date(movement.created_at).toLocaleString()}</td>
                  <td>{movement.product?.name}</td>
                  <td>
                    <span className={`badge badge-${movement.type}`}>
                      {movement.type === 'in' ? 'Stock in' : 'Stock out'}
                    </span>
                  </td>
                  <td>{movement.quantity}</td>
                  <td>{movement.quantity_before}</td>
                  <td>{movement.quantity_after}</td>
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

export default Stock
