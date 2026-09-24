import { useEffect,useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuthStore } from '../store/authStore'
import { useSettingsStore } from '../store/settingsStore'
import * as api from '../api/documents'
export default function EntityDocuments({entityType,entityId,compact=false}) {
  const permissions=useAuthStore(s=>s.permissions),sq=useSettingsStore(s=>s.language)==='sq'
  const [items,setItems]=useState([]),[readiness,setReadiness]=useState(null),[expanded,setExpanded]=useState(!compact),[error,setError]=useState('')
  const allowed=permissions.includes('documents.view')
  useEffect(()=>{let active=true;setItems([]);setReadiness(null);setError('');if(allowed&&entityId&&expanded)Promise.all([api.documents({entity_type:entityType,entity_id:entityId}),api.requirements(entityType,entityId)]).then(([d,r])=>{if(active){setItems(d.data);setReadiness(r)}}).catch(e=>{if(active){setItems([]);setReadiness(null);setError(e.message)}});return()=>{active=false}},[allowed,entityType,entityId,expanded])
  if(!allowed||!entityId)return null
  return <section className="entity-context"><header><h3>{sq?'Dokumentet e lidhura':'Related documents'}</h3><Link to={`/documents?entity_type=${entityType}&entity_id=${entityId}`}>{sq?'Hap dokumentet / shto':'Open documents / add'}</Link></header>{error&&<p role="alert">{error}</p>}{compact&&<button type="button" onClick={()=>setExpanded(!expanded)}>{sq?(expanded?'Mbyll dokumentet':'Shfaq dokumentet'):(expanded?'Hide documents':'Show documents')}</button>}{expanded&&items.map(d=><p key={d.id}><Link to={`/documents?document=${d.id}`}>{d.title}</Link> · v{d.current_version}</p>)}{expanded&&readiness?.requirements?.map((r,i)=><p key={i}>{r.type_labels?.[sq?'sq':'en']||r.type}: {(sq?{missing:'Mungon',expired:'Skaduar',review_required:'Kërkon shqyrtim',received:'Pranuar'}:{missing:'Missing',expired:'Expired',review_required:'Review required',received:'Received'})[r.status]||r.status}</p>)}</section>
}
