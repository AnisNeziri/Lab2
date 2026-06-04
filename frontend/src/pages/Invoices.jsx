import { useEffect, useState } from 'react'
import { getInvoices, getInvoice, downloadInvoicePdf } from '../api/invoices'
import { FileText, Download, X, Eye } from 'lucide-react'

function Invoices() {
  const [invoices, setInvoices] = useState([])
  const [selectedInvoice, setSelectedInvoice] = useState(null)
  const [loading, setLoading] = useState(true)
  const [loadingDetail, setLoadingDetail] = useState(false)
  const [downloadingId, setDownloadingId] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadInvoices() {
      try {
        setLoading(true)
        setError('')
        const data = await getInvoices()
        setInvoices(data)
      } catch (err) {
        setError('Failed to load invoices.')
      } finally {
        setLoading(false)
      }
    }
    loadInvoices()
  }, [])

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

  if (loading) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-slate-500 font-medium">Loading invoices...</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold text-slate-900">Invoices</h1>
          <p className="text-slate-500 text-sm">View, inspect, and generate PDF versions of customer invoices.</p>
        </div>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl">
          {error}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Invoices List */}
        <div className={`bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden ${selectedInvoice ? 'lg:col-span-2' : 'lg:col-span-3'}`}>
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200">
              <thead className="bg-slate-50">
                <tr>
                  <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Invoice Number</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Customer</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Issued Date</th>
                  <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                  <th className="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Total Amount</th>
                  <th className="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="bg-white divide-y divide-slate-100">
                {invoices.length === 0 ? (
                  <tr>
                    <td colSpan="6" className="px-6 py-10 text-center text-slate-400">
                      No invoices found.
                    </td>
                  </tr>
                ) : (
                  invoices.map((invoice) => (
                    <tr key={invoice.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-900">
                        {invoice.invoice_number}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                        {invoice.customer_name}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                        {new Date(invoice.issued_at).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2.5 py-1 rounded-full text-xs font-semibold capitalize ${
                          invoice.status === 'paid' ? 'bg-emerald-50 text-emerald-700' :
                          invoice.status === 'unpaid' ? 'bg-amber-50 text-amber-700' :
                          'bg-slate-100 text-slate-700'
                        }`}>
                          {invoice.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-slate-900">
                        ${Number(invoice.total_amount).toFixed(2)}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                        <button
                          onClick={() => handleViewDetails(invoice.id)}
                          className="inline-flex items-center gap-1.5 text-indigo-600 hover:text-indigo-900 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg transition-colors"
                        >
                          <Eye className="w-4 h-4" />
                          Details
                        </button>
                        <button
                          onClick={() => handleDownloadPdf(invoice.id, invoice.invoice_number)}
                          disabled={downloadingId === invoice.id}
                          className="inline-flex items-center gap-1.5 text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 px-3 py-1.5 rounded-lg transition-colors disabled:opacity-50"
                        >
                          <Download className="w-4 h-4" />
                          {downloadingId === invoice.id ? 'Generating...' : 'PDF'}
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Invoice Details Panel */}
        {selectedInvoice && (
          <div className="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6 self-start lg:col-span-1">
            <div className="flex justify-between items-center pb-4 border-b border-slate-100">
              <h2 className="text-lg font-bold text-slate-900">Invoice Details</h2>
              <button
                onClick={() => setSelectedInvoice(null)}
                className="text-slate-400 hover:text-slate-600 p-1 hover:bg-slate-100 rounded-lg transition-colors"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <div className="space-y-3">
              <div className="flex justify-between">
                <span className="text-slate-500 text-sm">Number:</span>
                <span className="font-semibold text-slate-900 text-sm">{selectedInvoice.invoice_number}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500 text-sm">Customer:</span>
                <span className="font-semibold text-slate-900 text-sm">{selectedInvoice.customer_name}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-slate-500 text-sm">Issued Date:</span>
                <span className="text-slate-700 text-sm">{new Date(selectedInvoice.issued_at).toLocaleDateString()}</span>
              </div>
              {selectedInvoice.due_at && (
                <div className="flex justify-between">
                  <span className="text-slate-500 text-sm">Due Date:</span>
                  <span className="text-slate-700 text-sm">{new Date(selectedInvoice.due_at).toLocaleDateString()}</span>
                </div>
              )}
              <div className="flex justify-between">
                <span className="text-slate-500 text-sm">Status:</span>
                <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-semibold capitalize ${
                  selectedInvoice.status === 'paid' ? 'bg-emerald-50 text-emerald-700' :
                  selectedInvoice.status === 'unpaid' ? 'bg-amber-50 text-amber-700' :
                  'bg-slate-100 text-slate-700'
                }`}>
                  {selectedInvoice.status}
                </span>
              </div>
            </div>

            <div className="border-t border-slate-100 pt-4">
              <h3 className="text-sm font-semibold text-slate-900 mb-3">Items</h3>
              <div className="space-y-3 max-h-60 overflow-y-auto pr-1">
                {selectedInvoice.items?.map((item) => (
                  <div key={item.id} className="flex justify-between items-start text-sm">
                    <div>
                      <p className="font-medium text-slate-800">{item.product?.name || 'Deleted Product'}</p>
                      <p className="text-xs text-slate-400">Qty: {item.quantity} × ${Number(item.unit_price).toFixed(2)}</p>
                    </div>
                    <span className="font-semibold text-slate-900">${Number(item.line_total).toFixed(2)}</span>
                  </div>
                ))}
              </div>
            </div>

            <div className="border-t border-slate-100 pt-4 flex justify-between items-center">
              <span className="font-bold text-slate-900 text-base">Total Amount:</span>
              <span className="font-extrabold text-slate-900 text-lg">${Number(selectedInvoice.total_amount).toFixed(2)}</span>
            </div>

            <button
              onClick={() => handleDownloadPdf(selectedInvoice.id, selectedInvoice.invoice_number)}
              disabled={downloadingId === selectedInvoice.id}
              className="w-full inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-2.5 px-4 rounded-xl transition-colors shadow-lg shadow-indigo-600/20 disabled:opacity-50"
            >
              <Download className="w-4 h-4" />
              {downloadingId === selectedInvoice.id ? 'Generating PDF...' : 'Download Invoice PDF'}
            </button>
          </div>
        )}
      </div>
    </div>
  )
}

export default Invoices
