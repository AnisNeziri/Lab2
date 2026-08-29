import { apiRequest, buildApiUrl } from './client'

export const getMobileWarehouseBootstrap = () => apiRequest(
  '/mobile-warehouse/bootstrap',
  {},
  'Could not load mobile warehouse data.',
)

export const lookupWarehouseProduct = (code) => apiRequest(
  buildApiUrl('/mobile-warehouse/lookup', { code }),
  {},
  'Product lookup failed.',
)

export const receiveWarehouseProduct = (payload) => apiRequest(
  '/mobile-warehouse/receive',
  { method: 'POST', body: JSON.stringify(payload) },
  'The receipt could not be posted.',
)

export const moveWarehouseProduct = (payload) => apiRequest(
  '/mobile-warehouse/move',
  { method: 'POST', body: JSON.stringify(payload) },
  'The stock could not be moved.',
)

export const countWarehouseProduct = (payload) => apiRequest(
  '/mobile-warehouse/count',
  { method: 'POST', body: JSON.stringify(payload) },
  'The count could not be recorded.',
)

export const pickWarehouseProduct = (payload) => apiRequest(
  '/mobile-warehouse/pick',
  { method: 'POST', body: JSON.stringify(payload) },
  'The pick could not be posted.',
)
