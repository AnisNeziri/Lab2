import { useEffect, useState } from 'react'
import { Activity, ArrowUpRight } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { getEntityContext } from '../api/entityContext'
import EntityDocuments from './EntityDocuments'
import {useSettingsStore} from '../store/settingsStore'
import {businessDate} from '../utils/businessFormat'
import {businessActivity} from '../utils/businessActivity'
import {formatQuantity} from '../utils/formatQuantity'

export default function EntityContext({ entityType, entityId, title: suppliedTitle }) {
  const navigate = useNavigate()
  const language=useSettingsStore(s=>s.language),sq=language==='sq',title=suppliedTitle||(sq?'Historia dhe lidhjet':'History and connections')
  const [outbound,setOutbound]=useState(null)
  const [unit,setUnit]=useState(null)
  const [tasks,setTasks]=useState([])
  const [activity, setActivity] = useState([])
  const [loading, setLoading] = useState(true)
  const [error,setError]=useState(''),[revision,setRevision]=useState(0)

  useEffect(() => {
    let active = true
    setLoading(true);setError('');setOutbound(null);setActivity([]);setTasks([])
    getEntityContext(entityType, entityId)
      .then((data) => { if (active) {setActivity(data?.activity || []);setOutbound(data?.outbound||null);setTasks(data?.tasks || []);setUnit(data?.entity?.unit)} })
      .catch(cause => { if (active) {setActivity([]);setError(cause.message)} })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [entityType, entityId,revision])

  return <>{entityType!=='warehouse'&&<EntityDocuments entityType={entityType} entityId={entityId}/>}<section className="entity-context" aria-label={title}>
    {outbound&&<div className="entity-outbound-context"><strong>{sq?'Përmbushja e porosive':'Order fulfillment'}</strong><div className="entity-outbound-summary">{[['open_demand',sq?'Kërkesë e hapur':'Open demand'],['open_orders',sq?'Porosi të hapura':'Open orders'],['open_tasks',sq?'Detyra të hapura':'Open pick tasks'],['open_returns',sq?'Kthime në pritje':'Returns awaiting action'],['allocated',sq?'Caktuar':'Allocated'],['reserved',sq?'Rezervuar':'Reserved'],['picked',sq?'Mbledhur':'Picked'],['returned',sq?'Kthyer':'Returned']].filter(([key])=>outbound.summary[key]!=null).map(([key,label])=><span key={key}>{label}: <strong>{entityType==='product'?`${formatQuantity(outbound.summary[key],unit,language)} ${unit||''}`:outbound.summary[key]}</strong></span>)}</div><button type="button" onClick={()=>navigate(outbound.url)}>{sq?'Hap porositë dhe mbledhjen':'Open orders and picking'}</button></div>}
    <header><div><Activity size={18}/><h3>{title}</h3></div><small>{sq?'Aktiviteti i kompanisë dhe regjistrat e lidhur':'Company activity and linked records'}</small></header>
    {tasks.length>0&&<details><summary>{sq?'Detyrat e lidhura':'Related tasks'} ({tasks.length})</summary>{tasks.map(task=><p key={task.id}><button type="button" onClick={()=>navigate('/action-center?status=all&task='+task.id)}>{task.title}</button></p>)}</details>}
    {error?<div role="alert"><p>{sq?'Historia nuk mund të ngarkohej. Provo përsëri.':'History could not be loaded. Please try again.'}</p><button type="button" onClick={()=>setRevision(v=>v+1)}>{sq?'Provo përsëri':'Try again'}</button></div>:loading ? <p className="entity-context-empty">{sq?'Duke ngarkuar aktivitetin…':'Loading activity…'}</p> : activity.length === 0 ? <p className="entity-context-empty">{sq?'Ende nuk ka aktivitet të lidhur.':'No related activity has been recorded yet.'}</p> : <ol>{activity.map((item) => <li key={item.key}><span className="entity-context-dot"/><div><strong>{businessActivity(item,language).title}</strong><small>{businessActivity(item,language).detail}</small>{businessActivity(item,language).technical&&<details><summary>{sq?'Detajet':'Details'}</summary><small>{businessActivity(item,language).technical}</small></details>}<time>{item.occurred_at ? businessDate(item.occurred_at,language,{time:true}) : '—'}</time></div>{item.url ? <button type="button" className="entity-context-link" aria-label={`${sq?'Hap':'Open'} ${businessActivity(item,language).title}`} onClick={() => navigate(item.url)}><ArrowUpRight size={16}/></button> : null}</li>)}</ol>}
  </section></>
}
