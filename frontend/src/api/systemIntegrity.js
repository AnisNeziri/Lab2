import { apiRequest } from './client'

export const getSystemIntegrity = () => apiRequest('/system-integrity', {}, 'Could not load system integrity checks.')
