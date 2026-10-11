import { useEffect, useMemo, useRef, useState } from 'react'
import { RefreshCw, SlidersHorizontal, Plus, RotateCcw } from 'lucide-react'
import { useAuthStore } from '../store/authStore'
import { useWorkspaceStore } from '../store/workspaceStore'
import { useTranslation } from '../hooks/useTranslation'
import { availableWidgets, defaultDashboard, normalizeDashboard, moveWidget, widgetById, widgetCategories } from '../config/dashboardWidgets'
import { createDashboardResources, DashboardDataContext } from '../components/dashboard/DashboardData'
import WidgetFrame from '../components/dashboard/WidgetFrame'
import WorkspaceModal from '../components/WorkspaceModal'
import RefreshIndicator from '../components/dashboard/RefreshIndicator'
import WorkProfilePicker from '../components/WorkProfilePicker'
import { profileDashboard } from '../config/workProfiles'
import { useUnsavedNavigation } from '../hooks/useUnsavedNavigation'
import '../styles/Personalization.css'

export default function Dashboard() {
  const {language,t:translate}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const user=useAuthStore(s=>s.user),permissions=useAuthStore(s=>s.permissions),role=useAuthStore(s=>s.role)
  const {document:preferences,loading,saving,error:loadError,save,load}=useWorkspaceStore()
  const [draft,setDraft]=useState(null),[library,setLibrary]=useState(false),[query,setQuery]=useState(''),[category,setCategory]=useState(''),[dragged,setDragged]=useState(null),[error,setError]=useState(''),[notice,setNotice]=useState(''),[reset,setReset]=useState(false)
  const permissionKey=permissions.join('|'),identity=[user?.company_id,user?.id,permissionKey].join(':')
  const resources=useMemo(()=>createDashboardResources(),[identity]),refreshTimer=useRef(null)
  const defaultLayout=useMemo(()=>defaultDashboard(permissions,role),[permissionKey,role])
  const saved=normalizeDashboard(preferences?.dashboard,permissions)||defaultLayout
  const layout=normalizeDashboard(draft,permissions)||saved,editing=Boolean(draft)
  const dirty=editing&&JSON.stringify(layout)!==JSON.stringify(saved),[discard,setDiscard]=useState(false)
  const cancel=()=>{if(saving)return;if(dirty){setDiscard(true);return}setDraft(null);setLibrary(false);setError('')}
  useUnsavedNavigation(dirty,t('Discard dashboard changes? Your current arrangement has not been saved.','Të hidhen poshtë ndryshimet e panelit? Renditja aktuale nuk është ruajtur.'))
  useEffect(()=>{if(!editing)return;const key=e=>{if(e.key==='Escape'&&!document.querySelector('[role=dialog]')){e.preventDefault();cancel()}};window.addEventListener('keydown',key);return()=>window.removeEventListener('keydown',key)},[editing,dirty,saving])
  const catalog=availableWidgets(permissions)
  useEffect(()=>{setDraft(null);setError('');setNotice('')},[user?.id,user?.company_id])
  useEffect(()=>{
    let alive=true
    const refresh=()=>{if(refreshTimer.current||document.hidden)return;refreshTimer.current=setTimeout(()=>{refreshTimer.current=null;if(alive)resources.refresh()},250)}
    for(const event of ['database-refresh','dashboard-refresh','stock-refresh'])window.addEventListener(event,refresh)
    const visibility=()=>{if(!document.hidden)refresh()};document.addEventListener('visibilitychange',visibility)
    return()=>{alive=false;clearTimeout(refreshTimer.current);refreshTimer.current=null;resources.dispose();for(const event of ['database-refresh','dashboard-refresh','stock-refresh'])window.removeEventListener(event,refresh);document.removeEventListener('visibilitychange',visibility)}
  },[resources])
  const edit=next=>{if(saving)return;setDraft(normalizeDashboard(next||layout,permissions));setNotice('')}
  const remove=id=>edit({...layout,widgets:layout.widgets.filter(w=>w.id!==id)})
  const change=widget=>edit({...layout,widgets:layout.widgets.map(w=>w.id===widget.id?widget:w)})
  const commit=async()=>{
    if(saving||!dirty)return;setError('')
    try{if(await save('dashboard',normalizeDashboard(draft,permissions))){setDraft(null);setLibrary(false);setNotice(t('Dashboard saved.','Paneli u ruajt.'))}}
    catch(e){setError(e.message)}
  }
  const candidates=catalog.filter(w=>(!category||w.category===category)&&(w.en+' '+w.sq).toLocaleLowerCase().includes(query.toLocaleLowerCase()))
  return <DashboardDataContext.Provider value={resources}><main className="personal-dashboard">
    {editing&&<WorkProfilePicker language={language} disabled={saving} onChoose={profile=>edit(profileDashboard(profile,permissions))}/>}
    <header className="personal-dashboard-header"><div><h1>{translate('nav.dashboard')}</h1><p>{t('Welcome back,','Mirë se u ktheve,')} {user?.name}</p></div><div className="workspace-actions">
      <RefreshIndicator resources={resources} t={t} label={t('Auto Refresh','Përditësim automatik')}/>
      {!editing&&<><button type="button" onClick={()=>resources.refresh()}><RefreshCw size={16}/>{t('Refresh','Përditëso')}</button><button type="button" className="primary" disabled={loading||!preferences} onClick={()=>{edit();setLibrary(false);setError('')}}><SlidersHorizontal size={16}/>{t('Customize Dashboard','Personalizo panelin')}</button></>}
    </div></header>
    {(error||loadError)&&<div className="workspace-error" role="alert"><p>{error||loadError}</p><button type="button" disabled={saving} onClick={()=>{setDraft(null);setError('');load(user)}}>{t('Reload saved workspace','Ringarko hapësirën e ruajtur')}</button></div>}
    {notice&&<p className="workspace-notice" role="status">{notice}</p>}
    {editing&&<section className="workspace-edit-toolbar workspace-sticky-toolbar" aria-label={t('Dashboard editing','Redaktimi i panelit')}><div><strong>{t('Arrange your workspace','Organizo hapësirën tënde')}</strong><p role="status">{dirty?t('Unsaved changes','Ndryshime të paruajtura'):t('No changes yet','Ende pa ndryshime')}</p></div><div className="workspace-actions"><button type="button" disabled={saving} onClick={()=>setLibrary(v=>!v)}><Plus size={16}/>{t('Add Widget','Shto panel')}</button><button type="button" disabled={saving} onClick={()=>setReset(true)}><RotateCcw size={16}/>{t('Reset to Default','Rikthe standardin')}</button><button type="button" disabled={saving} onClick={cancel}>{t('Cancel','Anulo')}</button><button type="button" className="primary" disabled={saving||!preferences||!dirty} onClick={commit}>{saving?t('Saving…','Duke ruajtur…'):t('Save','Ruaj')}</button></div></section>}
    {editing&&library&&<WorkspaceModal title={t('Widget library','Biblioteka e paneleve')} onClose={()=>setLibrary(false)} closeOnBackdrop><section className="widget-library" aria-label={t('Widget library','Biblioteka e paneleve')}><div className="widget-library-filters"><label>{t('Search widgets','Kërko panele')}<input type="search" value={query} onChange={e=>setQuery(e.target.value)} placeholder={t('Sales, stock, orders…','Shitje, stok, porosi…')}/></label><label>{t('Category','Kategoria')}<select value={category} onChange={e=>setCategory(e.target.value)}><option value="">{t('All categories','Të gjitha kategoritë')}</option>{Object.entries(widgetCategories).filter(([key])=>catalog.some(w=>w.category===key)).map(([key,labels])=><option key={key} value={key}>{labels[language==='sq'?1:0]}</option>)}</select></label></div><div className="widget-library-items">{candidates.map(w=>{const added=layout.widgets.some(row=>row.id===w.id);return <button type="button" key={w.id} disabled={added||saving} onClick={()=>edit({...layout,widgets:[...layout.widgets,{id:w.id,size:w.defaultSize,settings:w.settings}]})}><span>{language==='sq'?w.sq:w.en}</span><small>{added?t('Added','I shtuar'):t('Add','Shto')}</small></button>})}</div>{!candidates.length&&<p>{t('No matching permitted widgets.','Nuk ka panele të lejuara që përputhen.')}</p>}</section></WorkspaceModal>}
    {layout.widgets.length>18&&<p className="workspace-notice">{t('You have many widgets. Removing rarely used items can make the dashboard faster.','Ke shumë panele. Heqja e atyre që përdoren rrallë e bën panelin më të shpejtë.')}</p>}
    {loading&&!preferences?<div className="widget-skeleton" role="status" aria-label={t('Loading workspace','Duke ngarkuar hapësirën')}><i/><i/><i/></div>:<div className={'workspace-widget-grid '+(editing?'is-editing':'')}>{layout.widgets.map((widget,index)=><WidgetFrame key={widget.id} widget={widget} definition={widgetById[widget.id]} editing={editing} busy={saving} index={index} count={layout.widgets.length} dragged={dragged} onDrag={setDragged} onDrop={id=>{const from=layout.widgets.findIndex(w=>w.id===dragged),to=layout.widgets.findIndex(w=>w.id===id);edit(moveWidget(layout,from,to));setDragged(null)}} onMove={(from,to)=>edit(moveWidget(layout,from,to))} onRemove={remove} onChange={change}/>)}</div>}
    {!loading&&!layout.widgets.length&&<p className="widget-empty">{t('Your dashboard has no widgets. Use Customize Dashboard to add one.','Paneli yt nuk ka elemente. Përdor Personalizo panelin për të shtuar një.')}</p>}
    {reset&&<WorkspaceModal title={t('Reset dashboard?','Rikthe panelin?')} onClose={()=>setReset(false)} busy={saving}><p>{t('Restore the default widgets for your current permissions. Save to apply, or Cancel to keep your saved layout.','Rikthe panelet standarde sipas lejeve të tua. Ruaj për të zbatuar, ose Anulo për të mbajtur renditjen e ruajtur.')}</p><div className="workspace-actions"><button type="button" onClick={()=>setReset(false)}>{t('Cancel','Anulo')}</button><button type="button" className="primary" onClick={()=>{edit(defaultLayout);setReset(false)}}>{t('Reset to Default','Rikthe standardin')}</button></div></WorkspaceModal>}
    {discard&&<WorkspaceModal title={t('Discard dashboard changes?','Të hidhen poshtë ndryshimet e panelit?')} onClose={()=>setDiscard(false)} closeOnBackdrop={false}><p>{t('Your current arrangement has not been saved.','Renditja aktuale nuk është ruajtur.')}</p><div className="workspace-actions"><button onClick={()=>setDiscard(false)}>{t('Keep Editing','Vazhdo redaktimin')}</button><button onClick={()=>{setDiscard(false);setDraft(null);setLibrary(false);setError('')}}>{t('Discard','Hidh poshtë')}</button></div></WorkspaceModal>}
  </main></DashboardDataContext.Provider>
}
