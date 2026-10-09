import { useEffect, useMemo, useRef, useState } from 'react'
import { Command, Search, X, Package, Users, Truck, Ship, Warehouse, FileText, ClipboardList, Landmark, Activity, ArrowUpRight, MessageCircle } from 'lucide-react'
import { customerSalesLabel } from '../pages/customerSalesPresentation'
import { globalSearch } from '../api/search'
import { useAuthStore } from '../store/authStore'
import { matchingCommands } from '../config/commandCatalog'
import { createPortal } from 'react-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useDialog } from '../hooks/useDialog'
import { navigationContext } from '../config/navigation'
import { openAimsAssistant } from './AskAimsButton'
import { useSettingsStore } from '../store/settingsStore'

const labels = {
  automations:'Automations', tasks:'Tasks', decisions:'Decisions',
  order_hub:'Orders',
  documents: 'Documents',
  sales_orders: 'Sales orders', pick_tasks: 'Pick tasks', pick_waves: 'Pick waves', dispatches: 'Dispatches / deliveries', returns: 'Customer returns',
  products: 'Products', stock_movements: 'Stock movements', suppliers: 'Suppliers',
  customers: 'Customers', invoices: 'Invoices', purchase_orders: 'Purchase orders',
  purchase_requests: 'Purchase requests', rfqs: 'RFQs', shipments: 'Shipments',
  containers: 'Containers', warehouses: 'Warehouses', bins: 'Bins', journals: 'Journals',
}
const labelsSq = { automations:'Automatizimet', tasks:'Detyrat', order_hub:'Porositë', documents: 'Dokumentet', sales_orders: 'Porositë e shitjeve', pick_tasks: 'Detyrat e mbledhjes', pick_waves: 'Valët e mbledhjes', dispatches: 'Dërgesat / dorëzimet', returns: 'Kthimet e klientëve', products: 'Produktet', stock_movements: 'Lëvizjet e stokut', suppliers: 'Furnitorët', customers: 'Klientët', invoices: 'Faturat', purchase_orders: 'Porositë e blerjes', purchase_requests: 'Kërkesat për blerje', rfqs: 'Kërkesat për oferta', shipments: 'Dërgesat', containers: 'Kontejnerët', warehouses: 'Depot', bins: 'Lokacionet', journals: 'Ditarët kontabël' }
const entityIcons = { decisions: Activity, products: Package, customers: Users, suppliers: Truck, shipments: Ship, containers: Ship, warehouses: Warehouse, bins: Warehouse, invoices: FileText, documents: FileText, journals: Landmark, stock_movements: Activity }
const commandIcon = path => {
  const group = navigationContext(path)?.group.id
  return ({ sales: Users, inventory: Package, purchasing: Truck, shipments: Ship, finance: Landmark, intelligence: Activity, operations: ClipboardList, settings: Activity })[group] || Command
}
labelsSq.decisions = 'Vendimet'

export default function GlobalSearch({ onNavigate }) {
  const { t, language } = useTranslation()
  const permissions = useAuthStore((state) => state.permissions)
  const role = useAuthStore(state => state.role)
  const enable3dMap = useSettingsStore(state => state.enable_3d_map)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState({})
  const [isOpen, setIsOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [activeIndex, setActiveIndex] = useState(-1)
  const [retry, setRetry] = useState(0)
  const inputRef = useRef(null)
  const dialogRef = useDialog(() => setIsOpen(false), false, isOpen)
  const optionRefs = useRef([])
  const requestRef = useRef(0)
  const commands = useMemo(() => matchingCommands(query, permissions, language, {role,enable3dMap}), [query, permissions, language,role,enable3dMap])
  const sections = useMemo(() => Object.entries(results || {}).filter(([, items]) => Array.isArray(items) && items.length), [results])
  const visibleCommands = useMemo(() => commands.slice(0, 8), [commands])
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

    }
    window.addEventListener('keydown', openPalette)
    return () => window.removeEventListener('keydown', openPalette)
  }, [])

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
  }, [query, isOpen, t, retry])

  useEffect(() => {
    setActiveIndex(-1)
    optionRefs.current = []
  }, [query, isOpen])

  useEffect(() => { setActiveIndex(index => index >= options.length ? -1 : index) }, [options.length])

  const select = (path) => {
    if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//')) return
    setIsOpen(false)
    setQuery('')
    onNavigate?.(path)
  }

  const ask = () => {setIsOpen(false);openAimsAssistant(null,query.trim()||'Daily brief today')}

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
      const option = options[activeIndex < 0 || activeIndex >= options.length ? 0 : activeIndex]
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
          <div className="command-palette-search"><Search size={20} aria-hidden="true"/><input role="combobox" aria-expanded="true" aria-autocomplete="list" aria-controls="aims-search-options" ref={inputRef} value={query} onChange={(event) => setQuery(event.target.value)} onKeyDown={handleKeyboardNavigation} placeholder={t('search.placeholder')} aria-label={t('search.title')} aria-activedescendant={activeIndex >= 0 ? `aims-option-${activeIndex}` : undefined}/>{query && <button type="button" onClick={() => { setQuery(''); inputRef.current?.focus() }} aria-label={language === 'sq' ? 'Pastro kërkimin' : 'Clear search'}><X size={16}/></button>}<button type="button" onClick={() => setIsOpen(false)} aria-label={t('search.close')}><X size={19}/></button></div>
          <div className="command-palette-body" id="aims-search-options" role="listbox" aria-label={language === 'sq' ? 'Rezultatet e kërkimit' : 'Search results'}>
            {visibleCommands.length > 0 && <section><h4><Command size={14} aria-hidden="true"/> {t('search.commands')}</h4>{visibleCommands.map((command) => { const index = options.findIndex((option) => option.key === `command-${command.id}`), Icon = commandIcon(command.path), context = navigationContext(command.path); return <button role="option" aria-selected={activeIndex === index} id={`aims-option-${index}`} ref={(element) => { optionRefs.current[index] = element }} className={activeIndex === index ? 'is-keyboard-active' : ''} key={command.id} type="button" onClick={() => select(command.path)}><Icon size={18} className="command-result-icon" aria-hidden="true"/><span className="command-result-copy"><span>{command.label}</span><small>{context ? context.group[language === 'sq' ? 'sq' : 'en'] : (language === 'sq' ? 'Veprim' : 'Action')}</small></span><ArrowUpRight size={14} className="command-result-arrow" aria-hidden="true"/></button> })}</section>}
            {sections.map(([name, items]) => { const Icon = entityIcons[name] || ClipboardList, type = (language === 'sq' ? labelsSq : labels)[name] || labels[name] || name.replaceAll('_', ' '); return <section key={name}><h4>{type}</h4>{items.map((item) => { const index = options.findIndex((option) => option.key === `${name}-${item.id}`); return <button role="option" aria-selected={activeIndex === index} id={`aims-option-${index}`} ref={(element) => { optionRefs.current[index] = element }} className={activeIndex === index ? 'is-keyboard-active' : ''} key={`${name}-${item.id}`} type="button" onClick={() => select(item.url)}><Icon size={18} className="command-result-icon" aria-hidden="true"/><span className="command-result-copy"><span>{item.title}</span>{item.subtitle && <small>{name==='customers'?item.subtitle.split(' · ').map(part=>customerSalesLabel(part,language)).join(' · '):item.subtitle}</small>}</span><span className="command-result-type">{type}</span></button> })}</section> })}
            {loading && <p className="command-palette-state" role="status">{t('search.loading')}</p>}
            {error && <div className="command-palette-state is-error" role="alert"><p>{error}</p><button type="button" className="secondary" onClick={()=>setRetry(v=>v+1)}>{language === 'sq' ? 'Provo përsëri' : 'Retry search'}</button></div>}
            {!loading && !error && query.trim().length >= 2 && sections.length === 0 && visibleCommands.length === 0 && <p className="command-palette-state">{t('search.empty')}</p>}
            {!loading && query.trim().length < 2 && <p className="command-palette-hint">{t('search.hint')}</p>}
          </div>
          <footer className="command-palette-footer"><span>{language === 'sq' ? '↑ ↓ Zgjidh · Enter Hap · Esc Mbyll' : '↑ ↓ Select · Enter Open · Esc Close'}</span><button type="button" className="workspace-assistant-trigger" onClick={ask}><MessageCircle size={15} aria-hidden="true"/>{language==='sq'?'Pyet AIMS':'Ask AIMS'}</button></footer>
        </section>
      </div>, document.body)}
    </>
  )
}
