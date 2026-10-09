import { useState } from 'react'
import { Link } from 'react-router-dom'
import { GripVertical, ChevronUp, ChevronDown, X, MoreHorizontal } from 'lucide-react'
import { useTranslation } from '../../hooks/useTranslation'
import { useWidgetData } from './DashboardData'
import WidgetContent, { widgetSource } from './WidgetContent'

export default function WidgetFrame({widget,definition,editing,busy,index,count,onMove,onRemove,onChange,onDrag,onDrop,dragged}) {
  const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const [config,setConfig]=useState(false),[target,setTarget]=useState(false)
  const resource=useWidgetData(widgetSource(definition,widget.settings)),title=language==='sq'?definition.sq:definition.en
  return <section ref={resource.ref} className={`workspace-widget widget-size-${widget.size} ${editing?'is-editing':''} ${target?'is-drop-target':''} ${dragged===widget.id?'is-dragging':''}`} style={{'--widget-min-height':`${definition.minHeight}px`}} data-widget={widget.id} aria-label={title}
    onDragOver={e=>{if(editing&&dragged){e.preventDefault();e.dataTransfer.dropEffect='move';setTarget(true)}}}
    onDragLeave={e=>{if(!e.currentTarget.contains(e.relatedTarget))setTarget(false)}} onDrop={e=>{e.preventDefault();setTarget(false);if(editing&&!busy)onDrop(widget.id)}}>
    <header className="workspace-widget-header"><h2>{title}</h2>{editing?<div className="widget-edit-controls">
      <button type="button" className="widget-drag-handle" draggable={!busy} disabled={busy} aria-label={`${t('Drag to reorder','Tërhiq për të renditur')}: ${title}`} onDragStart={e=>{e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',widget.id);onDrag(widget.id)}} onDragEnd={()=>onDrag(null)}><GripVertical size={18}/></button>
      <button type="button" disabled={busy||index===0} onClick={()=>onMove(index,index-1)} aria-label={`${t('Move earlier','Zhvendos më lart')}: ${title}`}><ChevronUp size={16}/></button>
      <button type="button" disabled={busy||index===count-1} onClick={()=>onMove(index,index+1)} aria-label={`${t('Move later','Zhvendos më poshtë')}: ${title}`}><ChevronDown size={16}/></button>
      <button type="button" disabled={busy} onClick={()=>onRemove(widget.id)} aria-label={`${t('Remove widget','Hiq panelin')}: ${title}`}><X size={16}/></button>
    </div>:<details className="widget-menu"><summary aria-label={`${t('Widget options','Opsionet e panelit')}: ${title}`}><MoreHorizontal size={18}/></summary><div>
      <button type="button" onClick={e=>{e.currentTarget.closest('details').open=false;setConfig(v=>!v)}}>{t('Configure / resize','Konfiguro / ndrysho madhësinë')}</button>
      <button type="button" disabled={resource.loading} onClick={e=>{e.currentTarget.closest('details').open=false;resource.retry()}}>{t('Refresh','Përditëso')}</button><Link to={definition.path}>{t('View details','Shiko hollësitë')}</Link>
      <button type="button" onClick={()=>onRemove(widget.id)}>{t('Remove widget','Hiq panelin')}</button>
    </div></details>}</header>
    {(editing||config)&&<div className="widget-settings"><label>{t('Size','Madhësia')}<select disabled={busy} value={widget.size} onChange={e=>onChange({...widget,size:e.target.value})}>{definition.sizes.map(size=><option key={size} value={size}>{({small:t('Small','E vogël'),medium:t('Medium','Mesatare'),large:t('Large','E madhe')})[size]}</option>)}</select></label>
      {'period' in definition.settings&&<label>{t('Period','Periudha')}<select disabled={busy} value={widget.settings.period} onChange={e=>onChange({...widget,settings:{...widget.settings,period:e.target.value}})}><option value="week">{t('This calendar week','Kjo javë kalendarike')}</option><option value="month">{t('This calendar month','Ky muaj kalendarik')}</option><option value="year">{t('This calendar year','Ky vit kalendarik')}</option></select></label>}
      {'supplier_id' in definition.settings&&<SupplierSetting widget={widget} onChange={onChange} disabled={busy} t={t}/>}{!editing&&<button type="button" onClick={()=>setConfig(false)}>{t('Close','Mbyll')}</button>}
    </div>}
    <div className="workspace-widget-content" inert={editing}>{resource.error&&<div className="widget-error" role="status"><p>{t('Could not refresh this widget.','Ky panel nuk u përditësua.')}</p><small>{resource.error}</small><button type="button" onClick={resource.retry}>{t('Try again','Provo përsëri')}</button></div>}{!resource.data&&resource.loading?<div className="widget-skeleton" aria-label={t('Loading widget','Duke ngarkuar panelin')} role="status"><i/><i/><i/></div>:<WidgetContent definition={definition} data={resource.data} settings={widget.settings}/>}</div>
    {!editing&&<Link className="widget-details" to={definition.path}>{t('View details','Shiko hollësitë')} →</Link>}
  </section>
}
function SupplierSetting({widget,onChange,disabled,t}) {
  const data=useWidgetData('/suppliers'),list=Array.isArray(data.data)?data.data:(data.data?.data||[])
  return <label ref={data.ref}>{t('Supplier','Furnitori')}<select disabled={disabled||data.loading} value={widget.settings.supplier_id} onChange={e=>onChange({...widget,settings:{...widget.settings,supplier_id:e.target.value}})}><option value="">{t('Choose supplier','Zgjidh furnitorin')}</option>{widget.settings.supplier_id&&!list.some(s=>String(s.id)===String(widget.settings.supplier_id))&&<option value={widget.settings.supplier_id}>#{widget.settings.supplier_id}</option>}{list.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select>{data.error&&<button type="button" onClick={data.retry}>{t('Retry suppliers','Riprovo furnitorët')}</button>}</label>
}
