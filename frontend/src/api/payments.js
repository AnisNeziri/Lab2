import { apiRequest } from './client'

export function getPayments() {
  return apiRequest('/payments')
}

export function processPayment(invoiceId, payload = {}) {
  return apiRequest('/payments', {
    method: 'POST',
    body: JSON.stringify({ invoice_id: invoiceId, ...payload }),
  })
}
