import { useEffect, useState } from 'react'
import { getCategories } from '../api/categories'
import { createProduct, deleteProduct, exportProducts, getProducts, updateProduct } from '../api/products'

const emptyForm = {
  category_id: '',
  name: '',
  sku: '',
  description: '',
  quantity: 0,
  min_quantity: 5,
  price: '',
}

function Products() {
  const [products, setProducts] = useState([])
  const [categories, setCategories] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [search, setSearch] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [lowStockOnly, setLowStockOnly] = useState(false)
  const [sortBy, setSortBy] = useState('name')
  const [sortDirection, setSortDirection] = useState('asc')
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')

  async function loadCategories() {
    const categoriesData = await getCategories()
    setCategories(categoriesData)
  }

  async function loadProducts(filters = {}) {
    try {
      setLoading(true)
      setError('')
      const productsData = await getProducts(filters)
      setProducts(productsData)
    } catch {
      setError('Could not load products. Make sure the API is running.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadCategories().catch(() => {
      setError('Could not load categories. Make sure the API is running.')
    })
  }, [])

  function getActiveFilters() {
    return {
      search: search.trim(),
      category_id: categoryFilter,
      low_stock: lowStockOnly,
      sort: sortBy,
      direction: sortDirection,
    }
  }

  useEffect(() => {
    const timer = setTimeout(() => {
      loadProducts(getActiveFilters())
    }, 300)

    return () => clearTimeout(timer)
  }, [search, categoryFilter, lowStockOnly, sortBy, sortDirection])

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
      min_quantity: product.min_quantity ?? 5,
      price: product.price,
    })
  }

  function cancelEdit() {
    setEditingId(null)
    setForm(emptyForm)
    setFormError('')
  }

  function clearFilters() {
    setSearch('')
    setCategoryFilter('')
    setLowStockOnly(false)
    setSortBy('name')
    setSortDirection('asc')
  }

  async function handleExport() {
    try {
      await exportProducts()
    } catch {
      setError('Could not export products.')
    }
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
      min_quantity: Number(form.min_quantity),
      price: Number(form.price),
    }

    try {
      if (editingId) {
        await updateProduct(editingId, payload)
      } else {
        await createProduct(payload)
      }

      cancelEdit()
      await loadProducts(getActiveFilters())
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
      await loadProducts(getActiveFilters())
    } catch {
      setError('Could not delete product.')
    }
  }

  const hasFilters =
    search.trim() !== '' ||
    categoryFilter !== '' ||
    lowStockOnly ||
    sortBy !== 'name' ||
    sortDirection !== 'asc'

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
              Min quantity
              <input
                name="min_quantity"
                type="number"
                min="0"
                value={form.min_quantity}
                onChange={handleChange}
                required
              />
            </label>
          </div>

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
        <div className="section-header">
          <h2>Product list</h2>
          <div className="section-header-actions">
            {!loading && <p className="result-count">{products.length} product(s)</p>}
            <button type="button" className="secondary" onClick={handleExport}>
              Export CSV
            </button>
          </div>
        </div>

        <div className="filters">
          <label>
            Search
            <input
              type="search"
              placeholder="Search by name or SKU"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </label>

          <label>
            Category
            <select
              value={categoryFilter}
              onChange={(event) => setCategoryFilter(event.target.value)}
            >
              <option value="">All categories</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
          </label>

          <label className="filter-checkbox">
            <input
              type="checkbox"
              checked={lowStockOnly}
              onChange={(event) => setLowStockOnly(event.target.checked)}
            />
            Low stock only
          </label>

          <label>
            Sort by
            <select value={sortBy} onChange={(event) => setSortBy(event.target.value)}>
              <option value="name">Name</option>
              <option value="sku">SKU</option>
              <option value="quantity">Quantity</option>
              <option value="min_quantity">Min quantity</option>
              <option value="price">Price</option>
            </select>
          </label>

          <label>
            Order
            <select
              value={sortDirection}
              onChange={(event) => setSortDirection(event.target.value)}
            >
              <option value="asc">Ascending</option>
              <option value="desc">Descending</option>
            </select>
          </label>

          {hasFilters && (
            <button type="button" className="secondary" onClick={clearFilters}>
              Clear filters
            </button>
          )}
        </div>

        {loading && <p>Loading products...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && products.length === 0 && !hasFilters && (
          <p>No products yet. Add your first item above.</p>
        )}

        {!loading && !error && products.length === 0 && hasFilters && (
          <p>No products match your search or filter.</p>
        )}

        {!loading && products.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Category</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Min</th>
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
                  <td className={product.quantity <= product.min_quantity ? 'low-stock' : ''}>
                    {product.quantity}
                  </td>
                  <td>{product.min_quantity}</td>
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
