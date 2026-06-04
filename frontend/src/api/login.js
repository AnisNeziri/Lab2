import { apiRequest } from './client'

export async function login(email, password) {
  return apiRequest('/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  }, 'Failed to login')
}

export async function logout() {
  return apiRequest('/logout', {
    method: 'POST',
  }, 'Failed to logout')
}
