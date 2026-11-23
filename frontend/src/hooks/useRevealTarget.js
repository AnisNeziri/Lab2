import { useCallback, useEffect, useState } from 'react'

export function revealElement(element) {
  if (!element) return
  element.focus({ preventScroll: true })
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  element.scrollIntoView({ block: 'start', behavior: reducedMotion ? 'instant' : 'smooth' })
}

export function useRevealTarget() {
  const [target, setTarget] = useState(null)
  useEffect(() => {
    if (!target) return
    const frame = requestAnimationFrame(() => revealElement(document.getElementById(target.id)))
    return () => cancelAnimationFrame(frame)
  }, [target])
  return useCallback(id => setTarget({ id }), [])
}
