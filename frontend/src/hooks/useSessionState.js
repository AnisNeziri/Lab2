import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuthStore } from '../store/authStore'
import { readSession, sessionKey, writeSession } from '../lib/sessionWorkspace'

// Working filters only: never use this for forms, credentials or business records.
export function useSessionState(name, initial) {
  const scope = useAuthStore(s => s.user ? `${s.user.company_id}:${s.user.id}` : 'anonymous')
  const key = sessionKey(scope, name), fallback = useRef(initial)
  const [record, setRecord] = useState(() => ({ key, value: readSession(sessionStorage, key, initial) }))
  const value = record.key === key ? record.value : readSession(sessionStorage, key, fallback.current)
  useEffect(() => { if (record.key !== key) setRecord({ key, value: readSession(sessionStorage, key, fallback.current) }) }, [key, record.key])
  useEffect(() => { if (record.key === key && scope !== 'anonymous') writeSession(sessionStorage, key, record.value) }, [key, record, scope])
  const update = useCallback(next => setRecord(current => {
    const previous = current.key === key ? current.value : readSession(sessionStorage, key, fallback.current)
    return { key, value: typeof next === 'function' ? next(previous) : next }
  }), [key])
  return [value, update]
}
