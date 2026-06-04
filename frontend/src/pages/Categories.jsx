import { useEffect, useState } from 'react'
import {
  createCategory,
  deleteCategory,
  getCategories,
  updateCategory,
} from '../api/categories'

function Categories({ userRole = 'staff' }) {
  const [categories, setCategories] = useState([])
  const [name, setName] = useState('')
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')

  async function loadCategories() {
    try {
      setLoading(true)
      setError('')
      const data = await getCategories()
      setCategories(data)
    } catch {
      setError('Could not load categories.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadCategories()
  }, [])

  function startEdit(category) {
    setEditingId(category.id)
    setName(category.name)
    setFormError('')
  }

  function cancelEdit() {
    setEditingId(null)
    setName('')
    setFormError('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError('')

    try {
      if (editingId) {
        await updateCategory(editingId, name)
      } else {
        await createCategory(name)
      }

      cancelEdit()
      await loadCategories()
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else if (err.message) {
        setFormError(err.message)
      } else {
        setFormError(editingId ? 'Could not update category.' : 'Could not save category.')
      }
    }
  }

  async function handleDelete(category) {
    setFormError('')

    const confirmed = window.confirm(
      `Delete "${category.name}"? Categories with products cannot be removed.`
    )

    if (!confirmed) {
      return
    }

    try {
      await deleteCategory(category.id)
      if (editingId === category.id) {
        cancelEdit()
      }
      await loadCategories()
    } catch (err) {
      if (err.message) {
        setFormError(err.message)
      } else {
        setFormError('Could not delete category.')
      }
    }
  }

  return (
    <main className="categories-page">
      <section className="card">
        <h2>{editingId ? 'Edit category' : 'Add category'}</h2>
        <form className="category-form" onSubmit={handleSubmit}>
          <label>
            Name
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="e.g. Electronics"
              required
            />
          </label>

          {formError && <p className="error">{formError}</p>}

          <div className="form-actions">
            <button type="submit">{editingId ? 'Update category' : 'Save category'}</button>
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
          <h2>All categories</h2>
          {!loading && <p className="result-count">{categories.length} category(s)</p>}
        </div>

        {loading && <p>Loading categories...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && categories.length === 0 && (
          <p>No categories yet. Add one above or run the database seeder.</p>
        )}

        {!loading && categories.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Products</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {categories.map((category) => (
                <tr key={category.id} className={editingId === category.id ? 'editing' : ''}>
                  <td>{category.name}</td>
                  <td>{category.products_count}</td>
                  <td className="actions">
                    <button type="button" className="secondary" onClick={() => startEdit(category)}>
                      Edit
                    </button>
                    {(userRole === 'admin' || userRole === 'manager') && (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => handleDelete(category)}
                        disabled={category.products_count > 0}
                        title={
                          category.products_count > 0
                            ? 'Remove or reassign products before deleting'
                            : ''
                        }
                      >
                        Delete
                      </button>
                    )}
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

export default Categories
