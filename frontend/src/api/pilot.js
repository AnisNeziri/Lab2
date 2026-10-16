import { apiRequest } from './client'
export const pilot = (path = '', options = {}) => apiRequest(`/pilot${path}`, options, 'Could not complete the pilot request.')
export const writePilot = (path, data, method = 'POST') => pilot(path, { method, body: JSON.stringify(data) })
