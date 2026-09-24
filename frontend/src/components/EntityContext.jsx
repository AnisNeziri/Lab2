import { useEffect, useState } from 'react'
import { Activity, ArrowUpRight } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { getEntityContext } from '../api/entityContext'
import EntityDocuments from './EntityDocuments'
import {useSettingsStore} from '../store/settingsStore'

export default function EntityContext({ entityType, entityId, title = 'Recent activity' }) {
  const navigate = useNavigate()
  const sq=useSettingsStore(s=>s.language)==='sq'
  const [outbound,setOutbound]=useState(null)
  const [activity, setActivity] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let active = true
    setLoading(true);setOutbound(null);setActivity([])
    getEntityContext(entityType, entityId)
      .then((data) => { if (active) {setActivity(data?.activity || []);setOutbound(data?.outbound||null)} })
      .catch(() => { if (active) setActivity([]) })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [entityType, entityId])

  return <>{entityType!=='warehouse'&&<EntityDocuments entityType={entityType} entityId={entityId}/>}<section className="entity-context" aria-label={title}>
    {outbound&&<div className="entity-outbound-context"><strong>{sq?'Përmbushja e porosive':'Order fulfillment'}</strong><div className="entity-outbound-summary">{[['open_demand',sq?'Kërkesë e hapur':'Open demand'],['open_orders',sq?'Porosi të hapura':'Open orders'],['open_tasks',sq?'Detyra të hapura':'Open pick tasks'],['open_returns',sq?'Kthime në pritje':'Returns awaiting action'],['allocated',sq?'Caktuar':'Allocated'],['reserved',sq?'Rezervuar':'Reserved'],['picked',sq?'Mbledhur':'Picked'],['returned',sq?'Kthyer':'Returned']].filter(([key])=>outbound.summary[key]!=null).map(([key,label])=><span key={key}>{label}: <strong>{outbound.summary[key]}</strong></span>)}</div><button type="button" onClick={()=>navigate(outbound.url)}>{sq?'Hap porositë dhe mbledhjen':'Open orders and picking'}</button></div>}
    <header><div><Activity size={18}/><h3>{title}</h3></div><small>{sq?'Aktiviteti i kompanisë dhe regjistrat e lidhur':'Company activity and linked records'}</small></header>
    {loading ? <p className="entity-context-empty">{sq?'Duke ngarkuar aktivitetin…':'Loading activity…'}</p> : activity.length === 0 ? <p className="entity-context-empty">{sq?'Ende nuk ka aktivitet të lidhur.':'No related activity has been recorded yet.'}</p> : <ol>{activity.map((item) => <li key={item.key}><span className="entity-context-dot"/><div><strong>{item.title}</strong><small>{item.detail}</small><time>{item.occurred_at ? new Date(item.occurred_at).toLocaleString() : '—'}</time></div>{item.url ? <button type="button" className="entity-context-link" aria-label={`Open ${item.title}`} onClick={() => navigate(item.url)}><ArrowUpRight size={16}/></button> : null}</li>)}</ol>}
  </section></>
}
