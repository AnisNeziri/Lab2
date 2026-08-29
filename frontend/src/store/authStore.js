import { create } from 'zustand'
import { getCurrentUser } from '../api/login'

function isTokenExpired(token) {
  if (!token) return true

  try {
    const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')))
    if (!payload?.exp) return true

    return payload.exp * 1000 <= Date.now()
  } catch {
    return true
  }
}

export const useAuthStore = create((set, get) => ({
  token: null,
  refreshToken: null,
  user: null,
  role: 'staff',
  permissions: [],
  mustChangePassword: false,
  isAuthenticated: false,
  authReady: false,

  setAuth: (token, user, refreshToken = null) => {
    const accessToken = token
    const nextRefreshToken = refreshToken ?? get().refreshToken

    set({
      token: accessToken,
      refreshToken: nextRefreshToken,
      user,
      role: user.role,
      permissions: user.permissions || [],
      mustChangePassword: user.must_change_password === true,
      isAuthenticated: true,
      authReady: true,
    })

    localStorage.setItem('api_token', accessToken)
    if (nextRefreshToken) {
      localStorage.setItem('refresh_token', nextRefreshToken)
    }
    localStorage.setItem('user_role', user.role)
    localStorage.setItem('user', JSON.stringify(user))
    if (user.must_change_password) {
      localStorage.setItem('must_change_password', 'true')
    } else {
      localStorage.removeItem('must_change_password')
    }
    localStorage.removeItem('aims-auth')
  },

  setTokens: (accessToken, refreshToken = null) => {
    set({
      token: accessToken,
      refreshToken: refreshToken ?? get().refreshToken,
    })
    localStorage.setItem('api_token', accessToken)
    if (refreshToken) {
      localStorage.setItem('refresh_token', refreshToken)
    }
  },

  updateUser: (user) => {
    set({
      user,
      role: user.role,
      permissions: user.permissions || [],
      mustChangePassword: user.must_change_password === true,
    })
    localStorage.setItem('user_role', user.role)
    localStorage.setItem('user', JSON.stringify(user))
    if (user.must_change_password) {
      localStorage.setItem('must_change_password', 'true')
    } else {
      localStorage.removeItem('must_change_password')
    }
  },

  clearAuth: () => {
    set({
      token: null,
      refreshToken: null,
      user: null,
      role: 'staff',
      permissions: [],
      mustChangePassword: false,
      isAuthenticated: false,
      authReady: true,
    })
    localStorage.removeItem('api_token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('user')
    localStorage.removeItem('user_role')
    localStorage.removeItem('must_change_password')
    localStorage.removeItem('aims-auth')
  },

  hasValidSession: () => {
    const { token, user, isAuthenticated } = get()
    return Boolean(isAuthenticated && token && user && !isTokenExpired(token))
  },

  hasPermission: (permission) => {
    return get().permissions.includes(permission)
  },

  syncSession: async () => {
    const { token, isAuthenticated } = get()
    if (!isAuthenticated || !token || isTokenExpired(token)) {
      return
    }

    try {
      const user = await getCurrentUser()
      get().updateUser(user)
    } catch {
      // Keep cached session if profile refresh fails transiently.
    }
  },

  hydrate: () => {
    try {
      const token = localStorage.getItem('api_token')
      const refreshToken = localStorage.getItem('refresh_token')
      const storedUser = localStorage.getItem('user')
      let user = null

      if (storedUser) {
        try {
          user = JSON.parse(storedUser)
        } catch {
          user = null
        }
      }

      if (!token || !user || isTokenExpired(token)) {
        localStorage.removeItem('api_token')
        localStorage.removeItem('refresh_token')
        localStorage.removeItem('user')
        localStorage.removeItem('user_role')
        localStorage.removeItem('must_change_password')
        localStorage.removeItem('aims-auth')
        set({ authReady: true })
        return
      }

      set({
        token,
        refreshToken,
        user,
        role: user.role || 'staff',
        permissions: user.permissions || [],
        mustChangePassword: localStorage.getItem('must_change_password') === 'true' || user.must_change_password === true,
        isAuthenticated: true,
        authReady: true,
      })
    } catch {
      localStorage.removeItem('api_token')
      localStorage.removeItem('refresh_token')
      localStorage.removeItem('user')
      localStorage.removeItem('user_role')
      localStorage.removeItem('must_change_password')
      localStorage.removeItem('aims-auth')
      set({ authReady: true })
    }
  },
}))

export { isTokenExpired }
