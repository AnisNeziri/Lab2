import { useEffect, useState } from 'react'
import { useAuthStore } from '../store/authStore'
import {
  createSupplier,
  deleteSupplier,
  getSuppliers,
  updateSupplier,
} from '../api/suppliers'
import { getSupplierScorecard } from '../api/quality'
import EntityContext from '../components/EntityContext'
import { useSearchParams } from 'react-router-dom'
import './QualityManagement.css'
import './SupplierScorecard.css'

const emptyForm = {
  name: '',
  phone: '',
  email: '',
  address: '',
}

function Suppliers() {
  const [documentParams] = useSearchParams()
  const userRole = useAuthStore((state) => state.role)
  const [suppliers, setSuppliers] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [scorecard, setScorecard] = useState(null)

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
      `Delete "${supplier.name}"? Linked products will be left without a supplier.`
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

  async function openScorecard(supplier) {
    try {
      setFormError('')
      setScorecard(await getSupplierScorecard(supplier.id))
    } catch (error) {
      setFormError(error.message || 'Could not load supplier performance.')
    }
  }

  return (
    <main className="suppliers-page">
      {suppliers.filter(s=>String(s.id)===documentParams.get('supplier')).map(s=><section className="card" key={s.id}><h2>{s.name}</h2><EntityContext entityType="supplier" entityId={s.id}/></section>)}
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
                    <button type="button" className="secondary" onClick={() => openScorecard(supplier)}>
                      Scorecard
                    </button>
                    <button type="button" className="secondary" onClick={() => startEdit(supplier)}>
                      Edit
                    </button>
                    {(userRole === 'admin' || userRole === 'manager') && (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => handleDelete(supplier)}
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
      {scorecard && (
        <div className="quality-modal-backdrop">
          <section className="quality-modal supplier-scorecard">
            <button type="button" className="quality-close" onClick={() => setScorecard(null)}>×</button>
            <span className="scorecard-eyebrow">Supplier Performance</span>
            <h2>{scorecard.supplier_name}</h2>
            <div className="scorecard-overall"><strong>{scorecard.overall_score ?? '—'}</strong><span>{scorecard.overall_score == null ? 'Insufficient data' : 'Overall score / 100'}</span></div>
            <div className="quality-metrics scorecard-categories">
              {Object.entries(scorecard.category_scores || {}).map(([name, item]) => <article className="quality-metric" key={name}><span>{name}</span><strong>{item.score ?? '—'}</strong></article>)}
            </div>
            <div className="scorecard-details">
              <p><span>Total spend</span><strong>€{scorecard.delivery.total_purchased_value}</strong></p>
              <p><span>Purchase Orders</span><strong>{scorecard.delivery.purchase_orders}</strong></p>
              <p><span>On-time delivery</span><strong>{scorecard.delivery.on_time_delivery_percent == null ? '—' : `${scorecard.delivery.on_time_delivery_percent}%`}</strong></p>
              <p><span>Average delay</span><strong>{scorecard.delivery.average_days_late ?? '—'} days</strong></p>
              <p><span>Defect rate</span><strong>{scorecard.quality.defect_rate == null ? '—' : `${scorecard.quality.defect_rate}%`}</strong></p>
              <p><span>Acceptance rate</span><strong>{scorecard.quality.acceptance_percent == null ? '—' : `${scorecard.quality.acceptance_percent}%`}</strong></p>
              <p><span>Claims / returns</span><strong>{scorecard.quality.claim_count} / {scorecard.quality.return_quantity}</strong></p>
              <p><span>RFQ response</span><strong>{scorecard.commercial.quote_response_rate == null ? '—' : `${scorecard.commercial.quote_response_rate}%`}</strong></p>
              <p><span>Historical price movement</span><strong>{scorecard.commercial.historical_price_movement_percent == null ? '—' : `${scorecard.commercial.historical_price_movement_percent}%`}</strong></p>
            </div>
            <ul>{scorecard.explanation?.map((line) => <li key={line}>{line}</li>)}</ul>
            <EntityContext entityType="supplier" entityId={scorecard.supplier_id} />
          </section>
        </div>
      )}
    </main>
  )
}

export default Suppliers
