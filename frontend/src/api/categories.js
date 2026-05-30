const API_BASE = '/api'

export async function getCategories() {
  const response = await fetch(`${API_BASE}/categories`)

  if (!response.ok) {
    throw new Error('Failed to load categories')
  }

  return response.json()
}

export async function createCategory(name) {
  const response = await fetch(`${API_BASE}/categories`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify({ name }),
  })

  if (!response.ok) {
    const error = await response.json()
    throw error
  }

  return response.json()
}
