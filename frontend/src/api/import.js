import { apiRequest } from './client'

async function uploadImport(path, file) {
  const formData = new FormData()
  formData.append('file', file)

  return apiRequest(path, {
    method: 'POST',
    body: formData,
  }, 'Import failed')
}

export function importProducts(file) {
  return uploadImport('/products/import', file)
}

export function importList(list, file) {
  return uploadImport(`/import/${list}`, file)
}
