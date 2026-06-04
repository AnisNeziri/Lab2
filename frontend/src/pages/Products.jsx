import { useEffect, useState } from 'react'
import { getCategories } from '../api/categories'
import { getSuppliers } from '../api/suppliers'
import {
  createProduct,
  deleteProduct,
  exportProducts,
  getProducts,
  updateProduct,
} from '../api/products'
import ProductDetail from '../components/ProductDetail'
import StockBadge from '../components/StockBadge'

const emptyForm = {
  category_id: '',
  supplier_id: '',
  name: '',
  sku: '',
  description: '',
  quantity: 0,
  min_quantity: 5,
  price: '',
  purchase_price: '',
  selling_price: '',
}

function Products() {
  const [products, setProducts] = useState([])
  const [pagination, setPagination] = useState(null)
  const [categories, setCategories] = useState([])
  const [suppliers, setSuppliers] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [search, setSearch] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [lowStockOnly, setLowStockOnly] = useState(false)
  const [sortBy, setSortBy] = useState('name')
  const [sortDirection, setSortDirection] = useState('asc')
  const [page, setPage] = useState(1)
  const [editingId, setEditingId] = useState(null)
  const [viewProductId, setViewProductId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')

  async function loadCategories() {
    const categoriesData = await getCategories()
    setCategories(categoriesData)
  }

  async function loadSuppliers() {
    const suppliersData = await getSuppliers()
    setSuppliers(suppliersData)
  }

  async function loadProducts(filters = {}) {
    try {
      setLoading(true)
      setError('')
      const response = await getProducts(filters)
      setProducts(response.data)
      setPagination({
        current_page: response.current_page,
        last_page: response.last_page,
        per_page: response.per_page,
        total: response.total,
      })
    } catch {
      setError('Could not load products. Make sure the API is running.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    Promise.all([loadCategories(), loadSuppliers()]).catch(() => {
      setError('Could not load categories or suppliers. Make sure the API is running.')
    })
  }, [])

  function getActiveFilters(targetPage = page) {
    return {
      search: search.trim(),
      category_id: categoryFilter,
      supplier_id: supplierFilter,
      low_stock: lowStockOnly,
      sort: sortBy,
      direction: sortDirection,
      page: targetPage,
      per_page: 10,
    }
  }

  useEffect(() => {
    setPage(1)
  }, [search, categoryFilter, supplierFilter, lowStockOnly, sortBy, sortDirection])

  useEffect(() => {
    const timer = setTimeout(() => {
      loadProducts(getActiveFilters(page))
    }, 300)

    return () => clearTimeout(timer)
  }, [search, categoryFilter, supplierFilter, lowStockOnly, sortBy, sortDirection, page])

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
      supplier_id: product.supplier_id ?? '',
      name: product.name,
      sku: product.sku,
      description: product.description ?? '',
      quantity: product.quantity,
      min_quantity: product.min_quantity ?? 5,
      price: product.price,
      purchase_price: product.purchase_price ?? '',
      selling_price: product.selling_price ?? '',
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
    setSupplierFilter('')
    setLowStockOnly(false)
    setSortBy('name')
    setSortDirection('asc')
    setPage(1)
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
      supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
      name: form.name,
      sku: form.sku,
      description: form.description || null,
      quantity: Number(form.quantity),
      min_quantity: Number(form.min_quantity),
      price: Number(form.price),
      purchase_price: form.purchase_price ? Number(form.purchase_price) : null,
      selling_price: form.selling_price ? Number(form.selling_price) : null,
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

  async function handleDelete(product) {
    const confirmed = window.confirm(`Delete "${product.name}"? This cannot be undone.`)

    if (!confirmed) {
      return
    }

    try {
      await deleteProduct(product.id)
      if (editingId === product.id) {
        cancelEdit()
      }
      if (viewProductId === product.id) {
        setViewProductId(null)
      }
      await loadProducts(getActiveFilters())
    } catch {
      setError('Could not delete product.')
    }
  }

  const hasFilters =
    search.trim() !== '' ||
    categoryFilter !== '' ||
    supplierFilter !== '' ||
    lowStockOnly ||
    sortBy !== 'name' ||
    sortDirection !== 'asc'

  return (
    <main className="products-page">
      {viewProductId && (
        <ProductDetail productId={viewProductId} onClose={() => setViewProductId(null)} />
      )}

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
            Supplier
            <select name="supplier_id" value={form.supplier_id} onChange={handleChange}>
              <option value="">No supplier</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
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

          <div className="form-row">
            <label>
              Purchase Price
              <input
                name="purchase_price"
                type="number"
                min="0"
                step="0.01"
                value={form.purchase_price}
                onChange={handleChange}
                placeholder="Cost price"
              />
            </label>

            <label>
              Selling Price
              <input
                name="selling_price"
                type="number"
                min="0"
                step="0.01"
                value={form.selling_price}
                onChange={handleChange}
                placeholder="Retail price"
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
        <div className="section-header">
          <h2>Product list</h2>
          <div className="section-header-actions">
            {!loading && pagination && (
              <p className="result-count">
                {pagination.total} product(s) - page {pagination.current_page} of{' '}
                {pagination.last_page}
              </p>
            )}
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

          <label>
            Supplier
            <select
              value={supplierFilter}
              onChange={(event) => setSupplierFilter(event.target.value)}
            >
              <option value="">All suppliers</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
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
          <>
            <table className="product-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Supplier</th>
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
                    <td>{product.category?.name ?? '-'}</td>
                    <td>{product.supplier?.name ?? '-'}</td>
                    <td>{product.sku}</td>
                    <td>
                      <StockBadge quantity={product.quantity} minQuantity={product.min_quantity} />
                    </td>
                    <td>{product.min_quantity}</td>
                    <td>${Number(product.price).toFixed(2)}</td>
                    <td className="actions">
                      <button
                        type="button"
                        className="secondary"
                        onClick={() => setViewProductId(product.id)}
                      >
                        View
                      </button>
                      <button type="button" className="secondary" onClick={() => startEdit(product)}>
                        Edit
                      </button>
                      <button
                        type="button"
                        className="danger"
                        onClick={() => handleDelete(product)}
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {pagination && pagination.last_page > 1 && (
              <div className="pagination">
                <button
                  type="button"
                  className="secondary"
                  disabled={page <= 1}
                  onClick={() => setPage((current) => current - 1)}
                >
                  Previous
                </button>
                <span>
                  Page {pagination.current_page} of {pagination.last_page}
                </span>
                <button
                  type="button"
                  className="secondary"
                  disabled={page >= pagination.last_page}
                  onClick={() => setPage((current) => current + 1)}
                >
                  Next
                </button>
              </div>
            )}
          </>
        )}
      </section>
    </main>
  )
}

export default Products
