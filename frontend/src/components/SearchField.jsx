import { Search, X } from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'

export default function SearchField({ value, onChange, placeholder, label, ...props }) {
  const { language } = useTranslation()
  return <label className="workspace-search-label"><span>{label || (language === 'sq' ? 'Kërko' : 'Search')}</span><span className="workspace-search-field"><Search size={17} aria-hidden="true"/><input {...props} type="search" value={value} onChange={onChange} placeholder={placeholder}/>{value && <button type="button" aria-label={language === 'sq' ? 'Pastro kërkimin' : 'Clear search'} onClick={() => onChange({ target: { value: '' } })}><X size={16}/></button>}</span></label>
}
