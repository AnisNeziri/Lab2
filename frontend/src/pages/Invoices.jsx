import { useEffect, useMemo, useState } from 'react'
import { createInvoice, getInvoices, getInvoice, downloadInvoicePdf } from '../api/invoices'
import { getProducts } from '../api/products'
import { processPayment } from '../api/payments'
import { Download, X, Eye, CreditCard, Plus, Trash2, FileText } from 'lucide-react'

const emptyLineItem = () => ({
  product_id: '',
  quantity: 1,
  unit_price: '',
})

function Invoices() {
  const [invoices, setInvoices] = useState([])
  const [products, setProducts] = useState([])
  const [selectedInvoice, setSelectedInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadingDetail, setLoadingDetail] = useState(false)
  const [downloadingId, setDownloadingId] = useState(null)
  const [payingId, setPayingId] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [showCreateForm, setShowCreateForm] = useState(false)
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
    () => Object.fromEntries(products.map((product) => [String(product.id), product])),
    [products]
  )

  const estimatedTotal = useMemo(() => {
    return form.items.reduce((sum, item) => {
      const product = productMap[item.product_id]
      const unitPrice = item.unit_price !== '' ? Number(item.unit_price) : Number(product?.price || 0)
      const quantity = Number(item.quantity || 0)
      return sum + unitPrice * quantity
    }, 0)
  }, [form.items, productMap])

  const handleViewDetails = async (id) => {
    setLoadingDetail(true)
    try {
      const data = await getInvoice(id)
      setSelectedInvoice(data)
    } catch (err) {
      alert('Could not load invoice details.')
    } finally {
      setLoadingDetail(false)
    }
  }

  const handlePayment = async (invoice) => {
    if (invoice.status === 'paid') return

    setPayingId(invoice.id)
    try {
      await processPayment(invoice.id, { payment_method: 'card' })
      await loadInvoices()
      if (selectedInvoice?.id === invoice.id) {
        const detail = await getInvoice(invoice.id)
        setSelectedInvoice(detail)
      }
    } catch (err) {
      alert(err.message || 'Payment failed.')
    } finally {
      setPayingId(null)
    }
  }

  const handleDownloadPdf = async (id, invoiceNumber) => {
    setDownloadingId(id)
    try {
      await downloadInvoicePdf(id, invoiceNumber)
    } catch (err) {
      alert('Failed to generate/download invoice PDF.')
    } finally {
      setDownloadingId(null)
    }
  }

  const updateFormField = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }))
  }

  const updateLineItem = (index, field, value) => {
    setForm((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) => {
        if (itemIndex !== index) return item

        const nextItem = { ...item, [field]: value }

        if (field === 'product_id') {
          const product = productMap[value]
          nextItem.unit_price = product ? String(product.price) : ''
        }

        return nextItem
      }),
    }))
  }

  const addLineItem = () => {
    setForm((current) => ({
      ...current,
      items: [...current.items, emptyLineItem()],
    }))
  }

  const removeLineItem = (index) => {
    setForm((current) => ({
      ...current,
      items: current.items.length === 1 ? current.items : current.items.filter((_, itemIndex) => itemIndex !== index),
    }))
  }

  const resetCreateForm = () => {
    setForm({
      customer_name: '',
      issued_at: new Date().toISOString().slice(0, 10),
      due_at: '',
      items: [emptyLineItem()],
    })
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
      if (err.errors) {
        setFormError(Object.values(err.errors).flat().join(' '))
      } else {
        setFormError(err.message || 'Could not create invoice.')
      }
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
      <section className="card">
        <div className="section-header">
          <h2>
            <FileText size={20} />
            Invoices
          </h2>
          <div className="section-header-actions">
            <button type="button" onClick={() => setShowCreateForm((current) => !current)}>
              <Plus size={16} />
              {showCreateForm ? 'Hide form' : 'Create invoice'}
            </button>
          </div>
        </div>
        <p className="page-intro">
          Create customer invoices from your product catalog, then view details, collect payment, or download PDFs.
        </p>
        {error && <div className="form-error-banner">{error}</div>}
      </section>

      {showCreateForm && (
        <section className="card">
          <h3>New invoice</h3>
          {formError && <div className="form-error-banner">{formError}</div>}
          {successMessage && <div className="success-banner">{successMessage}</div>}

          <form className="form-grid invoice-form" onSubmit={handleCreateInvoice}>
            <label>
              Customer name
              <input
                type="text"
                required
                value={form.customer_name}
                onChange={(event) => updateFormField('customer_name', event.target.value)}
                placeholder="Customer or company name"
              />
            </label>

            <div className="form-row">
              <label>
                Issue date
                <input
                  type="date"
                  value={form.issued_at}
                  onChange={(event) => updateFormField('issued_at', event.target.value)}
                />
              </label>

              <label>
                Due date
                <input
                  type="date"
                  value={form.due_at}
                  onChange={(event) => updateFormField('due_at', event.target.value)}
                />
              </label>
            </div>

            <div className="invoice-lines">
              <div className="section-header">
                <h4>Line items</h4>
                <button type="button" className="secondary" onClick={addLineItem}>
                  <Plus size={16} />
                  Add line
                </button>
              </div>

              {form.items.map((item, index) => (
                <div key={index} className="invoice-line-row">
                  <label>
                    Product
                    <select
                      required
                      value={item.product_id}
                      onChange={(event) => updateLineItem(index, 'product_id', event.target.value)}
                    >
                      <option value="">Select product</option>
                      {products.map((product) => (
                        <option key={product.id} value={product.id}>
                          {product.name} ({product.sku})
                        </option>
                      ))}
                    </select>
                  </label>

                  <label>
                    Quantity
                    <input
                      type="number"
                      min="1"
                      required
                      value={item.quantity}
                      onChange={(event) => updateLineItem(index, 'quantity', event.target.value)}
                    />
                  </label>

                  <label>
                    Unit price
                    <input
                      type="number"
                      min="0"
                      step="0.01"
                      value={item.unit_price}
                      onChange={(event) => updateLineItem(index, 'unit_price', event.target.value)}
                      placeholder="Auto from product"
                    />
                  </label>

                  <button
                    type="button"
                    className="danger icon-button"
                    onClick={() => removeLineItem(index)}
                    disabled={form.items.length === 1}
                    aria-label="Remove line item"
                  >
                    <Trash2 size={16} />
                  </button>
                </div>
              ))}
            </div>

            <p className="invoice-total-preview">
              Estimated total: <strong>${estimatedTotal.toFixed(2)}</strong>
            </p>

            <div className="form-actions">
              <button type="submit" disabled={submitting}>
                {submitting ? 'Creating...' : 'Create invoice'}
              </button>
              <button type="button" className="secondary" onClick={resetCreateForm}>
                Reset
              </button>
            </div>
          </form>
        </section>
      )}

      <div className={`invoice-layout ${selectedInvoice ? 'with-detail' : ''}`}>
        <section className="card table-wrap">
          <table className="product-table">
            <thead>
              <tr>
                <th>Invoice number</th>
                <th>Customer</th>
                <th>Issued date</th>
                <th>Status</th>
                <th>Total</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {invoices.length === 0 ? (
                <tr>
                  <td colSpan="6" className="empty-cell">
                    No invoices yet. Create your first invoice above.
                  </td>
                </tr>
              ) : (
                invoices.map((invoice) => (
                  <tr key={invoice.id}>
                    <td>{invoice.invoice_number}</td>
                    <td>{invoice.customer_name}</td>
                    <td>{new Date(invoice.issued_at).toLocaleDateString()}</td>
                    <td>
                      <span className={`status-pill status-${invoice.status}`}>{invoice.status}</span>
                    </td>
                    <td>${Number(invoice.total_amount).toFixed(2)}</td>
                    <td className="table-actions">
                      <button type="button" className="secondary" onClick={() => handleViewDetails(invoice.id)}>
                        <Eye size={16} />
                        Details
                      </button>
                      <button
                        type="button"
                        className="secondary"
                        onClick={() => handleDownloadPdf(invoice.id, invoice.invoice_number)}
                        disabled={downloadingId === invoice.id}
                      >
                        <Download size={16} />
                        {downloadingId === invoice.id ? 'Generating...' : 'PDF'}
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </section>

        {selectedInvoice && (
          <section className="card invoice-detail-panel">
            <div className="section-header">
              <h3>Invoice details</h3>
              <button type="button" className="secondary icon-button" onClick={() => setSelectedInvoice(null)}>
                <X size={16} />
              </button>
            </div>

            {loadingDetail ? (
              <p className="page-intro">Loading details...</p>
            ) : (
              <>
                <div className="invoice-detail-grid">
                  <div><span>Number</span><strong>{selectedInvoice.invoice_number}</strong></div>
                  <div><span>Customer</span><strong>{selectedInvoice.customer_name}</strong></div>
                  <div><span>Issued</span><strong>{new Date(selectedInvoice.issued_at).toLocaleDateString()}</strong></div>
                  {selectedInvoice.due_at && (
                    <div><span>Due</span><strong>{new Date(selectedInvoice.due_at).toLocaleDateString()}</strong></div>
                  )}
                  <div>
                    <span>Status</span>
                    <strong className={`status-pill status-${selectedInvoice.status}`}>{selectedInvoice.status}</strong>
                  </div>
                </div>

                <div className="invoice-items-list">
                  <h4>Items</h4>
                  {selectedInvoice.items?.map((item) => (
                    <div key={item.id} className="invoice-item-row">
                      <div>
                        <strong>{item.product?.name || item.description || 'Product'}</strong>
                        <p>
                          {item.quantity} x ${Number(item.unit_price).toFixed(2)}
                        </p>
                      </div>
                      <strong>${Number(item.line_total).toFixed(2)}</strong>
                    </div>
                  ))}
                </div>

                <p className="invoice-total-preview">
                  Total amount: <strong>${Number(selectedInvoice.total_amount).toFixed(2)}</strong>
                </p>

                {selectedInvoice.status !== 'paid' && (
                  <button
                    type="button"
                    onClick={() => handlePayment(selectedInvoice)}
                    disabled={payingId === selectedInvoice.id}
                  >
                    <CreditCard size={16} />
                    {payingId === selectedInvoice.id ? 'Processing...' : 'Pay invoice'}
                  </button>
                )}

                <button
                  type="button"
                  className="secondary"
                  onClick={() => handleDownloadPdf(selectedInvoice.id, selectedInvoice.invoice_number)}
                  disabled={downloadingId === selectedInvoice.id}
                >
                  <Download size={16} />
                  {downloadingId === selectedInvoice.id ? 'Generating PDF...' : 'Download PDF'}
                </button>
              </>
            )}
          </section>
        )}
      </div>
    </main>
  )
}

export default Invoices
