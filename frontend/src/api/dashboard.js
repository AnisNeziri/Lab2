import { apiRequest, buildApiUrl } from './client'

export async function getDashboard() {
  return apiRequest(buildApiUrl('/dashboard'), {}, 'Failed to load dashboard')
}

export async function getSalesAnalytics(period = 'week', date = '') {
  const params = new URLSearchParams({ period })
  if (date) params.set('date', date)
  return apiRequest(buildApiUrl(`/dashboard/sales-analytics?${params.toString()}`), {}, 'Failed to load sales analytics')
}
