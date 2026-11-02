import { useCallback, useEffect, useRef, useState } from 'react'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../store/authStore'

// Shares the application's stock events / 15-second poll. There is no new poller.
export function useInventoryResource(url) {
  const scope = useAuthStore(s => `${s.user?.company_id}:${s.user?.id}:${s.permissions.join(',')}`)
  const identity = `${scope}:${url}`
  const empty = { identity, data: null, health: 'refreshing', updated: null }
  const [state, setState] = useState(empty)
  const sequence = useRef(0), controller = useRef(null)
  const reload = useCallback(async () => {
    if (!url) return
    const request = ++sequence.current
    controller.current?.abort(); controller.current = new AbortController()
    if (!navigator.onLine) { setState(s => ({ ...s, health: 'disconnected' })); return }
    setState(s => ({ ...(s.identity === identity ? s : empty), health: 'refreshing' }))
    try {
      const data = await apiRequest(url, { signal: controller.current.signal })
      if (request === sequence.current) setState({ identity, data, health: 'live', updated: new Date() })
    } catch {
      if (request === sequence.current) setState(s => ({ ...s, health: 'disconnected' }))
    }
  }, [url, scope])
  useEffect(() => {
    setState(empty)
    if (!url) return
    let timer
    void reload()
    const refresh = () => { if (!document.hidden) { clearTimeout(timer); timer = setTimeout(reload, 150) } }
    const offline = () => { ++sequence.current; controller.current?.abort(); setState(s => ({ ...s, health: 'disconnected' })) }
    const visibility = () => {
      if (document.hidden) { ++sequence.current; controller.current?.abort(); clearTimeout(timer); setState(s => ({ ...s, health: 'paused' })) }
      else refresh()
    }
    window.addEventListener('database-refresh', refresh); window.addEventListener('stock-refresh', refresh)
    window.addEventListener('online', refresh); window.addEventListener('offline', offline); document.addEventListener('visibilitychange', visibility)
    return () => {
      ++sequence.current; clearTimeout(timer); controller.current?.abort()
      window.removeEventListener('database-refresh', refresh); window.removeEventListener('stock-refresh', refresh)
      window.removeEventListener('online', refresh); window.removeEventListener('offline', offline); document.removeEventListener('visibilitychange', visibility)
    }
  }, [reload, url])
  // Never render a previous user's/warehouse's facts while the effect resets.
  return { ...(state.identity === identity ? state : empty), reload }
}
