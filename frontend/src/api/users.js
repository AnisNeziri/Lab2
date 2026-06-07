import { apiRequest } from './client'

export function getUsers() {
  return apiRequest('/users')
}

export function createUser(payload) {
  return apiRequest('/users', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, 'Could not create user.')
}
