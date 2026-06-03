const API_BASE = '/api'

export async function getReports() {
  const response = await fetch(`${API_BASE}/reports`)

  if (!response.ok) {
    throw new Error('Failed to load reports')
  }

  return response.json()
}
