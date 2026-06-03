import { apiRequest, buildApiUrl } from './client'

export async function getStockMovements() {
  return apiRequest(buildApiUrl('/stock-movements'), {}, 'Failed to load stock movements')
}

export async function createStockMovement(movement) {
  return apiRequest(
    buildApiUrl('/stock-movements'),
    {
      method: 'POST',
      body: JSON.stringify(movement),
    },
    'Failed to record stock movement'
  )
}
