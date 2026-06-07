import { useEffect, useState } from 'react'
import { useAuthStore } from '../store/authStore'
import {
  createSupplier,
  deleteSupplier,
  getSuppliers,
  updateSupplier,
} from '../api/suppliers'

const emptyForm = {
  name: '',
  phone: '',
  email: '',
  address: '',
}

function Suppliers() {
  const userRole = useAuthStore((state) => state.role)
  const [suppliers, setSuppliers] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')

  async function loadSuppliers() {
    try {
      setLoading(true)
      setError('')
      const data = await getSuppliers()
      setSuppliers(data)
    } catch {
      setError('Could not load suppliers.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadSuppliers()
  }, [])

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({
      ...current,
      [name]: value,
    }))
  }

  function startEdit(supplier) {
    setEditingId(supplier.id)
    setFormError('')
    setForm({
      name: supplier.name,
      phone: supplier.phone ?? '',
      email: supplier.email ?? '',
      address: supplier.address ?? '',
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
      name: form.name,
      phone: form.phone || null,
      email: form.email || null,
      address: form.address || null,
    }

    try {
      if (editingId) {
        await updateSupplier(editingId, payload)
      } else {
        await createSupplier(payload)
      }

      cancelEdit()
      await loadSuppliers()
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else {
        setFormError(editingId ? 'Could not update supplier.' : 'Could not save supplier.')
      }
    }
  }

  async function handleDelete(supplier) {
    const confirmed = window.confirm(
      `Delete "${supplier.name}"? Suppliers with linked products cannot be removed.`
    )

    if (!confirmed) {
      return
    }

    try {
      await deleteSupplier(supplier.id)
      if (editingId === supplier.id) {
        cancelEdit()
      }
      await loadSuppliers()
    } catch (err) {
      if (err.message) {
        setFormError(err.message)
      } else {
        setError('Could not delete supplier.')
      }
    }
  }

  return (
    <main className="suppliers-page">
      <section className="card">
        <h2>{editingId ? 'Edit supplier' : 'Add supplier'}</h2>
        <form className="supplier-form" onSubmit={handleSubmit}>
          <label>
            Name
            <input name="name" value={form.name} onChange={handleChange} required />
          </label>

          <div className="form-row">
            <label>
              Phone
              <input name="phone" value={form.phone} onChange={handleChange} />
            </label>

            <label>
              Email
              <input name="email" type="email" value={form.email} onChange={handleChange} />
            </label>
          </div>

          <label>
            Address
            <input name="address" value={form.address} onChange={handleChange} />
          </label>

          {formError && <p className="error">{formError}</p>}

          <div className="form-actions">
            <button type="submit">{editingId ? 'Update supplier' : 'Save supplier'}</button>
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
          <h2>Supplier list</h2>
          {!loading && <p className="result-count">{suppliers.length} supplier(s)</p>}
        </div>

        {loading && <p>Loading suppliers...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && suppliers.length === 0 && (
          <p>No suppliers yet. Add one above or run the database seeder.</p>
        )}

        {!loading && suppliers.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Phone</th>
                <th>Email</th>
                <th>Address</th>
                <th>Products</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {suppliers.map((supplier) => (
                <tr key={supplier.id} className={editingId === supplier.id ? 'editing' : ''}>
                  <td>{supplier.name}</td>
                  <td>{supplier.phone ?? '—'}</td>
                  <td>{supplier.email ?? '—'}</td>
                  <td>{supplier.address ?? '—'}</td>
                  <td>{supplier.products_count ?? 0}</td>
                  <td className="actions">
                    <button type="button" className="secondary" onClick={() => startEdit(supplier)}>
                      Edit
                    </button>
                    {(userRole === 'admin' || userRole === 'manager') && (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => handleDelete(supplier)}
                        disabled={(supplier.products_count ?? 0) > 0}
                        title={
                          (supplier.products_count ?? 0) > 0
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

export default Suppliers
