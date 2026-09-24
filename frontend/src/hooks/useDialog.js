import { useEffect, useRef } from 'react'

// Focus handling is scoped to the dialog and restored on every close/unmount.
export function useDialog(onClose, busy = false) {
  const ref = useRef(null), closeRef = useRef(onClose), busyRef = useRef(busy)
  closeRef.current = onClose; busyRef.current = busy
  useEffect(() => {
    const previous = document.activeElement
    const selector = 'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), a[href], [tabindex="0"]'
    const controls = () => [...(ref.current?.querySelectorAll(selector) || [])].filter(node=>node.getClientRects().length)
    controls()[0]?.focus()
    const handle = event => {
      if (event.key === 'Escape' && !busyRef.current) { event.preventDefault(); closeRef.current?.() }
      if (event.key !== 'Tab') return
      const all = controls(), first = all[0], last = all.at(-1)
      if (!all.length) { event.preventDefault(); return }
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
    }
    document.addEventListener('keydown', handle)
    return () => { document.removeEventListener('keydown', handle); if(previous?.isConnected) previous.focus() }
  }, [])
  return ref
}
