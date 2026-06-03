import { apiRequest, buildApiUrl } from './client'

export async function getDashboard() {
  return apiRequest(buildApiUrl('/dashboard'), {}, 'Failed to load dashboard')
}
