const API_BASE = '/api'

export function buildApiUrl(path, params = {}) {
  const searchParams = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== false) {
      searchParams.set(key, value)
    }
  })

  const query = searchParams.toString()

  return query ? `${API_BASE}${path}?${query}` : `${API_BASE}${path}`
}

export async function parseApiResponse(response, fallbackMessage) {
  if (response.status === 204) {
    return null
  }

  const contentType = response.headers.get('content-type') ?? ''
  const payload = contentType.includes('application/json') ? await response.json() : null

  if (!response.ok) {
    const error = new Error(payload?.message ?? fallbackMessage)
    error.errors = payload?.errors
    throw error
  }

  return payload
}

export async function apiRequest(path, options = {}, fallbackMessage = 'API request failed') {
  const response = await fetch(path, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...options.headers,
    },
  })

  return parseApiResponse(response, fallbackMessage)
}
