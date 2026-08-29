import { apiRequest, authenticatedFetch } from './client'

export const BACKUP_MAX_BYTES = 100 * 1024 * 1024
export const BACKUP_MIN_PASSPHRASE_LENGTH = 12

export function getBackupCapabilities() {
  return apiRequest('/backup/modules', {}, 'Backup capabilities could not be loaded.')
}

function errorFromResponse(response, fallback) {
  return response.json()
    .catch(() => ({}))
    .then((payload) => {
      const error = new Error(payload?.message || fallback)
      error.errors = payload?.errors
      error.code = payload?.code
      throw error
    })
}

function filenameFromResponse(response, fallback) {
  const disposition = response.headers.get('content-disposition') || ''
  const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  const quoted = disposition.match(/filename="([^"]+)"/i)?.[1]
  const plain = disposition.match(/filename=([^;]+)/i)?.[1]?.trim()

  if (encoded) {
    try {
      return decodeURIComponent(encoded)
    } catch {
      return encoded
    }
  }

  return quoted || plain || fallback
}

async function responseBlob(response, onProgress) {
  const total = Number(response.headers.get('content-length') || 0)

  if (!response.body?.getReader) {
    onProgress?.({ phase: 'downloading', percent: null })
    return response.blob()
  }

  const reader = response.body.getReader()
  const chunks = []
  let received = 0

  while (true) {
    const { done, value } = await reader.read()
    if (done) break
    chunks.push(value)
    received += value.byteLength
    onProgress?.({
      phase: 'downloading',
      percent: total > 0 ? Math.min(99, Math.round((received / total) * 100)) : null,
    })
  }

  return new Blob(chunks, {
    type: response.headers.get('content-type') || 'application/json',
  })
}

function saveBlob(blob, filename) {
  const url = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.style.display = 'none'
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => window.URL.revokeObjectURL(url), 1000)
}

export async function downloadBackup(modules = [], passphrase, onProgress) {
  onProgress?.({ phase: 'preparing', percent: 8 })
  const response = await authenticatedFetch('/backup/export', {
    method: 'POST',
    headers: {
      Accept: 'application/octet-stream, application/json',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ modules, passphrase }),
  })

  if (!response.ok) {
    return errorFromResponse(response, 'Backup could not be created.')
  }

  const blob = await responseBlob(response, onProgress)
  const fallback = `aims-backup-${new Date().toISOString().slice(0, 10)}.aimsbackup`
  saveBlob(blob, filenameFromResponse(response, fallback))
  onProgress?.({ phase: 'complete', percent: 100 })

  return { filename: filenameFromResponse(response, fallback), size: blob.size }
}

function normalizeModules(value) {
  if (Array.isArray(value)) {
    return value
      .map((module) => typeof module === 'string' ? module : module?.id || module?.key || module?.name)
      .filter(Boolean)
  }

  if (value && typeof value === 'object') {
    return Object.keys(value)
  }

  return []
}

export async function inspectBackupFile(file, maxBytes = BACKUP_MAX_BYTES) {
  if (!file) throw new Error('Choose an AIMS backup file.')
  if (file.size === 0) throw new Error('The selected backup file is empty.')
  if (file.size > maxBytes) throw new Error('The backup file is larger than the allowed upload limit.')

  const name = file.name.toLowerCase()
  if (!name.endsWith('.aimsbackup') && !name.endsWith('.json')) {
    throw new Error('Choose a .aimsbackup or .json file created by AIMS.')
  }

  let payload
  try {
    payload = JSON.parse(await file.text())
  } catch {
    if (name.endsWith('.aimsbackup')) {
      return {
        format: 'Encrypted AIMS backup',
        version: '—',
        createdAt: null,
        company: '',
        modules: [],
        size: file.size,
        encrypted: true,
      }
    }
    throw new Error('The selected file is not valid JSON and cannot be restored.')
  }

  if (!payload || Array.isArray(payload) || typeof payload !== 'object') {
    throw new Error('The selected file is not a valid AIMS backup.')
  }

  const manifest = payload.manifest && typeof payload.manifest === 'object'
    ? payload.manifest
    : payload
  const format = manifest.format || manifest.backup_format || payload.format || payload.backup_format
  const modules = [
    ...normalizeModules(manifest.modules),
    ...normalizeModules(payload.modules),
    ...normalizeModules(payload.data),
  ].filter((module, index, values) => values.indexOf(module) === index)

  // AIMS backups are self-describing. This deliberately rejects arbitrary JSON
  // report exports before they can reach the destructive restore endpoint.
  const looksLikeAimsBackup = Boolean(
    format
    || manifest.version
    || manifest.schema_version
    || payload.backup_version
    || (payload.data && modules.length > 0),
  )

  if (!looksLikeAimsBackup) {
    throw new Error('This is a data export, not a restorable AIMS backup file.')
  }

  return {
    format: format || 'AIMS backup',
    version: manifest.version || manifest.schema_version || payload.version || payload.backup_version || '—',
    createdAt: manifest.created_at || manifest.generated_at || payload.created_at || payload.generated_at || null,
    company: manifest.company?.name || manifest.company_name || payload.company?.name || payload.company_name || '',
    modules,
    size: file.size,
    encrypted: format === 'aims-encrypted-backup' || Boolean(payload.encryption),
  }
}

export async function restoreBackup(file, {
  mode = 'merge',
  modules = [],
  passphrase = '',
  confirmReplace = false,
} = {}, onProgress) {
  const formData = new FormData()
  formData.append('file', file)
  formData.append('mode', mode)
  formData.append('passphrase', passphrase)
  if (confirmReplace) formData.append('confirm_replace', '1')
  if (modules.length > 0) formData.append('modules', modules.join(','))

  onProgress?.({ phase: 'uploading', percent: 25 })
  const response = await apiRequest('/backup/import', {
    method: 'POST',
    body: formData,
  }, 'The AIMS backup could not be restored.')
  onProgress?.({ phase: 'complete', percent: 100 })
  return response
}
