import { useEffect, useState } from 'react'
import { getProducts } from '../api/products'
import { createStockMovement, getStockMovements } from '../api/stock'

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
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')

  async function loadData() {
    try {
      setLoading(true)
      setError('')
      const [productsResponse, movementsData] = await Promise.all([
        getProducts({ per_page: 100 }),
        getStockMovements(),
      ])
      setProducts(productsResponse.data)
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

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({
      ...current,
      [name]: value,
    }))
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
          {!loading && <p className="result-count">{movements.length} record(s)</p>}
        </div>

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
