import { apiRequest, authenticatedFetch, buildApiUrl } from './client'

export const readOpening = (path = '', params = {}) => apiRequest(buildApiUrl(`/onboarding${path}`, params))
export const writeOpening = (path, data = {}, method = 'POST') => apiRequest(`/onboarding${path}`, { method, body: data instanceof FormData ? data : JSON.stringify(data) })
export async function downloadOpening(path, name) {
  const response = await authenticatedFetch(`/onboarding${path}`)
  if (!response.ok) throw new Error('The download could not be completed.')
  const url = URL.createObjectURL(await response.blob())
  const link = document.createElement('a'); link.href = url; link.download = name; link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
