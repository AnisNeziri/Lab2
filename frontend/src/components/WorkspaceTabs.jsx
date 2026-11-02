import { useId, useRef } from 'react'
import { tabIcons } from './workspaceIcons'

// Automatic keyboard activation; only the selected tab belongs in the Tab order.
export default function WorkspaceTabs({ items, value, onChange, label, children }) {
  const id = useId(), nav = useRef(null)
  const selected = items.some(item => item.id === value) ? value : items[0]?.id
  function navigate(event) {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return
    event.preventDefault()
    const index = items.findIndex(item => item.id === selected)
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length
    onChange(items[next].id)
    nav.current?.querySelectorAll('[role=tab]')[next]?.focus()
  }
  return <>
    <nav ref={nav} className="workspace-tabs" role="tablist" aria-label={label} onKeyDown={navigate}>
      {items.map(item => {const Icon=item.icon||tabIcons[item.id];return <button key={item.id} id={`${id}-${item.id}`} type="button" role="tab" aria-selected={selected === item.id} aria-controls={`${id}-panel`} tabIndex={selected === item.id ? 0 : -1} onClick={() => onChange(item.id)}>{Icon&&<Icon size={15} strokeWidth={1.8} aria-hidden="true"/>}{item.label}</button>})}
    </nav>
    <div id={`${id}-panel`} className="workspace-tab-panel" role="tabpanel" aria-labelledby={`${id}-${selected}`} tabIndex={0}>{children}</div>
  </>
}
