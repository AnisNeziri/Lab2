import { apiRequest, buildApiUrl } from './client'

export async function getInvoices() {
  return apiRequest('/invoices', {}, 'Failed to load invoices')
}

export async function getInvoice(id) {
  return apiRequest(`/invoices/${id}`, {}, 'Failed to load invoice details')
}

export async function downloadInvoicePdf(id, invoiceNumber) {
  const token = localStorage.getItem('api_token')
  const response = await fetch(buildApiUrl(`/invoices/${id}/pdf`), {
    headers: {
      'Authorization': `Bearer ${token}`,
    }
  })

  if (!response.ok) {
    throw new Error('Failed to download PDF')
  }

  const data = await response.json()

  if (data.error) {
    throw new Error(data.error || data.message)
  }

  const { pdf, filename } = data

  // Krijo link nga base64
  const link = document.createElement('a')
  link.href = `data:application/pdf;base64,${pdf}`
  link.download = filename || `invoice-${invoiceNumber}.pdf`
  link.click()
}
