import { useId, useMemo, useState } from 'react'
import { ChevronDown, X } from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'

// Checkbox selection works with keyboard, touch and mouse; no Ctrl-click knowledge needed.
export default function ScopePicker({ label, options, value, onChange, disabled = false }) {
  const { language } = useTranslation(), sq = language === 'sq', id = useId()
  const [query, setQuery] = useState('')
  const selected = useMemo(() => new Set(value.map(String)), [value])
  const rows = options.filter(row => row.name.toLocaleLowerCase().includes(query.toLocaleLowerCase()))
  return <details className="scope-picker">
    <summary aria-controls={id}><span><small>{label}</small><strong>{value.length ? `${value.length} ${sq ? 'të zgjedhura' : 'selected'}` : sq ? 'Të gjitha' : 'All'}</strong></span><ChevronDown size={16} aria-hidden="true"/></summary>
    <div id={id} className="scope-picker-content">
      {options.length > 6 && <label className="scope-picker-search"><span className="sr-only">{sq ? 'Kërko' : 'Search'}: {label}</span><input type="search" value={query} onChange={e => setQuery(e.target.value)} placeholder={sq ? 'Filtro listën…' : 'Filter list…'}/></label>}
      <div className="scope-picker-options">{rows.map(row => <label key={row.id}><input type="checkbox" disabled={disabled} checked={selected.has(String(row.id))} onChange={e => onChange(e.target.checked ? [...value, row.id] : value.filter(v => String(v) !== String(row.id)))}/><span>{row.name}</span></label>)}</div>
      {!rows.length && <p>{sq ? 'Nuk ka rezultate.' : 'No matching options.'}</p>}
      {value.length > 0 && <button type="button" className="secondary" disabled={disabled} onClick={() => onChange([])}><X size={14}/>{sq ? 'Hiq përzgjedhjen' : 'Clear selection'}</button>}
      <small>{sq ? 'Pa përzgjedhje përfshihen të gjitha.' : 'No selection includes all options.'}</small>
    </div>
  </details>
}
