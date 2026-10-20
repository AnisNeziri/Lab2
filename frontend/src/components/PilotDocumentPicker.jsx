import { useState } from 'react'
import { Link } from 'react-router-dom'
import { documents } from '../api/documents'
import { useTranslation } from '../hooks/useTranslation'
import { useUiText } from '../hooks/useUiText'

export default function PilotDocumentPicker({ value = [], onChange, multiple = false, disabled = false }) {
  const { language } = useTranslation(), tx=useUiText()
  const [q,setQ]=useState(''), [rows,setRows]=useState([]), [error,setError]=useState(''), [loading,setLoading]=useState(false)
  const search=async()=>{ setLoading(true);setError('');try{const data=await documents({q});setRows(data.data || [])}catch(cause){setError(cause.message)}finally{setLoading(false)} }
  return <fieldset><legend>{tx('Source documentation')}</legend>
    <label>{tx('Search Document Center')}<input value={q} maxLength={100} onChange={e=>setQ(e.target.value)} disabled={disabled}/></label>
    <button type="button" onClick={search} disabled={disabled||loading}>{tx('Find documents')}</button>
    <label>{tx('Select source')}<select disabled={disabled} value="" onChange={e=>{const id=Number(e.target.value);if(id)onChange(multiple?[...new Set([...value,id])]:[id])}}><option value="">{tx('Choose a document…')}</option>{rows.map(d=><option key={d.id} value={d.id}>{d.title} (#{d.id})</option>)}</select></label>
    {value.map(id=><span key={id}><Link to={`/documents?document=${id}`}>{tx('Document')} #{id}</Link> <button type="button" disabled={disabled} aria-label={`${tx('Remove document')} ${id}`} onClick={()=>onChange(value.filter(v=>v!==id))}>×</button></span>)}
    {error&&<p role="alert">{error}</p>}
  </fieldset>
}
