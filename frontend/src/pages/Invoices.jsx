import { useEffect, useMemo, useState } from 'react'
import { createInvoice, getInvoices, getInvoice, downloadInvoicePdf } from '../api/invoices'
import { getProducts } from '../api/products'
import { recordPayment } from '../api/payments'
import { Download, X, Eye, CreditCard, Plus, Trash2, FileText, AlertCircle, CheckCircle, Clock, Banknote } from 'lucide-react'

// ── helpers ──────────────────────────────────────────────────────────────────
const emptyLineItem = () => ({ product_id: '', quantity: 1, unit_price: '' })

const fmtMoney = (n) => `€${Number(n ?? 0).toFixed(2)}`

const remaining = (inv) =>
  Math.max(0, round2((inv?.total_amount ?? 0) - (inv?.total_paid ?? 0)))

const round2 = (n) => Math.round(Number(n) * 100) / 100

const STATUS_META = {
  paid:           { label: 'Paid',           color: '#16a34a', bg: '#f0fdf4', icon: CheckCircle },
  partially_paid: { label: 'Partially Paid', color: '#d97706', bg: '#fffbeb', icon: Clock },
  unpaid:         { label: 'Unpaid',         color: '#dc2626', bg: '#fef2f2', icon: AlertCircle },
  overdue:        { label: 'Overdue',        color: '#dc2626', bg: '#fef2f2', icon: AlertCircle },
  draft:          { label: 'Draft',          color: '#64748b', bg: '#f8fafc', icon: FileText },
  sent:           { label: 'Sent',           color: '#2563eb', bg: '#eff6ff', icon: FileText },
}

function StatusBadge({ status }) {
  const meta = STATUS_META[status] ?? STATUS_META.draft
  const Icon = meta.icon
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 5,
      background: meta.bg, color: meta.color,
      border: `1px solid ${meta.color}33`,
      borderRadius: 99, padding: '3px 10px', fontSize: 12, fontWeight: 700,
    }}>
      <Icon size={11} />
      {meta.label}
    </span>
  )
}

function BalanceBadge({ invoice }) {
  const bal = remaining(invoice)
  if (bal <= 0) return null
  const isPartial = Number(invoice.total_paid ?? 0) > 0
  return (
    <span style={{
      display: 'inline-flex', alignItems: 'center', gap: 4,
      background: isPartial ? '#fffbeb' : '#fef2f2',
      color: isPartial ? '#d97706' : '#dc2626',
      border: `1px solid ${isPartial ? '#fbbf2444' : '#fca5a544'}`,
      borderRadius: 99, padding: '2px 9px', fontSize: 11, fontWeight: 700,
    }}>
      {fmtMoney(bal)} due
    </span>
  )
}

// ── Payment modal ─────────────────────────────────────────────────────────────
function PaymentModal({ invoice, onClose, onSuccess }) {
  const bal = remaining(invoice)
  const [amount, setAmount]   = useState(String(bal))
  const [note, setNote]       = useState('')
  const [method, setMethod]   = useState('cash')
  const [loading, setLoading] = useState(false)
  const [error, setError]     = useState('')

  const handleSubmit = async (e) => {
    e.preventDefault()
    const amt = parseFloat(amount)
    if (!amt || amt <= 0) { setError('Enter a valid amount.'); return }
    if (amt > bal)        { setError(`Amount cannot exceed remaining balance ${fmtMoney(bal)}.`); return }
    setLoading(true)
    setError('')
    try {
      await recordPayment(invoice.id, amt, note, method)
      onSuccess()
    } catch (err) {
      setError(err.message || 'Payment failed.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div style={{
      position: 'fixed', inset: 0, zIndex: 200,
      background: 'rgba(15,23,42,0.55)', backdropFilter: 'blur(4px)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16,
    }} onClick={onClose}>
      <div style={{
        background: '#fff', borderRadius: 16, padding: 28, width: '100%', maxWidth: 420,
        boxShadow: '0 24px 60px rgba(15,23,42,0.18)',
      }} onClick={e => e.stopPropagation()}>
        {/* header */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 20 }}>
          <div>
            <div style={{ fontSize: 16, fontWeight: 800, color: '#0f172a' }}>Record Payment</div>
            <div style={{ fontSize: 12, color: '#64748b', marginTop: 3 }}>
              {invoice.invoice_number} · {invoice.customer_name}
            </div>
          </div>
          <button type="button" onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#94a3b8', padding: 4 }}>
            <X size={18} />
          </button>
        </div>

        {/* balance summary */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, marginBottom: 20 }}>
          {[
            { label: 'Invoice Total', value: fmtMoney(invoice.total_amount), color: '#0f172a' },
            { label: 'Already Paid',  value: fmtMoney(invoice.total_paid ?? 0), color: '#16a34a' },
            { label: 'Remaining',     value: fmtMoney(bal), color: '#dc2626' },
          ].map(({ label, value, color }) => (
            <div key={label} style={{ background: '#f8fafc', borderRadius: 10, padding: '10px 12px', textAlign: 'center' }}>
              <div style={{ fontSize: 10, color: '#64748b', textTransform: 'uppercase', fontWeight: 600, letterSpacing: '0.05em' }}>{label}</div>
              <div style={{ fontSize: 16, fontWeight: 800, color, marginTop: 4 }}>{value}</div>
            </div>
          ))}
        </div>

        {error && (
          <div style={{ background: '#fef2f2', border: '1px solid #fecaca', borderRadius: 8, padding: '10px 14px', color: '#991b1b', fontSize: 13, marginBottom: 14 }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} style={{ display: 'grid', gap: 14 }}>
          <div>
            <label style={{ fontSize: 13, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 6 }}>
              Amount (€) <span style={{ color: '#dc2626' }}>*</span>
            </label>
            <input
              type="number" min="0.01" step="0.01" required
              value={amount}
              onChange={e => setAmount(e.target.value)}
              style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 8, fontSize: 14, fontWeight: 700, boxSizing: 'border-box', outline: 'none' }}
              onFocus={e => e.target.style.borderColor = '#3b82f6'}
              onBlur={e => e.target.style.borderColor = '#cbd5e1'}
            />
          </div>

          <div>
            <label style={{ fontSize: 13, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 6 }}>
              Payment Method
            </label>
            <select
              value={method} onChange={e => setMethod(e.target.value)}
              style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 8, fontSize: 13, boxSizing: 'border-box', background: '#fff', outline: 'none' }}
            >
              <option value="cash">Cash</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="card">Card</option>
            </select>
          </div>

          <div>
            <label style={{ fontSize: 13, fontWeight: 600, color: '#334155', display: 'block', marginBottom: 6 }}>
              Note (optional)
            </label>
            <input
              type="text" placeholder="e.g. First installment, cash at office…"
              value={note} onChange={e => setNote(e.target.value)}
              style={{ width: '100%', padding: '10px 12px', border: '1px solid #cbd5e1', borderRadius: 8, fontSize: 13, boxSizing: 'border-box', outline: 'none' }}
              onFocus={e => e.target.style.borderColor = '#3b82f6'}
              onBlur={e => e.target.style.borderColor = '#cbd5e1'}
            />
          </div>

          <div style={{ display: 'flex', gap: 10, marginTop: 4 }}>
            <button type="submit" disabled={loading} style={{
              flex: 1, background: 'linear-gradient(135deg,#16a34a,#15803d)', color: '#fff',
              border: 'none', borderRadius: 10, padding: '11px 0', fontWeight: 700, fontSize: 14,
              cursor: loading ? 'not-allowed' : 'pointer', opacity: loading ? 0.7 : 1,
              display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 8,
            }}>
              <Banknote size={15} />
              {loading ? 'Recording…' : 'Record Payment'}
            </button>
            <button type="button" onClick={onClose} style={{
              padding: '11px 18px', border: '1px solid #e2e8f0', borderRadius: 10,
              background: '#f8fafc', color: '#64748b', fontWeight: 600, fontSize: 13, cursor: 'pointer',
            }}>
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Main Invoices page ────────────────────────────────────────────────────────
function Invoices() {
  const [invoices,       setInvoices]       = useState([])
  const [products,       setProducts]       = useState([])
  const [selectedInvoice, setSelectedInvoice] = useState(null)
  const [loading,        setLoading]        = useState(true)
  const [loadingDetail,  setLoadingDetail]  = useState(false)
  const [downloadingId,  setDownloadingId]  = useState(null)
  const [submitting,     setSubmitting]     = useState(false)
  const [error,          setError]          = useState('')
  const [formError,      setFormError]      = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [showCreateForm, setShowCreateForm] = useState(false)
  const [payModal,       setPayModal]       = useState(null) // invoice | null

  const [form, setForm] = useState({
    customer_name: '',
    issued_at: new Date().toISOString().slice(0, 10),
    due_at: '',
    items: [emptyLineItem()],
  })

  const loadInvoices = async () => {
    try {
      setLoading(true)
      setError('')
      const data = await getInvoices()
      setInvoices(data)
    } catch (err) {
      setError(err.message || 'Failed to load invoices.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadInvoices()
    getProducts({ per_page: 200 })
      .then((data) => setProducts(data.data || data))
      .catch(() => setProducts([]))
  }, [])

  const productMap = useMemo(
    () => Object.fromEntries(products.map((p) => [String(p.id), p])),
    [products]
  )

  const estimatedTotal = useMemo(() =>
    form.items.reduce((sum, item) => {
      const product = productMap[item.product_id]
      const unitPrice = item.unit_price !== '' ? Number(item.unit_price) : Number(product?.price || 0)
      return sum + unitPrice * Number(item.quantity || 0)
    }, 0),
    [form.items, productMap]
  )

  const handleViewDetails = async (id) => {
    setLoadingDetail(true)
    try {
      const data = await getInvoice(id)
      setSelectedInvoice(data)
    } catch {
      alert('Could not load invoice details.')
    } finally {
      setLoadingDetail(false)
    }
  }

  const handlePaymentSuccess = async () => {
    setPayModal(null)
    await loadInvoices()
    if (selectedInvoice) {
      const detail = await getInvoice(selectedInvoice.id)
      setSelectedInvoice(detail)
    }
  }

  const handleDownloadPdf = async (id, invoiceNumber) => {
    setDownloadingId(id)
    try {
      await downloadInvoicePdf(id, invoiceNumber)
    } catch {
      alert('Failed to generate/download invoice PDF.')
    } finally {
      setDownloadingId(null)
    }
  }

  const updateFormField = (field, value) =>
    setForm((cur) => ({ ...cur, [field]: value }))

  const updateLineItem = (index, field, value) => {
    setForm((cur) => ({
      ...cur,
      items: cur.items.map((item, i) => {
        if (i !== index) return item
        const next = { ...item, [field]: value }
        if (field === 'product_id') {
          const p = productMap[value]
          next.unit_price = p ? String(p.price) : ''
        }
        return next
      }),
    }))
  }

  const addLineItem    = () => setForm((cur) => ({ ...cur, items: [...cur.items, emptyLineItem()] }))
  const removeLineItem = (index) => setForm((cur) => ({
    ...cur,
    items: cur.items.length === 1 ? cur.items : cur.items.filter((_, i) => i !== index),
  }))

  const resetCreateForm = () => {
    setForm({ customer_name: '', issued_at: new Date().toISOString().slice(0, 10), due_at: '', items: [emptyLineItem()] })
    setFormError('')
    setSuccessMessage('')
  }

  const handleCreateInvoice = async (event) => {
    event.preventDefault()
    setSubmitting(true)
    setFormError('')
    setSuccessMessage('')

    const items = form.items
      .filter((item) => item.product_id)
      .map((item) => ({
        product_id: Number(item.product_id),
        quantity: Number(item.quantity),
        ...(item.unit_price !== '' ? { unit_price: Number(item.unit_price) } : {}),
      }))

    if (!form.customer_name.trim()) {
      setFormError('Customer name is required.')
      setSubmitting(false)
      return
    }
    if (items.length === 0) {
      setFormError('Add at least one product line to the invoice.')
      setSubmitting(false)
      return
    }

    try {
      const created = await createInvoice({
        customer_name: form.customer_name.trim(),
        issued_at: form.issued_at || undefined,
        due_at: form.due_at || undefined,
        status: 'unpaid',
        items,
      })
      setSuccessMessage(`Invoice ${created.invoice_number} created successfully.`)
      resetCreateForm()
      setShowCreateForm(false)
      await loadInvoices()
      setSelectedInvoice(created)
    } catch (err) {
      setFormError(err.errors ? Object.values(err.errors).flat().join(' ') : err.message || 'Could not create invoice.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) {
    return (
      <main className="invoices-page page-stack">
        <p className="page-intro">Loading invoices...</p>
      </main>
    )
  }

  return (
    <main className="invoices-page page-stack">
      {/* Payment modal */}
      {payModal && (
        <PaymentModal
          invoice={payModal}
          onClose={() => setPayModal(null)}
          onSuccess={handlePaymentSuccess}
        />
      )}

      <section className="card">
        <div className="section-header">
          <h2><FileText size={20} />Invoices</h2>
          <div className="section-header-actions">
            <button type="button" onClick={() => setShowCreateForm((v) => !v)}>
              <Plus size={16} />
              {showCreateForm ? 'Hide form' : 'Create invoice'}
            </button>
          </div>
        </div>
        <p className="page-intro">
          Create invoices, record partial or full payments, and track outstanding balances per customer.
        </p>
        {error && <div className="form-error-banner">{error}</div>}
        {successMessage && <div className="success-banner">{successMessage}</div>}
      </section>

      {showCreateForm && (
        <section className="card">
          <h3>New invoice</h3>
          {formError && <div className="form-error-banner">{formError}</div>}

          <form className="form-grid invoice-form" onSubmit={handleCreateInvoice}>
            <label>
              Customer name
              <input
                type="text" required value={form.customer_name}
                onChange={(e) => updateFormField('customer_name', e.target.value)}
                placeholder="Customer or company name"
              />
            </label>

            <div className="form-row">
              <label>
                Issue date
                <input type="date" value={form.issued_at} onChange={(e) => updateFormField('issued_at', e.target.value)} />
              </label>
              <label>
                Due date
                <input type="date" value={form.due_at} onChange={(e) => updateFormField('due_at', e.target.value)} />
              </label>
            </div>

            <div className="invoice-lines">
              <div className="section-header">
                <h4>Line items</h4>
                <button type="button" className="secondary" onClick={addLineItem}>
                  <Plus size={16} />Add line
                </button>
              </div>

              {form.items.map((item, index) => (
                <div key={index} className="invoice-line-row">
                  <label>
                    Product
                    <select required value={item.product_id} onChange={(e) => updateLineItem(index, 'product_id', e.target.value)}>
                      <option value="">Select product</option>
                      {products.map((p) => (
                        <option key={p.id} value={p.id}>{p.name} ({p.sku})</option>
                      ))}
                    </select>
                  </label>
                  <label>
                    Quantity
                    <input type="number" min="1" required value={item.quantity} onChange={(e) => updateLineItem(index, 'quantity', e.target.value)} />
                  </label>
                  <label>
                    Unit price
                    <input type="number" min="0" step="0.01" value={item.unit_price} onChange={(e) => updateLineItem(index, 'unit_price', e.target.value)} placeholder="Auto from product" />
                  </label>
                  <button type="button" className="danger icon-button" onClick={() => removeLineItem(index)} disabled={form.items.length === 1} aria-label="Remove line item">
                    <Trash2 size={16} />
                  </button>
                </div>
              ))}
            </div>

            <p className="invoice-total-preview">
              Estimated total: <strong>{fmtMoney(estimatedTotal)}</strong>
            </p>

            <div className="form-actions">
              <button type="submit" disabled={submitting}>{submitting ? 'Creating...' : 'Create invoice'}</button>
              <button type="button" className="secondary" onClick={resetCreateForm}>Reset</button>
            </div>
          </form>
        </section>
      )}

      <div className={`invoice-layout ${selectedInvoice ? 'with-detail' : ''}`}>
        {/* Invoice list table */}
        <section className="card table-wrap">
          <table className="product-table">
            <thead>
              <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Issued</th>
                <th>Status</th>
                <th>Total</th>
                <th>Balance Due</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {invoices.length === 0 ? (
                <tr><td colSpan="7" className="empty-cell">No invoices yet. Create your first invoice above.</td></tr>
              ) : invoices.map((invoice) => (
                <tr key={invoice.id}>
                  <td><strong>{invoice.invoice_number}</strong></td>
                  <td>{invoice.customer_name}</td>
                  <td>{new Date(invoice.issued_at).toLocaleDateString()}</td>
                  <td><StatusBadge status={invoice.status} /></td>
                  <td>{fmtMoney(invoice.total_amount)}</td>
                  <td>
                    {remaining(invoice) > 0
                      ? <BalanceBadge invoice={invoice} />
                      : <span style={{ color: '#16a34a', fontSize: 12, fontWeight: 700 }}>✓ Settled</span>
                    }
                  </td>
                  <td className="table-actions">
                    <button type="button" className="secondary" onClick={() => handleViewDetails(invoice.id)}>
                      <Eye size={16} />Details
                    </button>
                    {remaining(invoice) > 0 && (
                      <button type="button" onClick={() => setPayModal(invoice)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                        <Banknote size={16} />Pay
                      </button>
                    )}
                    <button type="button" className="secondary" onClick={() => handleDownloadPdf(invoice.id, invoice.invoice_number)} disabled={downloadingId === invoice.id}>
                      <Download size={16} />
                      {downloadingId === invoice.id ? '…' : 'PDF'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>

        {/* Invoice detail panel */}
        {selectedInvoice && (
          <section className="card invoice-detail-panel">
            <div className="section-header">
              <h3>Invoice details</h3>
              <button type="button" className="secondary icon-button" onClick={() => setSelectedInvoice(null)}>
                <X size={16} />
              </button>
            </div>

            {loadingDetail ? <p className="page-intro">Loading...</p> : (
              <>
                <div className="invoice-detail-grid">
                  <div><span>Number</span><strong>{selectedInvoice.invoice_number}</strong></div>
                  <div><span>Customer</span><strong>{selectedInvoice.customer_name}</strong></div>
                  <div><span>Issued</span><strong>{new Date(selectedInvoice.issued_at).toLocaleDateString()}</strong></div>
                  {selectedInvoice.due_at && (
                    <div><span>Due</span><strong>{new Date(selectedInvoice.due_at).toLocaleDateString()}</strong></div>
                  )}
                  <div><span>Status</span><StatusBadge status={selectedInvoice.status} /></div>
                </div>

                {/* Balance summary strip */}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8, margin: '16px 0', background: '#f8fafc', borderRadius: 10, padding: 14 }}>
                  {[
                    { label: 'Total',    value: fmtMoney(selectedInvoice.total_amount),         color: '#0f172a' },
                    { label: 'Paid',     value: fmtMoney(selectedInvoice.total_paid ?? 0),       color: '#16a34a' },
                    { label: 'Remaining',value: fmtMoney(remaining(selectedInvoice)),            color: remaining(selectedInvoice) > 0 ? '#dc2626' : '#16a34a' },
                  ].map(({ label, value, color }) => (
                    <div key={label} style={{ textAlign: 'center' }}>
                      <div style={{ fontSize: 10, color: '#64748b', textTransform: 'uppercase', fontWeight: 600, letterSpacing: '0.05em' }}>{label}</div>
                      <div style={{ fontSize: 17, fontWeight: 800, color, marginTop: 3 }}>{value}</div>
                    </div>
                  ))}
                </div>

                <div className="invoice-items-list">
                  <h4>Items</h4>
                  {selectedInvoice.items?.map((item) => (
                    <div key={item.id} className="invoice-item-row">
                      <div>
                        <strong>{item.product?.name || item.description || 'Product'}</strong>
                        <p>{item.quantity} × {fmtMoney(item.unit_price)}</p>
                      </div>
                      <strong>{fmtMoney(item.line_total)}</strong>
                    </div>
                  ))}
                </div>

                {/* Payment history */}
                {selectedInvoice.payments?.length > 0 && (
                  <div style={{ marginTop: 16 }}>
                    <h4 style={{ fontSize: 13, fontWeight: 700, marginBottom: 8, color: '#334155' }}>Payment History</h4>
                    {selectedInvoice.payments.map((pmt) => (
                      <div key={pmt.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 12px', background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 8, marginBottom: 6 }}>
                        <div>
                          <div style={{ fontSize: 13, fontWeight: 700, color: '#15803d' }}>{fmtMoney(pmt.amount)}</div>
                          <div style={{ fontSize: 11, color: '#64748b' }}>
                            {pmt.payment_method} · {new Date(pmt.paid_at ?? pmt.created_at).toLocaleDateString()}
                            {pmt.note && <span> · {pmt.note}</span>}
                          </div>
                        </div>
                        <span style={{ fontSize: 10, background: '#dcfce7', color: '#15803d', borderRadius: 99, padding: '2px 8px', fontWeight: 700 }}>
                          {pmt.transaction_ref}
                        </span>
                      </div>
                    ))}
                  </div>
                )}

                <div style={{ display: 'flex', gap: 10, marginTop: 16, flexWrap: 'wrap' }}>
                  {remaining(selectedInvoice) > 0 && (
                    <button type="button" onClick={() => setPayModal(selectedInvoice)} style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                      <Banknote size={16} />
                      Record Payment
                    </button>
                  )}
                  <button type="button" className="secondary" onClick={() => handleDownloadPdf(selectedInvoice.id, selectedInvoice.invoice_number)} disabled={downloadingId === selectedInvoice.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                    <Download size={16} />
                    {downloadingId === selectedInvoice.id ? 'Generating…' : 'Download PDF'}
                  </button>
                </div>
              </>
            )}
          </section>
        )}
      </div>
    </main>
  )
}

export default Invoices
