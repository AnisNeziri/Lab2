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

export function updateUserRole(userId, role) {
  return apiRequest(`/users/${userId}`, {
    method: 'PUT',
    body: JSON.stringify({ role }),
  }, 'Could not update user role.')
}

export function deleteUser(userId) {
  return apiRequest(`/users/${userId}`, {
    method: 'DELETE',
  }, 'Could not remove user.')
}
