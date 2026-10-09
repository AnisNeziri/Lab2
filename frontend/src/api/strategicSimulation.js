import { apiRequest } from './client'

const root = '/strategic-simulation'
const post = (path, body) => apiRequest(path, { method: 'POST', body: JSON.stringify(body) })

// Hypothetical runs use only the isolated V12 namespace. The response endpoint
// requires a separate explicit confirmation and creates a fresh V11 review plan.
export const simulationApi = {
  options: () => apiRequest(`${root}/options`),
  list: () => apiRequest(root),
  create: definition => post(root, definition),
  get: id => apiRequest(`${root}/${id}`),
  state: id => apiRequest(`${root}/${id}/state`),
  rerun: (id, definition) => post(`${root}/${id}/rerun`, definition),
  derive: (id, definition) => post(`${root}/${id}/derive`, definition),
  cancel: id => post(`${root}/${id}/cancel`, {}),
  compare: ids => post(`${root}/compare`, { ids }),
  sensitivity: (id, body) => post(`${root}/${id}/sensitivity`, body),
  prepareResponse: (id, body) => post(`${root}/${id}/response`, body),
}
