export function systemStatus(data, failed = false) {
  if (failed) return { tone: 'is-offline', title: 'System status unavailable', message: 'Could not reach the status endpoint. Check the local backend and retry.' }
  if (!data) return null
  if (data.mode === 'offline') return { tone: data.cache_available === false ? 'is-offline' : 'is-online', title: 'Desktop offline mode', message: 'Intentional local operation. Inventory and sales are stored on this computer; Redis is not required.' + (data.cache_available === false ? ' The configured cache is unavailable. Set CACHE_STORE=file and run php artisan config:clear, then retry.' : '') }
  if (data.redis?.status === 'unavailable') return { tone: 'is-offline', title: 'Redis connection problem', message: data.redis.message }
  if (data.cache_available === false) return { tone: 'is-offline', title: 'Cache connection problem', message: 'Check the configured cache service. For local development, set CACHE_STORE=file and run php artisan config:clear, then retry.' }
  if (data.redis?.status === 'disabled') return { tone: 'is-online', title: 'Redis optional: disabled', message: data.redis.message }
  return null
}
