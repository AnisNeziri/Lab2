import { apiRequest, buildApiUrl } from './client'

export async function getActivityLogs(page = 1) {
  const url = buildApiUrl('/activity-logs', { page })
  return apiRequest(url, {}, 'Failed to load activity logs')
}
