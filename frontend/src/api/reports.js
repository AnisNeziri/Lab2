import { apiRequest, buildApiUrl } from './client'

export async function getReports() {
  return apiRequest(buildApiUrl('/reports'), {}, 'Failed to load reports')
}
