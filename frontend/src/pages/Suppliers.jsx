import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useUiText } from '../hooks/useUiText'
import PageHeader from '../components/PageHeader'
import { useDialog } from '../hooks/useDialog'
import SearchField from '../components/SearchField'
import { useAuthStore } from '../store/authStore'
import {
  createSupplier,
  deleteSupplier,
  getSuppliers,
  updateSupplier,
} from '../api/suppliers'
import { getSupplierScorecard } from '../api/quality'
import EntityContext from '../components/EntityContext'
import SupplierIntelligence from '../components/SupplierIntelligence'
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
  const { t, language } = useTranslation()
  const ui = useUiText()
  const permissions = useAuthStore(state => state.permissions)
  const canManage = permissions.includes('suppliers.manage')
  const canScore = permissions.includes('supplier_performance.view')
  const [search, setSearch] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [deletingId, setDeletingId] = useState(null)
  const [scoreBusy, setScoreBusy] = useState(false)
  const [intelligenceOpen, setIntelligenceOpen] = useState(Boolean(documentParams.get('supplier')))
  const pending = useRef(false), deleting = useRef(new Set()), scorePending = useRef(false), formRef = useRef(null)
  const canIntelligence = permissions.includes('analytics.view') && permissions.includes('inventory.view')
  const [suppliers, setSuppliers] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [scorecard, setScorecard] = useState(null)
  const scoreRef = useDialog(() => setScorecard(null), false, Boolean(scorecard))
  const visibleSuppliers = suppliers.filter(supplier => [supplier.name, supplier.phone, supplier.email, supplier.address].some(value => String(value || '').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())))

  async function loadSuppliers(silent = false) {
    try {
      if (!silent) setLoading(true)
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
    setFormOpen(true)
    requestAnimationFrame(() => { formRef.current?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth' }); formRef.current?.querySelector('input')?.focus({ preventScroll: true }) })
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
    if (pending.current) return
    pending.current = true
    setSaving(true)
    setFormError('')
    setMessage('')

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

      setMessage(ui(editingId ? 'Supplier updated.' : 'Supplier added.'))
      cancelEdit()
      setFormOpen(false)
      await loadSuppliers(true)
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else {
        setFormError(err.message || ui(editingId ? 'Could not update supplier.' : 'Could not save supplier.'))
      }
    } finally { pending.current = false; setSaving(false) }
  }

  async function handleDelete(supplier) {
    if (deleting.current.has(supplier.id)) return
    const confirmed = window.confirm(
      language === 'sq' ? `Të fshihet "${supplier.name}"? Produktet e lidhura do mbeten pa furnitor.` : `Delete "${supplier.name}"? Linked products will be left without a supplier.`
    )

    if (!confirmed) {
      return
    }

    deleting.current.add(supplier.id)
    setDeletingId(supplier.id)
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
    } finally { deleting.current.delete(supplier.id); setDeletingId(null) }
  }

  async function openScorecard(supplier) {
    if (scorePending.current) return
    scorePending.current = true
    setScoreBusy(true)
    try {
      setFormError('')
      setScorecard(await getSupplierScorecard(supplier.id))
    } catch (error) {
      setFormError(error.message || 'Could not load supplier performance.')
    } finally { scorePending.current = false; setScoreBusy(false) }
  }

  return (
    <main className="suppliers-page">
      <PageHeader title={t('nav.suppliers')} description={ui('Supplier contacts and purchasing evidence.')} actions={canManage && !formOpen && <button type="button" className="workspace-primary" onClick={() => setFormOpen(true)}>{ui('Add supplier')}</button>}/>
      {message && <p role="status" className="workspace-success">{message}</p>}
      {scoreBusy && <p role="status">{ui('Loading supplier performance…')}</p>}
      {suppliers.filter(s=>String(s.id)===documentParams.get('supplier')).map(s=><section className="card" key={s.id}><h2>{s.name}</h2><EntityContext entityType="supplier" entityId={s.id}/></section>)}
      {formOpen && canManage && <section className="card" ref={formRef}>
        <h2>{ui(editingId ? 'Edit supplier' : 'Add supplier')}</h2>
        <form className="supplier-form" onSubmit={handleSubmit}>
          <label>
            {ui("Name")}
            <input name="name" value={form.name} onChange={handleChange} required />
          </label>

          <div className="form-row">
            <label>
              {ui("Phone")}
              <input name="phone" value={form.phone} onChange={handleChange} />
            </label>

            <label>
              {ui("Email")}
              <input name="email" type="email" value={form.email} onChange={handleChange} />
            </label>
          </div>

          <label>
            {ui("Address")}
            <input name="address" value={form.address} onChange={handleChange} />
          </label>

          {formError && <p className="error">{ui(formError)}</p>}

          <div className="form-actions">
            <button type="submit" disabled={saving}>{ui(saving ? 'Saving…' : editingId ? 'Update supplier' : 'Save supplier')}</button>
            {(
              <button type="button" className="secondary" disabled={saving} onClick={() => { if (form.name && !window.confirm(ui('Discard unsaved supplier changes?'))) return; cancelEdit(); setFormOpen(false) }}>
                {ui("Cancel")}
              </button>
            )}
          </div>
        </form>
      </section>}

      <section className="card">
        <div className="section-header">
          <h2>{ui("Supplier list")}</h2>
          {!loading && <p className="result-count">{visibleSuppliers.length} / {suppliers.length} {ui('suppliers')}</p>}
        </div>

        <SearchField placeholder={ui('Search by name, phone or email')} value={search} onChange={event => setSearch(event.target.value)}/>
        {loading && <p>{ui("Loading suppliers...")}</p>}
        {error && <p className="error">{ui(error)}</p>}

        {!loading && !error && suppliers.length === 0 && (
          <p>{ui('No suppliers yet. Add your first supplier.')}</p>
        )}

        {!loading && visibleSuppliers.length === 0 && search && <p>{ui('No suppliers match this search. Clear the search to see all suppliers.')}</p>}
        {!loading && visibleSuppliers.length > 0 && (
          <div className="table-wrap" tabIndex={0} aria-label={ui('Supplier list')}><table className="product-table">
            <thead>
              <tr>
                <th>{ui("Name")}</th>
                <th>{ui("Phone")}</th>
                <th>{ui("Email")}</th>
                <th>{ui("Address")}</th>
                <th>{ui("Products")}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {visibleSuppliers.map((supplier) => (
                <tr key={supplier.id} className={editingId === supplier.id ? 'editing' : ''}>
                  <td>{supplier.name}</td>
                  <td>{supplier.phone ?? '—'}</td>
                  <td>{supplier.email ?? '—'}</td>
                  <td>{supplier.address ?? '—'}</td>
                  <td>{supplier.products_count ?? 0}</td>
                  <td className="actions">
                    {canScore && <button type="button" disabled={scoreBusy} className="secondary" onClick={() => openScorecard(supplier)}>
                      {ui('Scorecard')}
                    </button>}
                    {canManage && <button type="button" disabled={saving} className="secondary" onClick={() => startEdit(supplier)}>
                      {ui('Edit')}
                    </button>}
                    {canManage && (
                      <button
                        type="button"
                        className="danger"
                        disabled={deletingId === supplier.id} onClick={() => handleDelete(supplier)}
                      >
                        {ui("Delete")}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table></div>
        )}
      </section>
      {canIntelligence && <details className="supplier-intelligence-disclosure" open={intelligenceOpen} onToggle={event => setIntelligenceOpen(event.currentTarget.open)}><summary>{ui('Delivery intelligence and forecasting')}</summary>{intelligenceOpen && <SupplierIntelligence suppliers={suppliers} initialSupplier={documentParams.get('supplier')}/>}</details>}
      {scorecard && createPortal(
        <div className="quality-modal-backdrop" onMouseDown={event => event.target === event.currentTarget && setScorecard(null)}>
          <section ref={scoreRef} className="quality-modal supplier-scorecard" role="dialog" aria-modal="true" aria-label={ui('Supplier Performance')}>
            <button type="button" className="quality-close" aria-label={ui('Close')} onClick={() => setScorecard(null)}>×</button>
            <span className="scorecard-eyebrow">{ui("Supplier Performance")}</span>
            <h2>{scorecard.supplier_name}</h2>
            <div className="scorecard-overall"><strong>{scorecard.overall_score ?? '—'}</strong><span>{scorecard.overall_score == null ? ui('Insufficient data') : ui('Overall score / 100')}</span></div>
            <div className="quality-metrics scorecard-categories">
              {Object.entries(scorecard.category_scores || {}).map(([name, item]) => <article className="quality-metric" key={name}><span>{name}</span><strong>{item.score ?? '—'}</strong></article>)}
            </div>
            <div className="scorecard-details">
              <p><span>{ui("Total spend")}</span><strong>€{scorecard.delivery.total_purchased_value}</strong></p>
              <p><span>{ui("Purchase Orders")}</span><strong>{scorecard.delivery.purchase_orders}</strong></p>
              <p><span>{ui("On-time delivery")}</span><strong>{scorecard.delivery.on_time_delivery_percent == null ? '—' : `${scorecard.delivery.on_time_delivery_percent}%`}</strong></p>
              <p><span>{ui("Average delay")}</span><strong>{scorecard.delivery.average_days_late ?? '—'} days</strong></p>
              <p><span>{ui("Defect rate")}</span><strong>{scorecard.quality.defect_rate == null ? '—' : `${scorecard.quality.defect_rate}%`}</strong></p>
              <p><span>{ui("Acceptance rate")}</span><strong>{scorecard.quality.acceptance_percent == null ? '—' : `${scorecard.quality.acceptance_percent}%`}</strong></p>
              <p><span>{ui("Claims / returns")}</span><strong>{scorecard.quality.claim_count} / {scorecard.quality.return_quantity}</strong></p>
              <p><span>{ui("RFQ response")}</span><strong>{scorecard.commercial.quote_response_rate == null ? '—' : `${scorecard.commercial.quote_response_rate}%`}</strong></p>
              <p><span>{ui("Historical price movement")}</span><strong>{scorecard.commercial.historical_price_movement_percent == null ? '—' : `${scorecard.commercial.historical_price_movement_percent}%`}</strong></p>
            </div>
            <ul>{scorecard.explanation?.map((line) => <li key={line}>{line}</li>)}</ul>
            <EntityContext entityType="supplier" entityId={scorecard.supplier_id} />
          </section>
        </div>
      , document.body)}
    </main>
  )
}

export default Suppliers
