import { X } from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'

export default function ActiveFilters({ filters, onClear }) {
  const { language } = useTranslation()
  const visible = filters.filter(Boolean)
  if (!visible.length) return null
  return <div className="workspace-active-filters" aria-label={language === 'sq' ? 'Filtrat aktivë' : 'Active filters'}>
    {visible.map(filter => <button key={filter.key} type="button" onClick={filter.remove} aria-label={`${language === 'sq' ? 'Hiq filtrin' : 'Remove filter'}: ${filter.label}`}><span>{filter.label}</span><X size={13} aria-hidden="true"/></button>)}
    {onClear && <button className="filter-clear-all" type="button" onClick={onClear}>{language === 'sq' ? 'Pastro të gjitha' : 'Clear all'}</button>}
  </div>
}
