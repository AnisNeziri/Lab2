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

// Record a partial or full payment with optional note
export function recordPayment(invoiceId, amount, note = '', paymentMethod = 'cash') {
  return apiRequest('/payments', {
    method: 'POST',
    body: JSON.stringify({
      invoice_id:     invoiceId,
      amount:         amount,
      note:           note || undefined,
      payment_method: paymentMethod,
    }),
  })
}
