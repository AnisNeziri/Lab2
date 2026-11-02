import { useState } from 'react'
import { useAuthStore } from '../store/authStore'
import { useInventoryResource } from '../hooks/useInventoryResource'
import { apiRequest } from '../api/client'
import { businessDate } from '../utils/businessFormat'

export default function AutomationHealth({ t, language }) {
  const permissions=useAuthStore(s=>s.permissions)
  const resource=useInventoryResource('/automation-health')
  const [editing,setEditing]=useState(false),[policy,setPolicy]=useState(null),[error,setError]=useState(''),[busy,setBusy]=useState(false)
  const data=resource.data
  async function save(e){e.preventDefault();setBusy(true);setError('');try{await apiRequest('/automation-controls',{method:'POST',body:JSON.stringify(policy)});setEditing(false);await resource.reload()}catch(e){setError(e.message)}finally{setBusy(false)}}
  return <section className="auto-panel automation-health" aria-label={t('Automation health','Gjendja e automatizimit')}>
    <h2>{t('Automation health','Gjendja e automatizimit')}</h2>
    <p role="status">{resource.health==='disconnected'?t('Disconnected — last known health','Pa lidhje — gjendja e fundit'):data?.controls.paused?t('Company automation paused','Automatizimi i kompanisë në pauzë'):data?.state==='not_observed'?t('Worker activity has not been observed yet','Aktiviteti i punëtorit ende nuk është vëzhguar'):t('Current orchestration status','Gjendja aktuale e koordinimit')}</p>
    {data&&<><dl className="automation-health-facts">{[['active_rules','Active rules','Rregulla aktive'],['paused_rules','Paused rules','Rregulla në pauzë'],['draft_rules','Draft rules','Rregulla draft'],['retrying','Retrying','Në riprovim'],['unknown_outcomes','Unknown outcomes','Rezultate të panjohura'],['waiting_approvals','Waiting approvals','Miratime në pritje'],['tasks_created_30_days','Tasks created · 30 days','Detyra të krijuara · 30 ditë'],['tasks_resolved_30_days','Completed tasks · 30 days','Detyra të përfunduara · 30 ditë'],['mean_resolution_hours','Mean resolution hours','Orë mesatare zgjidhjeje']].map(([key,en,sq])=><div key={key}><dt>{t(en,sq)}</dt><dd>{data[key]??'—'}</dd></div>)}</dl>
      <p>{t('Manual ERP workflows remain available.','Veprimet manuale ERP mbeten të disponueshme.')}{data.heartbeat_at&&` · ${t('Last worker observation','Vëzhgimi i fundit')}: ${businessDate(data.heartbeat_at,language,{time:true})}`}</p>
      {data.controls.paused_categories.length>0&&<p>{t('Paused areas','Fusha në pauzë')}: {data.controls.paused_categories.join(', ')}</p>}
      <details><summary>{t('Recent failures & queue','Dështimet e fundit dhe radha')}</summary><p>{t('Company execution ledger','Regjistri i ekzekutimeve të kompanisë')}: {data.queue.pending} {t('pending','në pritje')} · {data.queue.processing} {t('processing','në punë')} · {data.queue.failed} {t('failed','të dështuara')}</p><small>{t('Raw worker job counts require tenant-aware telemetry. Job payloads are not exposed.','Numërimi i punëve të procesit kërkon telemetri sipas kompanisë. Përmbajtja e punëve nuk shfaqet.')}</small>{data.recent_failures.map(run=><p key={run.id}><a href={`/automation-studio?automation=${run.automation_id}`}>{t('Review rule','Rishiko rregullin')} #{run.automation_id}</a> · {run.error||run.status}{run.failure_class==='unknown_outcome'&&` · ${t('Verify linked results before retry','Verifikoni rezultatet para riprovimit')}`}</p>)}</details>
      {permissions.includes('automations.pause')&&<button type="button" onClick={()=>{setEditing(!editing);setPolicy({paused:data.controls.paused,paused_categories:data.controls.paused_categories,reason:''})}}>{t('Company pause controls','Kontrollet e pauzës së kompanisë')}</button>}
      {editing&&policy&&<form onSubmit={save}><label><span><input type="checkbox" checked={policy.paused} onChange={e=>setPolicy({...policy,paused:e.target.checked})}/> {t('Pause all company automation','Ndalo automatizimin e kompanisë')}</span></label><fieldset><legend>{t('Pause selected areas','Ndalo fusha të zgjedhura')}</legend>{['inventory','sales','purchasing','shipments','finance','tax','workforce','documents','operations'].map(category=><label key={category}><span><input type="checkbox" checked={policy.paused_categories.includes(category)} onChange={e=>setPolicy({...policy,paused_categories:e.target.checked?[...policy.paused_categories,category]:policy.paused_categories.filter(x=>x!==category)})}/> {category}</span></label>)}</fieldset><label>{t('Reason','Arsyeja')}<textarea required minLength={5} maxLength={1000} value={policy.reason} onChange={e=>setPolicy({...policy,reason:e.target.value})}/></label><button disabled={busy}>{t('Apply automation controls','Zbato kontrollet')}</button></form>}
    </>}{error&&<p role="alert">{error}</p>}
  </section>
}
