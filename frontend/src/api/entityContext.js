import { apiRequest } from './client'

export const getEntityContext = (type, id) => apiRequest(`/entity-context/${type}/${id}`, {}, 'Could not load recent activity.')
