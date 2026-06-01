const API_BASE = '/api'

export async function getStockMovements() {
  const response = await fetch(`${API_BASE}/stock-movements`)

  if (!response.ok) {
    throw new Error('Failed to load stock movements')
  }

  return response.json()
}

export async function createStockMovement(movement) {
  const response = await fetch(`${API_BASE}/stock-movements`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: JSON.stringify(movement),
  })

  if (!response.ok) {
    const error = await response.json()
    throw error
  }

  return response.json()
}
