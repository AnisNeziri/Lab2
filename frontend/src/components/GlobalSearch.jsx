import { useEffect, useMemo, useRef, useState } from 'react'
import { Command, Search, X } from 'lucide-react'
import { globalSearch } from '../api/search'
import { useAuthStore } from '../store/authStore'
import { matchingCommands } from '../config/commandCatalog'
import { createPortal } from 'react-dom'
import { useTranslation } from '../hooks/useTranslation'

const labels = {
  order_hub:'Orders',
  documents: 'Documents',
  sales_orders: 'Sales orders', pick_tasks: 'Pick tasks', pick_waves: 'Pick waves', dispatches: 'Dispatches / deliveries', returns: 'Customer returns',
  products: 'Products', stock_movements: 'Stock movements', suppliers: 'Suppliers',
  customers: 'Customers', invoices: 'Invoices', purchase_orders: 'Purchase orders',
  purchase_requests: 'Purchase requests', rfqs: 'RFQs', shipments: 'Shipments',
  containers: 'Containers', warehouses: 'Warehouses', bins: 'Bins', journals: 'Journals',
}
const labelsSq = { order_hub:'Porositë', documents: 'Dokumentet', sales_orders: 'Porositë e shitjeve', pick_tasks: 'Detyrat e mbledhjes', pick_waves: 'Valët e mbledhjes', dispatches: 'Dërgesat / dorëzimet', returns: 'Kthimet e klientëve', products: 'Produktet', stock_movements: 'Lëvizjet e stokut', suppliers: 'Furnitorët', customers: 'Klientët', invoices: 'Faturat', purchase_orders: 'Porositë e blerjes', purchase_requests: 'Kërkesat për blerje', rfqs: 'Kërkesat për oferta', shipments: 'Dërgesat', containers: 'Kontejnerët', warehouses: 'Depot', bins: 'Lokacionet', journals: 'Ditarët kontabël' }

export default function GlobalSearch({ onNavigate }) {
  const { t, language } = useTranslation()
  const permissions = useAuthStore((state) => state.permissions)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState({})
  const [isOpen, setIsOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)
  const inputRef = useRef(null)
  const dialogRef = useRef(null)
  const optionRefs = useRef([])
  const requestRef = useRef(0)
  const commands = useMemo(() => matchingCommands(query, permissions, language), [query, permissions, language])
  const sections = Object.entries(results || {}).filter(([, items]) => Array.isArray(items) && items.length)
  const visibleCommands = commands.slice(0, 8)
  const options = useMemo(() => [
    ...visibleCommands.map((command) => ({ key: `command-${command.id}`, path: command.path })),
    ...sections.flatMap(([name, items]) => items.map((item) => ({ key: `${name}-${item.id}`, path: item.url }))),
  ], [visibleCommands, sections])

  useEffect(() => {
    const openPalette = (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setIsOpen(true)
        requestAnimationFrame(() => inputRef.current?.focus())
      }
      if (event.key === 'Escape') setIsOpen(false)
    }
    window.addEventListener('keydown', openPalette)
    return () => window.removeEventListener('keydown', openPalette)
  }, [])

  useEffect(() => {
    if (!isOpen) return undefined
    const previousFocus = document.activeElement
    const trap = (event) => {
      if (event.key !== 'Tab') return
      const controls = [...(dialogRef.current?.querySelectorAll('input, button, a[href]') || [])].filter((node) => !node.disabled && node.getClientRects().length)
      const first = controls[0], last = controls.at(-1)
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
    }
    document.addEventListener('keydown', trap)
    return () => { document.removeEventListener('keydown', trap); if (previousFocus?.isConnected) previousFocus.focus() }
  }, [isOpen])

  useEffect(() => {
    const requestId = ++requestRef.current
    if (!isOpen || query.trim().length < 2) {
      setResults({})
      setError('')
      setLoading(false)
      return undefined
    }
    setResults({})
    setLoading(true)
    const timer = setTimeout(async () => {
      setLoading(true)
      setError('')
      try {
        const data = await globalSearch(query.trim())
        if (requestRef.current === requestId) setResults(data || {})
      } catch (cause) {
        if (requestRef.current === requestId) {
          setResults({})
          setError(cause?.message || t('search.error'))
        }
      } finally {
        if (requestRef.current === requestId) setLoading(false)
      }
    }, 240)
    return () => { clearTimeout(timer); requestRef.current++ }
  }, [query, isOpen, t])

  useEffect(() => {
    setActiveIndex(-1)
    optionRefs.current = []
  }, [query, isOpen])

  const select = (path) => {
    setIsOpen(false)
    setQuery('')
    onNavigate?.(path)
  }

  const handleKeyboardNavigation = (event) => {
    if (!options.length) return
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      const direction = event.key === 'ArrowDown' ? 1 : -1
      setActiveIndex((current) => {
        const next = current < 0
          ? (direction > 0 ? 0 : options.length - 1)
          : (current + direction + options.length) % options.length
        requestAnimationFrame(() => optionRefs.current[next]?.scrollIntoView({ block: 'nearest' }))
        return next
      })
    }
    if (event.key === 'Enter') {
      event.preventDefault()
      const option = options[activeIndex < 0 ? 0 : activeIndex]
      if (option) select(option.path)
    }
  }

  return (
    <>
      <button type="button" className="global-search-trigger" onClick={() => { setIsOpen(true); requestAnimationFrame(() => inputRef.current?.focus()) }}>
        <Search size={17} /><span>{t('search.title')}</span><kbd>Ctrl K</kbd>
      </button>
      {isOpen && createPortal(<div className="command-palette-backdrop" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && setIsOpen(false)}>
        <section ref={dialogRef} className="command-palette" role="dialog" aria-modal="true" aria-label={t('search.dialog')}>
          <header><Search size={20}/><input ref={inputRef} value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={handleKeyboardNavigation} placeholder={t('search.placeholder')} aria-label={t('search.title')} aria-activedescendant={activeIndex >= 0 ? `aims-option-${activeIndex}` : undefined}/><button type="button" onClick={() => setIsOpen(false)} aria-label={t('search.close')}><X size={19}/></button></header>
          <div className="command-palette-body">
            {visibleCommands.length > 0 && <section><h4><Command size={14}/> {t('search.commands')}</h4>{visibleCommands.map((command) => { const index = options.findIndex((option) => option.key === `command-${command.id}`); return <button id={`aims-option-${index}`} ref={(element) => { optionRefs.current[index] = element }} className={activeIndex === index ? 'is-keyboard-active' : ''} key={command.id} type="button" onClick={() => select(command.path)}><span>{command.label}</span><small>{command.path}</small></button> })}</section>}
            {sections.map(([name, items]) => <section key={name}><h4>{(language === 'sq' ? labelsSq : labels)[name] || labels[name] || name.replaceAll('_', ' ')}</h4>{items.map((item) => { const index = options.findIndex((option) => option.key === `${name}-${item.id}`); return <button id={`aims-option-${index}`} ref={(element) => { optionRefs.current[index] = element }} className={activeIndex === index ? 'is-keyboard-active' : ''} key={`${name}-${item.id}`} type="button" onClick={() => select(item.url)}><span>{item.title}</span>{item.subtitle && <small>{item.subtitle}</small>}</button> })}</section>)}
            {loading && <p className="command-palette-state" role="status">{t('search.loading')}</p>}
            {error && <p className="command-palette-state is-error">{error}</p>}
            {!loading && !error && query.trim().length >= 2 && sections.length === 0 && <p className="command-palette-state">{t('search.empty')}</p>}
            {!loading && query.trim().length < 2 && <p className="command-palette-hint">{t('search.hint')}</p>}
          </div>
        </section>
      </div>, document.body)}
    </>
  )
}
