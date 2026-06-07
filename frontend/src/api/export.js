import { authenticatedFetch } from './client'

export async function downloadExport(list, format = 'csv') {
  const response = await authenticatedFetch(`/export/${list}?format=${format}`)

  if (!response.ok) {
    const data = await response.json().catch(() => ({}))
    throw new Error(data.message || 'Export failed')
  }

  const blob = await response.blob()
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${list}.${format === 'xlsx' ? 'xlsx' : format}`
  link.click()
  window.URL.revokeObjectURL(url)
}
