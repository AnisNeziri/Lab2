import { useEffect, useState } from 'react'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'
import {
  createCategory,
  deleteCategory,
  getCategories,
  updateCategory,
} from '../api/categories'

function Categories() {
  const { language } = useTranslation(), text = (en,sq) => language === 'sq' ? sq : en
  const userRole = useAuthStore((state) => state.role)
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
      `Delete "${category.name}"? Products in this category will be left without a category.`
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
        <h1>{editingId ? text('Edit category','Ndrysho kategorinë') : text('Add category','Shto kategori')}</h1>
        <form className="category-form" onSubmit={handleSubmit}>
          <label>
            {text('Name','Emri')}
            <input
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder={text('e.g. Electronics','p.sh. Elektronikë')}
              required
            />
          </label>

          {formError && <p className="error">{formError}</p>}

          <div className="form-actions">
            <button type="submit">{editingId ? text('Update category','Përditëso kategorinë') : text('Save category','Ruaj kategorinë')}</button>
            {editingId && (
              <button type="button" className="secondary" onClick={cancelEdit}>
                {text('Cancel','Anulo')}
              </button>
            )}
          </div>
        </form>
      </section>

      <section className="card">
        <div className="section-header">
          <h2>{text('All categories','Të gjitha kategoritë')}</h2>
          {!loading && <p className="result-count">{categories.length} {text('categories','kategori')}</p>}
        </div>

        {loading && <p>{text('Loading categories…','Duke ngarkuar kategoritë…')}</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && categories.length === 0 && (
          <p>{text('No categories yet. Add your first category above.','Ende nuk ka kategori. Shtoni kategorinë e parë më sipër.')}</p>
        )}

        {!loading && categories.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>{text('Name','Emri')}</th>
                <th>{text('Products','Produktet')}</th>
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
                      {text('Edit','Ndrysho')}
                    </button>
                    {(userRole === 'admin' || userRole === 'manager') && (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => handleDelete(category)}
                      >
                        {text('Delete','Fshi')}
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
