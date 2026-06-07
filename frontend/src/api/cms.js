import { apiRequest } from './client'

export function getPublishedPages() {
  return apiRequest('/cms/published')
}

export function getCmsPages() {
  return apiRequest('/cms')
}

export function updateCmsPage(id, data) {
  return apiRequest(`/cms/${id}`, {
    method: 'PUT',
    body: JSON.stringify(data),
  })
}
