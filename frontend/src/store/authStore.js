import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export const useAuthStore = create(
  persist(
    (set, get) => ({
      token: null,
      refreshToken: null,
      user: null,
      role: 'staff',
      permissions: [],
      mustChangePassword: false,
      isAuthenticated: false,

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
        })
        localStorage.removeItem('api_token')
        localStorage.removeItem('refresh_token')
        localStorage.removeItem('user')
        localStorage.removeItem('user_role')
        localStorage.removeItem('must_change_password')
      },

      hasPermission: (permission) => {
        return get().permissions.includes(permission)
      },

      hydrate: () => {
        const token = localStorage.getItem('api_token')
        const refreshToken = localStorage.getItem('refresh_token')
        const storedUser = localStorage.getItem('user')
        const user = storedUser ? JSON.parse(storedUser) : null
        if (token && user) {
          set({
            token,
            refreshToken,
            user,
            role: user.role || 'staff',
            permissions: user.permissions || [],
            mustChangePassword: localStorage.getItem('must_change_password') === 'true' || user.must_change_password === true,
            isAuthenticated: true,
          })
        }
      },
    }),
    {
      name: 'aims-auth',
      partialize: (state) => ({
        token: state.token,
        refreshToken: state.refreshToken,
        user: state.user,
        role: state.role,
        permissions: state.permissions,
        mustChangePassword: state.mustChangePassword,
        isAuthenticated: state.isAuthenticated,
      }),
    }
  )
)
