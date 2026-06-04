import { apiRequest } from './client'

export async function getInvoices() {
  return apiRequest('/invoices', {}, 'Failed to load invoices')
}

export async function getInvoice(id) {
  return apiRequest(`/invoices/${id}`, {}, 'Failed to load invoice details')
}

export async function downloadInvoicePdf(id, invoiceNumber) {
  const token = localStorage.getItem('api_token')
  const response = await fetch(`/api/invoices/${id}/pdf`, {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  })
  
  if (!response.ok) {
    throw new Error('Failed to download PDF')
  }
  
  const blob = await response.blob()
  const url = window.URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `invoice-${invoiceNumber}.pdf`
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.URL.revokeObjectURL(url)
}
