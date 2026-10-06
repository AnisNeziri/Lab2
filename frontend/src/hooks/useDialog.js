import { useEffect, useRef } from 'react'

// Focus handling is scoped to the dialog and restored on every close/unmount.
const dialogs = []
let previousOverflow = ''

export function useDialog(onClose, busy = false, open = true) {
  const ref = useRef(null), closeRef = useRef(onClose), busyRef = useRef(busy)
  closeRef.current = onClose; busyRef.current = busy
  useEffect(() => {
    if (!open) return undefined
    const token = Symbol('dialog')
    if (!dialogs.length) {
      previousOverflow = document.body.style.overflow
      document.body.style.overflow = 'hidden'
    }
    dialogs.push(token)
    const previous = document.activeElement
    const selector = 'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]'
    const controls = () => [...(ref.current?.querySelectorAll(selector) || [])].filter(node=>node.getClientRects().length)
    const frame = requestAnimationFrame(() => controls()[0]?.focus())
    const handle = event => {
      if (dialogs.at(-1) !== token) return
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); if (!busyRef.current) closeRef.current?.(); return }
      if (event.key !== 'Tab') return
      event.stopPropagation()
      const all = controls(), first = all[0], last = all.at(-1)
      if (!all.length) { event.preventDefault(); return }
      if (event.shiftKey && (document.activeElement === first || !ref.current?.contains(document.activeElement))) { event.preventDefault(); last.focus() }
      if (!event.shiftKey && (document.activeElement === last || !ref.current?.contains(document.activeElement))) { event.preventDefault(); first.focus() }
    }
    document.addEventListener('keydown', handle, true)
    return () => {
      cancelAnimationFrame(frame)
      document.removeEventListener('keydown', handle, true)
      const top = dialogs.at(-1) === token
      dialogs.splice(dialogs.indexOf(token), 1)
      if (!dialogs.length) document.body.style.overflow = previousOverflow
      if (top && previous?.isConnected) previous.focus({ preventScroll: true })
    }
  }, [open])
  return ref
}
