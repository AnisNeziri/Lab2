import { apiRequest, authenticatedFetch, buildApiUrl } from './client'
export const orders = (filters) => apiRequest(buildApiUrl('/sales-orders', filters))
export const order = (id) => apiRequest(`/sales-orders/${id}`)
export const queues = (filters={}) => apiRequest(buildApiUrl('/fulfillment/queues',filters))
export const create = (data) => apiRequest('/sales-orders', { method:'POST', body:JSON.stringify(data) })
export async function action(id, name, data) {
  const response = await authenticatedFetch(buildApiUrl(`/sales-orders/${id}/${name}`), { method:'POST', body:JSON.stringify(data), headers:{'Content-Type':'application/json'} })
  const result = await response.json()
  if (!response.ok) { const error = new Error(Object.values(result.errors || {}).flat().join(' ') || result.message); error.credit = result.credit_control; throw error }
  return result
}
export const candidates = (id) => apiRequest(`/sales-order-items/${id}/candidates`)
export const wave = (data) => apiRequest('/fulfillment/waves', {method:'POST',body:JSON.stringify(data)})
export async function slip(id) {
  const response = await authenticatedFetch(buildApiUrl(`/sales-orders/${id}/packing-slip`))
  if (!response.ok) throw new Error('Could not generate packing slip')
  const href=URL.createObjectURL(await response.blob()); const a=document.createElement('a'); a.href=href;a.download=`SO-${id}-packing-slip.pdf`;a.click();setTimeout(()=>URL.revokeObjectURL(href),1000)
}
