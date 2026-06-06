import { apiRequest, buildApiUrl } from './client'

export async function getActivityLogs(page = 1) {
  return apiRequest(buildApiUrl('/activity-logs', { page }), {}, 'Failed to load activity logs')
}
