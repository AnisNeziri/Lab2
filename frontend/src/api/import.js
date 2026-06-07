import { buildApiUrl } from './client'

export async function importProducts(file) {
  const token = localStorage.getItem('api_token')
  const formData = new FormData()
  formData.append('file', file)

  const response = await fetch(buildApiUrl('/products/import'), {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${token}`,
    },
    body: formData,
  })

  const data = await response.json()

  if (!response.ok) {
    throw new Error(data.message || 'Import failed')
  }

  return data
}
