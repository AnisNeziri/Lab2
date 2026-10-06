import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { MoreHorizontal } from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'

export default function RowActions({ label, actions }) {
  const { language } = useTranslation(), id = useId(), trigger = useRef(null), menu = useRef(null)
  const [open, setOpen] = useState(false), [position, setPosition] = useState({ top: 0, left: 0 })
  const close = () => { setOpen(false); trigger.current?.focus({ preventScroll: true }) }
  useLayoutEffect(() => {
    if (!open) return
    const rect = trigger.current.getBoundingClientRect(), height = menu.current?.offsetHeight || 140
    setPosition({ left: Math.max(8, Math.min(rect.right - 190, window.innerWidth - 198)), top: rect.bottom + height + 8 > window.innerHeight ? Math.max(8, rect.top - height - 4) : rect.bottom + 4 })
    menu.current?.querySelector('button:not(:disabled)')?.focus({ preventScroll: true })
  }, [open])
  useEffect(() => {
    if (!open) return
    const dismiss = event => { if (!menu.current?.contains(event.target) && !trigger.current?.contains(event.target)) setOpen(false) }
    const hide = () => setOpen(false)
    document.addEventListener('pointerdown', dismiss); window.addEventListener('resize', hide); window.addEventListener('scroll', hide, true)
    return () => { document.removeEventListener('pointerdown', dismiss); window.removeEventListener('resize', hide); window.removeEventListener('scroll', hide, true) }
  }, [open])
  const keyboard = e => {
    const buttons = [...menu.current.querySelectorAll('button:not(:disabled)')], index = buttons.indexOf(document.activeElement)
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close() }
    if (e.key === 'Tab') setOpen(false)
    if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
      e.preventDefault()
      const next = e.key === 'Home' ? 0 : e.key === 'End' ? buttons.length - 1 : (index + (e.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length
      buttons[next]?.focus({ preventScroll: true })
    }
  }
  return <><button ref={trigger} type="button" className="secondary row-actions-trigger" aria-label={`${language === 'sq' ? 'Veprimet për' : 'Actions for'} ${label}`} title={language === 'sq' ? 'Më shumë veprime' : 'More actions'} aria-haspopup="menu" aria-expanded={open} aria-controls={open ? id : undefined} onClick={() => setOpen(v => !v)}><MoreHorizontal size={18}/></button>
    {open && createPortal(<div ref={menu} id={id} className="workspace-row-menu" role="menu" aria-label={label} style={position} onKeyDown={keyboard}>{actions.filter(Boolean).map(action => <button key={action.label} role="menuitem" type="button" className={action.danger ? 'is-danger' : ''} disabled={action.disabled} onClick={() => { setOpen(false); trigger.current?.focus({ preventScroll: true }); action.onClick() }}>{action.icon}{action.label}</button>)}</div>, document.body)}</>
}
