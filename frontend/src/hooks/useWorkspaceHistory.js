import { useLayoutEffect, useRef } from 'react'
import { useLocation, useNavigationType } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'
import { readSession, sessionKey, writeSession } from '../lib/sessionWorkspace'

export function useWorkspaceHistory() {
  const location = useLocation(), navigation = useNavigationType()
  const scope = useAuthStore(s => s.user ? `${s.user.company_id}:${s.user.id}` : 'anonymous')
  const positions = useRef({}), previous = useRef(null)
  useLayoutEffect(() => {
    const key = sessionKey(scope, 'history'), saved = readSession(sessionStorage, key, {})
    positions.current = saved
    const samePage = previous.current?.scope === scope && previous.current?.path === location.pathname
    const target = navigation === 'POP' ? Number(saved[location.key] || 0) : samePage ? window.scrollY : 0
    previous.current = { scope, path: location.pathname }
    let restoring = navigation === 'POP', timer, observer
    const restore = () => {
      window.scrollTo({ top: target, behavior: 'instant' })
      if (Math.abs(window.scrollY - target) < 2) stop()
    }
    const stop = () => { restoring = false; observer?.disconnect(); clearTimeout(timer) }
    const remember = () => {
      if (restoring) return
      const entries = Object.entries(positions.current).filter(([id]) => id !== location.key).slice(-49)
      positions.current = { ...Object.fromEntries(entries), [location.key]: window.scrollY }
      writeSession(sessionStorage, key, positions.current)
    }
    if (restoring) {
      observer = new ResizeObserver(restore)
      const content = document.getElementById('workspace-main')
      if (content) observer.observe(content)
      timer = setTimeout(stop, 4000)
    }
    restore()
    window.addEventListener('scroll', remember, { passive: true })
    window.addEventListener('wheel', stop, { passive: true })
    window.addEventListener('touchstart', stop, { passive: true })
    return () => { stop(); window.removeEventListener('scroll', remember); window.removeEventListener('wheel', stop); window.removeEventListener('touchstart', stop) }
  }, [location.key, scope])
}
