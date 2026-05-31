const API_BASE = '/api'

export async function getDashboard() {
  const response = await fetch(`${API_BASE}/dashboard`)

  if (!response.ok) {
    throw new Error('Failed to load dashboard')
  }

  return response.json()
}
