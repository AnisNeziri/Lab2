import { useEffect, useState } from 'react'
import { getCategories } from '../api/categories'
import { createProduct, deleteProduct, getProducts, updateProduct } from '../api/products'

const emptyForm = {
  category_id: '',
  name: '',
  sku: '',
  description: '',
  quantity: 0,
  price: '',
}

function Products() {
  const [products, setProducts] = useState([])
  const [categories, setCategories] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')

  async function loadData() {
    try {
      setLoading(true)
      setError('')
      const [productsData, categoriesData] = await Promise.all([
        getProducts(),
        getCategories(),
      ])
      setProducts(productsData)
      setCategories(categoriesData)
    } catch {
      setError('Could not load data. Make sure the API is running.')
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

  function startEdit(product) {
    setEditingId(product.id)
    setFormError('')
    setForm({
      category_id: product.category_id ?? '',
      name: product.name,
      sku: product.sku,
      description: product.description ?? '',
      quantity: product.quantity,
      price: product.price,
    })
  }

  function cancelEdit() {
    setEditingId(null)
    setForm(emptyForm)
    setFormError('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError('')

    const payload = {
      category_id: Number(form.category_id),
      name: form.name,
      sku: form.sku,
      description: form.description || null,
      quantity: Number(form.quantity),
      price: Number(form.price),
    }

    try {
      if (editingId) {
        await updateProduct(editingId, payload)
      } else {
        await createProduct(payload)
      }

      cancelEdit()
      await loadData()
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else {
        setFormError(editingId ? 'Could not update product.' : 'Could not save product.')
      }
    }
  }

  async function handleDelete(id) {
    try {
      await deleteProduct(id)
      if (editingId === id) {
        cancelEdit()
      }
      await loadData()
    } catch {
      setError('Could not delete product.')
    }
  }

  return (
    <main className="products-page">
      <section className="card">
        <h2>{editingId ? 'Edit product' : 'Add product'}</h2>
        <form className="product-form" onSubmit={handleSubmit}>
          <label>
            Category
            <select name="category_id" value={form.category_id} onChange={handleChange} required>
              <option value="">Select a category</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Name
            <input name="name" value={form.name} onChange={handleChange} required />
          </label>

          <label>
            SKU
            <input name="sku" value={form.sku} onChange={handleChange} required />
          </label>

          <label>
            Description
            <textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              rows={3}
            />
          </label>

          <div className="form-row">
            <label>
              Quantity
              <input
                name="quantity"
                type="number"
                min="0"
                value={form.quantity}
                onChange={handleChange}
                required
              />
            </label>

            <label>
              Price
              <input
                name="price"
                type="number"
                min="0"
                step="0.01"
                value={form.price}
                onChange={handleChange}
                required
              />
            </label>
          </div>

          {formError && <p className="error">{formError}</p>}

          <div className="form-actions">
            <button type="submit">{editingId ? 'Update product' : 'Save product'}</button>
            {editingId && (
              <button type="button" className="secondary" onClick={cancelEdit}>
                Cancel
              </button>
            )}
          </div>
        </form>
      </section>

      <section className="card">
        <h2>Product list</h2>

        {loading && <p>Loading products...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && products.length === 0 && (
          <p>No products yet. Add your first item above.</p>
        )}

        {!loading && products.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Category</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Price</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {products.map((product) => (
                <tr key={product.id} className={editingId === product.id ? 'editing' : ''}>
                  <td>{product.name}</td>
                  <td>{product.category?.name ?? '—'}</td>
                  <td>{product.sku}</td>
                  <td>{product.quantity}</td>
                  <td>${Number(product.price).toFixed(2)}</td>
                  <td className="actions">
                    <button type="button" className="secondary" onClick={() => startEdit(product)}>
                      Edit
                    </button>
                    <button
                      type="button"
                      className="danger"
                      onClick={() => handleDelete(product.id)}
                    >
                      Delete
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}

export default Products
