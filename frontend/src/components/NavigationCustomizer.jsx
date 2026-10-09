import { useMemo, useState } from 'react'
import { ChevronUp, ChevronDown, Star } from 'lucide-react'
import { useAuthStore } from '../store/authStore'
import { useSettingsStore } from '../store/settingsStore'
import { useWorkspaceStore } from '../store/workspaceStore'
import { useTranslation } from '../hooks/useTranslation'
import { permittedNavigation } from '../config/navigation'
import { defaultNavigation, normalizeNavigation, protectedNavigation } from '../config/workspaceNavigation'
import WorkspaceModal from './WorkspaceModal'
import '../styles/Personalization.css'

export default function NavigationCustomizer({onClose}) {
  const {t:translate,language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const user=useAuthStore(s=>s.user),role=useAuthStore(s=>s.role),permissions=useAuthStore(s=>s.permissions),enable3d=useSettingsStore(s=>s.enable_3d_map)
  const {document,saving,save,load,error:loadError}=useWorkspaceStore(),[draft,setDraft]=useState(()=>normalizeNavigation(document?.navigation||defaultNavigation(role))),[error,setError]=useState(''),[query,setQuery]=useState(''),[filter,setFilter]=useState('all'),[reset,setReset]=useState(false)
  const groups=useMemo(()=>permittedNavigation(permissions,enable3d,role),[permissions,enable3d,role])
  const title=i=>i.label?translate(i.label):language==='sq'?i.sq:i.en
  const commit=async()=>{setError('');try{if(await save('navigation',normalizeNavigation(draft)))onClose()}catch(e){setError(e.message)}}
  const toggleVisible=id=>setDraft(d=>({...d,hidden:d.hidden.includes(id)?d.hidden.filter(i=>i!==id):[...d.hidden,id]}))
  const pin=id=>setDraft(d=>d.favorites.includes(id)?{...d,favorites:d.favorites.filter(i=>i!==id)}:{...d,favorites:d.favorites.length<6?[...d.favorites,id]:d.favorites,hidden:d.hidden.filter(i=>i!==id)})
  const orderRows=g=>{const order=draft.order[g.id]||[];return g.items.toSorted((a,b)=>(order.includes(a.id)?order.indexOf(a.id):100+g.items.indexOf(a))-(order.includes(b.id)?order.indexOf(b.id):100+g.items.indexOf(b)))}
  const move=(g,from,to)=>{const ids=orderRows(g).map(i=>i.id),[id]=ids.splice(from,1);ids.splice(to,0,id);setDraft(d=>({...d,order:{...d.order,[g.id]:ids}}))}
  return <WorkspaceModal title={t('Customize Navigation','Personalizo navigimin')} onClose={onClose} busy={saving}><p>{t('Hide unused pages, reorder inside each category and pin up to six favorites. Hidden pages remain available through Search AIMS.','Fshih faqet që nuk përdor, renditi brenda kategorive dhe fikso deri në gjashtë të preferuara. Faqet e fshehura gjenden ende te Kërko në AIMS.')}</p>
    {(error||loadError)&&<div className="workspace-error" role="alert"><p>{error||loadError}</p><button type="button" disabled={saving} onClick={async()=>{await load(user);onClose()}}>{t('Reload saved workspace','Ringarko hapësirën e ruajtur')}</button></div>}
    <div className="widget-library-filters"><label>{t('Search pages','Kërko faqe')}<input type="search" value={query} onChange={e=>setQuery(e.target.value)}/></label><label>{t('Show','Shfaq')}<select value={filter} onChange={e=>setFilter(e.target.value)}><option value="all">{t('Available items','Elementet në dispozicion')}</option><option value="hidden">{t('Hidden items','Elementet e fshehura')}</option><option value="favorites">{t('Favorites','Të preferuarat')}</option></select></label></div>
    {groups.map(g=><section className="navigation-editor-group" key={g.id}><h3>{language==='sq'?g.sq:g.en}</h3>{orderRows(g).map((i,index)=>{const hidden=draft.hidden.includes(i.id),pinned=draft.favorites.includes(i.id),label=title(i);if((filter==='hidden'&&!hidden)||(filter==='favorites'&&!pinned)||!label.toLocaleLowerCase().includes(query.toLocaleLowerCase()))return null;return <div key={i.id} className={'navigation-editor-row '+(hidden?'is-hidden':'')} data-nav-item={i.id}><label><input type="checkbox" checked={!hidden} disabled={saving||protectedNavigation.includes(i.id)} onChange={()=>toggleVisible(i.id)}/><span>{label}{protectedNavigation.includes(i.id)&&<small> · {t('Always available','Gjithmonë në dispozicion')}</small>}</span></label><button type="button" className={pinned?'is-pinned':''} aria-label={(pinned?t('Unpin','Hiq nga të preferuarat'):t('Pin','Fikso'))+': '+label} aria-pressed={pinned} disabled={saving||(!pinned&&draft.favorites.length>=6)} onClick={()=>pin(i.id)}><Star size={16} fill={pinned?'currentColor':'none'}/></button><button type="button" aria-label={t('Move earlier','Zhvendos më lart')+': '+label} disabled={saving||index===0} onClick={()=>move(g,index,index-1)}><ChevronUp size={16}/></button><button type="button" aria-label={t('Move later','Zhvendos më poshtë')+': '+label} disabled={saving||index===g.items.length-1} onClick={()=>move(g,index,index+1)}><ChevronDown size={16}/></button></div>})}</section>)}
    {reset?<div className="workspace-edit-toolbar"><p>{t('Restore the default navigation for your role? Save to apply.','Rikthe navigimin standard sipas rolit? Ruaj për ta zbatuar.')}</p><button type="button" onClick={()=>{setDraft(defaultNavigation(role));setReset(false)}}>{t('Confirm reset','Konfirmo rikthimin')}</button><button type="button" onClick={()=>setReset(false)}>{t('Keep current','Mbaje aktualin')}</button></div>:null}
    <div className="workspace-actions"><button type="button" disabled={saving} onClick={()=>setReset(true)}>{t('Reset to Default','Rikthe standardin')}</button><button type="button" disabled={saving} onClick={onClose}>{t('Cancel','Anulo')}</button><button type="button" className="primary" disabled={saving||!document} onClick={commit}>{saving?t('Saving…','Duke ruajtur…'):t('Save','Ruaj')}</button></div>
  </WorkspaceModal>
}
