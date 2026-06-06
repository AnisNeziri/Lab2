import { apiRequest, buildApiUrl, authenticatedFetch, parseApiResponse } from './client'

export async function getInvoices() {
  return apiRequest(buildApiUrl('/invoices'), {}, 'Failed to load invoices')
}

export async function getInvoice(id) {
  return apiRequest(buildApiUrl(`/invoices/${id}`), {}, 'Failed to load invoice details')
}

export async function downloadInvoicePdf(id, invoiceNumber) {
  const response = await authenticatedFetch(buildApiUrl(`/invoices/${id}/pdf`))

  const data = await parseApiResponse(response, 'Failed to download PDF')

  if (data.error) {
    throw new Error(data.error)
  }

  const { pdf, filename } = data

  const link = document.createElement('a')
  link.href = `data:application/pdf;base64,${pdf}`
  link.download = filename || `invoice-${invoiceNumber}.pdf`
  link.click()
}
