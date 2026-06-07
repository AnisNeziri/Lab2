import { buildApiUrl } from './client'

async function uploadImport(path, file) {
  const token = localStorage.getItem('api_token')
  const formData = new FormData()
  formData.append('file', file)

  const response = await fetch(buildApiUrl(path), {
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

export function importProducts(file) {
  return uploadImport('/products/import', file)
}

export function importList(list, file) {
  return uploadImport(`/import/${list}`, file)
}
