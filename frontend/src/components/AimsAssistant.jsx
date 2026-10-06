import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link, useLocation } from 'react-router-dom'
import { MessageCircle, Send, Trash2, X } from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'
import { canOpenPage } from '../config/pageAccess'
import { askIntelligence, assistantStatus, assistantUsage } from '../api/assistant'
import { assistantIntent, guideAnswer, safeRecordPath } from './assistantPresentation'
import IntelligenceReply from './IntelligenceReply'
import { navigationContext } from '../config/navigation'
import './AimsAssistant.css'

export default function AimsAssistant({open=true,onClose,guideRequest,request,embedded=false}) {
  const {language,t:translate}=useTranslation(),sq=language==='sq',t=(en,al)=>sq?al:en,location=useLocation()
  const {user,permissions}=useAuthStore(),identity=`${user?.company_id}:${user?.id}:${permissions.slice().sort().join('|')}`
  const [messages,setMessages]=useState([]),[question,setQuestion]=useState(''),[busy,setBusy]=useState(false),[status,setStatus]=useState(null),[usage,setUsage]=useState(null),[statusError,setStatusError]=useState(false)
  const conversation=useRef(null),pending=useRef(false),sequence=useRef(0),mounted=useRef(false),input=useRef(null),transcript=useRef(null)
  const append=m=>setMessages(p=>[...p,{...m,key:crypto.randomUUID()}].slice(-40))
  const clear=(restoreFocus=true)=>{sequence.current++;pending.current=false;conversation.current=null;setBusy(false);setMessages([]);setQuestion('');if(restoreFocus)input.current?.focus({preventScroll:true})}
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;sequence.current++}},[])
  useEffect(()=>{clear(false);setStatus(null)},[identity])
  useEffect(()=>{if(!open)return;let live=true;setStatusError(false);assistantStatus().then(r=>{if(live)setStatus(r)}).catch(()=>{if(live)setStatusError(true)});return()=>{live=false}},[open,identity])
  useEffect(()=>{if(guideRequest)append({kind:'guide',data:guideAnswer(location.pathname+location.search,language)})},[guideRequest])
  useEffect(()=>{if(request?.question)send(request.question,request.entity)},[request])
  useEffect(()=>{if(open&&!embedded)input.current?.focus({preventScroll:true})},[open,embedded])
  useEffect(()=>{if(transcript.current)transcript.current.scrollTop=transcript.current.scrollHeight},[messages,busy])
  useEffect(()=>{if(!open||embedded)return;const escape=e=>{if(e.key==='Escape'&&!document.querySelector('[aria-modal="true"]'))onClose?.()};window.addEventListener('keydown',escape);return()=>window.removeEventListener('keydown',escape)},[open,embedded,onClose])
  async function send(value=question,selected=null){
    if(pending.current||!value.trim())return
    pending.current=true;setBusy(true);setQuestion('');append({kind:'user',text:value});const ticket=++sequence.current
    try{
      if(/^(how (?:do|does|to|can)|si (?:funksion|perdor|përdor|mund))/i.test(value)){const intent=assistantIntent(value);append({kind:'guide',data:guideAnswer(intent.page||location.pathname+location.search,language)});return}
      const reply=await askIntelligence({question:value,language,conversation_id:conversation.current,entity:selected||null})
      if(mounted.current&&ticket===sequence.current){conversation.current=reply.conversation_id;append({kind:'reply',reply,original:value})}
    }catch(error){if(mounted.current&&ticket===sequence.current)append({kind:'error',text:error.message,original:value})}
    finally{if(mounted.current&&ticket===sequence.current){pending.current=false;setBusy(false)}}
  }
  const suggestions=permissions.includes('analytics.view')?[["Daily brief today","Përmbledhje ditore"],["What should I buy?","Çfarë duhet të blej?"],["What decisions are waiting?","Cilat vendime janë në pritje?"]]:[["stock","stoku"],["How do I use this page?","Si përdoret kjo faqe?"]]
  if(permissions.includes('analytics.view'))suggestions.unshift(['How do I use this page?','Si përdoret kjo faqe?'])
  if(permissions.includes('analytics.finance')&&permissions.includes('finance.view')&&permissions.includes('financial_accounts.view'))suggestions.push(['Cash forecast for 30 days','Parashikimi i parasë për 30 ditë'])
  if(!open)return null
  const content=<aside className={`aims-assistant ${embedded?'is-embedded':''}`} aria-label={t('AIMS Assistant','Asistenti AIMS')}>
    <div className="assistant-heading"><MessageCircle size={20}/><div><strong>{t('Intelligence Assistant','Asistenti inteligjent')}</strong><small>{t('Evidence first · human control','Dëshmitë së pari · kontroll njerëzor')}</small></div><button type="button" onClick={clear} aria-label={t('Clear conversation','Pastro bisedën')}><Trash2 size={17}/></button>{!embedded&&<button type="button" onClick={onClose} aria-label={t('Close assistant','Mbyll asistentin')}><X size={20}/></button>}</div>
    <details className="assistant-privacy"><summary>{t('Your company data · read-only assistance','Të dhënat e kompanisë · asistencë vetëm për lexim')}</summary><p>{t('No cloud AI or external data transmission. Answers use authorized evidence. Creating a business draft always requires your review.','Pa AI në cloud ose dërgim të jashtëm të dhënash. Përgjigjet përdorin dëshmi të autorizuara. Krijimi i draftit kërkon gjithmonë rishikimin tuaj.')}</p></details>
    <div className="assistant-page"><span>{statusError?t('Assistant connection unavailable. Try your question again.','Lidhja me asistentin nuk është e disponueshme. Provo pyetjen përsëri.'):status?t('Connected to AIMS evidence','I lidhur me të dhënat e AIMS'):t('Connecting to local AIMS…','Duke u lidhur me AIMS lokal…')}</span>{!embedded&&<Link to="/intelligence-assistant" onClick={onClose}>{t('Open workspace','Hap hapësirën')}</Link>}</div>
    <div ref={transcript} className="assistant-transcript" role="log" aria-live="polite" aria-label={t('Assistant conversation','Biseda me asistentin')}>
      {!messages.length&&<div className="assistant-welcome"><h3>{t('What needs your attention?','Çfarë kërkon vëmendjen tënde?')}</h3><p>{t('Ask for a morning brief, purchase priorities, cash forecast or a specific product. Use quoted names when several records could match.','Kërko përmbledhjen e mëngjesit, prioritetet e blerjes, parashikimin financiar ose një produkt konkret. Përdor emra në thonjëza për regjistrime të paqarta.')}</p></div>}
      {messages.map(m=><article key={m.key} className={`assistant-message ${m.kind==='user'?'is-user':m.kind==='error'?'is-error':''}`}>
        {m.kind==='reply'?<IntelligenceReply reply={m.reply} language={language} busy={busy} onSelect={(e,q)=>send(q||m.original,e)}/>:m.kind==='guide'?<><p>{m.data.text}</p><ol>{m.data.steps.map(step=><li key={step}>{step}</li>)}</ol><div className="assistant-links">{m.data.links.filter(p=>safeRecordPath(p)&&canOpenPage(p,permissions)).map(p=>{const entry=navigationContext(p)?.entry;return <Link key={p} to={p} onClick={!embedded?onClose:undefined}>{entry?.label?translate(entry.label):entry?.[sq?'sq':'en']||t('Open module','Hap modulin')} ↗</Link>})}</div></>:<><p role={m.kind==='error'?'alert':undefined}>{m.text}</p>{m.kind==='error'&&<button className="assistant-retry" disabled={busy} onClick={()=>send(m.original)}>{t('Retry question','Provo pyetjen përsëri')}</button>}</>}
      </article>)}{busy&&<p className="assistant-working" role="status">{t('Reading authorized evidence…','Duke lexuar dëshmitë e autorizuara…')}</p>}
    </div>
    <div className="assistant-suggestions">{suggestions.map(([en,al])=><button key={en} disabled={busy} onClick={()=>send(t(en,al))}>{t(en,al)}</button>)}</div>
    <form className="assistant-composer" onSubmit={e=>{e.preventDefault();send()}}><label className="sr-only" htmlFor={embedded?'aims-copilot-question':'aims-assistant-question'}>{t('Question for AIMS','Pyetje për AIMS')}</label><input id={embedded?'aims-copilot-question':'aims-assistant-question'} ref={input} value={question} onChange={e=>setQuestion(e.target.value)} maxLength={600} placeholder={t('Ask about your business…','Pyet për biznesin tënd…')}/><button type="submit" disabled={busy||!question.trim()} aria-label={t('Send question','Dërgo pyetjen')}><Send size={18}/></button></form>
    {embedded&&permissions.includes('activity.view')&&<details className="assistant-usage"><summary onClick={()=>{if(!usage)assistantUsage().then(setUsage).catch(()=>{})}}>{t('Assistant usage · last 30 days','Përdorimi i asistentit · 30 ditët e fundit')}</summary>{usage&&<p>{t('Queries','Pyetjet')}: {usage.queries} · {t('Follow-ups','Vazhdimet')}: {usage.follow_ups} · {t('Tool failures','Gabimet e mjeteve')}: {usage.tool_failures} · {t('Helpful feedback','Vlerësime të dobishme')}: {usage.feedback.helpful}</p>}</details>}
  </aside>
  return embedded?content:createPortal(content,document.body)
}
