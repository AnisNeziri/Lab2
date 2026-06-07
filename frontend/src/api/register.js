import { apiRequest } from './client'

export async function register({ name, email, password, password_confirmation }) {
  return apiRequest('/register', {
    method: 'POST',
    body: JSON.stringify({ name, email, password, password_confirmation }),
  }, 'Failed to register')
}
