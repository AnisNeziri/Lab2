const API_BASE = '/api'

let refreshPromise = null

export function buildApiUrl(path, params = {}) {
  const searchParams = new URLSearchParams()

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '' && value !== false) {
      searchParams.set(key, value)
    }
  })

  const query = searchParams.toString()
  const cleanPath = path.startsWith('/api') ? path.replace('/api', '') : path

  return query ? `${API_BASE}${cleanPath}?${query}` : `${API_BASE}${cleanPath}`
}

function clearAuthStorage() {
  localStorage.removeItem('api_token')
  localStorage.removeItem('refresh_token')
  localStorage.removeItem('user')
  localStorage.removeItem('user_role')
  localStorage.removeItem('must_change_password')
}

async function refreshAccessToken() {
  const refreshToken = localStorage.getItem('refresh_token')

  if (!refreshToken) {
    return false
  }

  if (!refreshPromise) {
    refreshPromise = fetch(buildApiUrl('/refresh'), {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ refresh_token: refreshToken }),
    })
      .then(async (response) => {
        const contentType = response.headers.get('content-type') ?? ''
        const payload = contentType.includes('application/json') ? await response.json() : null

        if (!response.ok || !payload?.access_token) {
          return false
        }

        localStorage.setItem('api_token', payload.access_token)
        if (payload.refresh_token) {
          localStorage.setItem('refresh_token', payload.refresh_token)
        }

        window.dispatchEvent(new CustomEvent('auth-token-refreshed', {
          detail: {
            accessToken: payload.access_token,
            refreshToken: payload.refresh_token ?? refreshToken,
          },
        }))

        return true
      })
      .catch(() => false)
      .finally(() => {
        refreshPromise = null
      })
  }

  return refreshPromise
}

export async function parseApiResponse(response, fallbackMessage) {
  if (response.status === 204) {
    return null
  }

  const contentType = response.headers.get('content-type') ?? ''
  const payload = contentType.includes('application/json') ? await response.json() : null

  if (response.status === 403 && payload?.code === 'PASSWORD_CHANGE_REQUIRED') {
    localStorage.setItem('must_change_password', 'true')
    window.dispatchEvent(new Event('password-change-required'))
    const error = new Error(payload.message ?? fallbackMessage)
    error.code = payload.code
    throw error
  }

  if (!response.ok) {
    const error = new Error(payload?.message ?? fallbackMessage)
    error.errors = payload?.errors
    error.code = payload?.code
    throw error
  }

  return payload
}

export async function authenticatedFetch(pathOrUrl, options = {}) {
  const makeRequest = async (retryOnUnauthorized = true) => {
    const token = localStorage.getItem('api_token')
    const url = pathOrUrl.startsWith('/api') || pathOrUrl.startsWith('http')
      ? pathOrUrl
      : buildApiUrl(pathOrUrl)

    const headers = {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    }

    const response = await fetch(url, {
      ...options,
      headers,
    })

    if (response.status === 401 && retryOnUnauthorized) {
      const refreshed = await refreshAccessToken()
      if (refreshed) {
        return makeRequest(false)
      }
      clearAuthStorage()
      window.dispatchEvent(new Event('auth-unauthorized'))
    }

    return response
  }

  return makeRequest()
}

export async function apiRequest(path, options = {}, fallbackMessage = 'API request failed') {
  const makeRequest = async (retryOnUnauthorized = true) => {
    const token = localStorage.getItem('api_token')

    const headers = {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    }

    const response = await fetch(buildApiUrl(path), {
      ...options,
      headers,
    })

    if (response.status === 401 && retryOnUnauthorized && !path.includes('/login') && !path.includes('/refresh')) {
      const refreshed = await refreshAccessToken()
      if (refreshed) {
        return makeRequest(false)
      }
      clearAuthStorage()
      window.dispatchEvent(new Event('auth-unauthorized'))
    }

    return parseApiResponse(response, fallbackMessage)
  }

  return makeRequest()
}
