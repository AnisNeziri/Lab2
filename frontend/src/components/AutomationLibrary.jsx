import { useEffect, useMemo, useRef, useState } from 'react'
import { apiRequest } from '../api/client'
import { ArrowRight } from 'lucide-react'
import WorkspaceTabs from './WorkspaceTabs'
import SearchField from './SearchField'
import StatusBadge from './StatusBadge'
import { useSessionState } from '../hooks/useSessionState'

const areas = {
 inventory:['Inventory','Inventari'],sales:['Sales','Shitjet'],purchasing:['Purchasing','Blerjet'],shipments:['Shipments','Dërgesat'],finance:['Finance','Financat'],tax:['Tax & fiscalization','Tatimet dhe fiskalizimi'],workforce:['Payroll & HR','Pagat dhe personeli'],documents:['Documents','Dokumentet'],operations:['Operations','Operacionet'],
}
export default function AutomationLibrary({ revision = 0, catalog, canCreate, onRule, onTemplate, t, language }) {
 const [view,setView]=useSessionState('automations.library',{tab:'rules',search:'',area:'',page:1})
 const heading=useRef(null),tab=canCreate&&view.tab==='templates'?'templates':'rules',search=view.search,area=view.area,page=view.page
 const setTab=value=>setView(v=>({...v,tab:value})),setSearch=value=>setView(v=>({...v,search:value})),setArea=value=>setView(v=>({...v,area:value})),setPage=value=>setView(v=>({...v,page:value}))
 const [remote,setRemote]=useState({data:[],total:0,last_page:1,current_page:1}),[loading,setLoading]=useState(false),[error,setError]=useState('')
 useEffect(()=>{
  if(tab!=='rules'){setLoading(false);return}
  let active=true;setLoading(true);setError('')
  const timer=setTimeout(()=>{
   const query=new URLSearchParams({search:search.trim(),area,page:String(page),per_page:'6'})
   apiRequest(`/automations?${query}`).then(result=>{if(active){setRemote(result);if(page>result.last_page)setPage(result.last_page)}}).catch(cause=>active&&setError(cause.message)).finally(()=>active&&setLoading(false))
  },250)
  return()=>{active=false;clearTimeout(timer)}
 },[tab,search,area,page,revision])
 const rules=remote.data
 const areaKey=trigger=>catalog.triggers[trigger]?.category||'operations'
 const areaName=trigger=>t(...(areas[areaKey(trigger)]||['Other','Të tjera']))
 const entries=tab==='templates'?catalog.templates:rules
 const options=useMemo(()=>[...new Map(Object.keys(catalog.triggers).map(trigger=>({trigger})).map(row=>[areaKey(row.trigger),{key:areaKey(row.trigger),label:areaName(row.trigger)}])).values()].sort((a,b)=>a.label.localeCompare(b.label)),[catalog,language])
 const visible=entries.filter(row=>(!area||areaKey(row.trigger)===area)&&[row.name,row.name_sq,row.description,catalog.triggers[row.trigger]?.label,catalog.triggers[row.trigger]?.label_sq].some(value=>String(value||'').toLocaleLowerCase().includes(search.trim().toLocaleLowerCase())))
 const pageSize=6,pages=tab==='rules'?remote.last_page:Math.max(1,Math.ceil(visible.length/pageSize)),currentPage=Math.min(page,pages),pageRows=tab==='rules'?remote.data:visible.slice((currentPage-1)*pageSize,currentPage*pageSize),total=tab==='rules'?remote.total:visible.length
 const changePage=next=>{setPage(next);heading.current?.scrollIntoView({block:'start',behavior:'instant'})}
 return <section ref={heading} className="auto-library" aria-label={t('Automation library','Biblioteka e automatizimeve')}>
  <WorkspaceTabs label={t('Automation library views','Pamjet e bibliotekës së automatizimeve')} value={tab} onChange={value=>{setTab(value);setPage(1)}} items={[{id:'rules',label:`${t('Your automations','Automatizimet tuaja')} (${remote.total})`},...(canCreate?[{id:'templates',label:`${t('Templates','Modelet')} (${catalog.templates.length})`}]:[])]}>
   <div className="auto-library-heading"><div><h2>{tab==='templates'?t('Start with a template','Fillo me një model'):t('Your automations','Automatizimet tuaja')}</h2><p>{tab==='templates'?t('Choose a business need, then review the draft. Templates stay disabled until you enable them.','Zgjidh nevojën e biznesit, pastaj shqyrto draftin. Modelet mbeten joaktive derisa t’i aktivizosh.'):t('Review the rule, its status and its results before making changes.','Shqyrto rregullin, gjendjen dhe rezultatet para ndryshimeve.')}</p></div></div>
   <div className="auto-library-filters"><SearchField label={t('Search automations','Kërko automatizime')} aria-label={t('Search automations','Kërko automatizime')} placeholder={t('Name or business event…','Emri ose ngjarja e biznesit…')} value={search} onChange={e=>{setSearch(e.target.value);setPage(1)}}/><label>{t('Business area','Fusha e biznesit')}<select value={area} onChange={e=>{setArea(e.target.value);setPage(1)}}><option value="">{t('All areas','Të gjitha fushat')}</option>{options.map(value=><option key={value.key} value={value.key}>{value.label}</option>)}</select></label></div>
   <p className="auto-library-count" role="status">{loading&&tab==='rules'?'…':pageRows.length} {t('shown','të shfaqura')} · {total} {t('matching','që përputhen')}</p>
   {error&&tab==='rules'&&<p role="alert" className="page-error">{error}</p>}
   {loading&&tab==='rules'?<p role="status">{t('Searching all company rules…','Duke kërkuar të gjitha rregullat e kompanisë…')}</p>:error&&tab==='rules'?null:total?<div className="auto-library-grid">{pageRows.map((row,index)=><article className="auto-library-card" key={row.id||`${row.trigger}-${index}`}><div className="auto-library-meta"><span>{areaName(row.trigger)}</span>{tab==='rules'&&<StatusBadge value={row.enabled?'active':row.lifecycle_state||'draft'}/>}</div><h3>{tab==='templates'?t(row.name,row.name_sq):row.name}</h3><p>{t(catalog.triggers[row.trigger]?.label||row.trigger,catalog.triggers[row.trigger]?.label_sq||row.trigger)}</p><button type="button" className="secondary" onClick={()=>tab==='templates'?onTemplate(row):onRule(row)}>{tab==='templates'?t('Use template','Përdor modelin'):t('Open automation','Hap automatizimin')}<ArrowRight size={16} aria-hidden="true"/></button></article>)}</div>:<div className="workspace-empty"><strong>{search||area?t('No matching automations','Nuk ka automatizime që përputhen'):t('No automations yet','Ende nuk ka automatizime')}</strong><p>{search||area?t('Try another name or clear the filters.','Provo një emër tjetër ose pastro filtrat.'):t('Start with a template and review it before activation.','Fillo me një model dhe shqyrtoje para aktivizimit.')}</p>{search||area?<button type="button" onClick={()=>{setSearch('');setArea('')}}>{t('Clear filters','Pastro filtrat')}</button>:canCreate&&<button type="button" onClick={()=>setTab('templates')}>{t('Browse templates','Shfleto modelet')}</button>}</div>}
   {pages>1&&<nav className="auto-library-pagination" aria-label={t('Automation pages','Faqet e automatizimeve')}><button type="button" disabled={loading||currentPage<=1} onClick={()=>changePage(currentPage-1)}>{t('Previous','Para')}</button><span>{currentPage} / {pages}</span><button type="button" disabled={loading||currentPage>=pages} onClick={()=>changePage(currentPage+1)}>{t('Next','Tjetra')}</button></nav>}
  </WorkspaceTabs>
 </section>
}
