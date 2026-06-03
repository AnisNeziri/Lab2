import { apiRequest, buildApiUrl } from './client'

export async function getCategories() {
  return apiRequest(buildApiUrl('/categories'), {}, 'Failed to load categories')
}

export async function createCategory(name) {
  return apiRequest(
    buildApiUrl('/categories'),
    {
      method: 'POST',
      body: JSON.stringify({ name }),
    },
    'Failed to save category'
  )
}

export async function updateCategory(id, name) {
  return apiRequest(
    buildApiUrl(`/categories/${id}`),
    {
      method: 'PUT',
      body: JSON.stringify({ name }),
    },
    'Failed to update category'
  )
}

export async function deleteCategory(id) {
  await apiRequest(
    buildApiUrl(`/categories/${id}`),
    {
      method: 'DELETE',
    },
    'Failed to delete category'
  )
}
