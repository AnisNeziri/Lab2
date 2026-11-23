import { AskAimsGlyph as Sparkles } from './icons/AimsGlyph'

import { useEffect, useRef, useState } from 'react'

import { createPortal } from 'react-dom'

import { Link, useLocation } from 'react-router-dom'
import { readPurchasingDraft } from '../utils/purchasingDraft'

import { Send, Trash2, X } from 'lucide-react'

import { useTranslation } from '../hooks/useTranslation'

import { useAuthStore } from '../store/authStore'

import { canOpenPage } from '../config/pageAccess'

import { askIntelligence, assistantUsage } from '../api/assistant'

import { assistantHelpRequest, assistantIntent, assistantRequestError, guideAnswer, safeRecordPath } from './assistantPresentation'

import IntelligenceReply from './IntelligenceReply'

import AssistantAttachments from './AssistantAttachments'

import { navigationContext } from '../config/navigation'

import './AimsAssistant.css'



export default function AimsAssistant({open=true,onClose,request,embedded=false}) {

  const {language,t:translate}=useTranslation(),sq=language==='sq',t=(en,al)=>sq?al:en,location=useLocation()

  const {user,permissions}=useAuthStore(),identity=`${user?.company_id}:${user?.id}:${permissions.slice().sort().join('|')}`

  const [messages,setMessages]=useState([]),[question,setQuestion]=useState(''),[busy,setBusy]=useState(false),[usage,setUsage]=useState(null),[context,setContext]=useState([])

  const [attachmentEpoch,setAttachmentEpoch]=useState(0)
  const [cooldown,setCooldown]=useState(0)

  const abortRequest=useRef(null)
  const activeQuestion=useRef('')

  const conversation=useRef(null),pending=useRef(false),sequence=useRef(0),mounted=useRef(false),input=useRef(null),transcript=useRef(null)

  const closeGate=useRef({busy,question});closeGate.current={busy,question}

  useEffect(()=>{if(!open||embedded)return;const panel=input.current?.closest('.aims-assistant');const previous=document.activeElement;const outside=e=>{if(!closeGate.current.busy&&!closeGate.current.question.trim()&&panel&&!panel.contains(e.target)&&!e.target.closest('[data-assistant-trigger]'))onClose?.()};document.addEventListener('pointerdown',outside);return()=>{document.removeEventListener('pointerdown',outside);if(previous?.isConnected)previous.focus({preventScroll:true})}},[open,embedded,onClose])

  useEffect(()=>{if(!question&&input.current)input.current.style.height=''},[question])

  const append=m=>setMessages(p=>[...p,{...m,key:crypto.randomUUID()}].slice(-40))

  const clear=(restoreFocus=true)=>{abortRequest.current?.abort();sequence.current++;pending.current=false;conversation.current=null;setBusy(false);setMessages([]);setContext([]);setQuestion('');setAttachmentEpoch(n=>n+1);if(restoreFocus)input.current?.focus({preventScroll:true})}

  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;abortRequest.current?.abort();sequence.current++}},[])

  useEffect(()=>{clear(false);setUsage(null);setCooldown(0)},[identity])

  useEffect(()=>{
    if(!cooldown)return
    const timer=setTimeout(()=>setCooldown(0),Math.max(0,cooldown-Date.now()))
    return()=>clearTimeout(timer)
  },[cooldown])

  useEffect(()=>{if(request?.question)send(request.question,request.entity)},[request])

  useEffect(()=>{if(open&&!embedded)input.current?.focus({preventScroll:true})},[open,embedded])

  useEffect(()=>{

    const log=transcript.current,last=log?.lastElementChild

    // Start at the new answer, not at a long answer's feedback/footer.

    if(log&&last)log.scrollTop+=last.getBoundingClientRect().top-log.getBoundingClientRect().top-16

  },[messages,busy])

  useEffect(()=>{if(!open||embedded)return;const escape=e=>{if(e.key==='Escape'&&!document.querySelector('[aria-modal="true"]'))onClose?.()};window.addEventListener('keydown',escape);return()=>window.removeEventListener('keydown',escape)},[open,embedded,onClose])

  async function send(value=question,selected=null){

    if(pending.current||cooldown>Date.now()||!value.trim())return

    pending.current=true;activeQuestion.current=value;setBusy(true);setQuestion('');append({kind:'user',text:value});const ticket=++sequence.current

    try{

      if(!selected&&assistantHelpRequest(value)){const intent=assistantIntent(value);append({kind:'guide',data:guideAnswer(intent.page||location.pathname+location.search,language)});return}

      const simulationId=location.pathname==='/strategic-simulation'?new URLSearchParams(location.search).get('run'):null

      const entity=selected ? {type:selected.type,id:Number(selected.id)} : null
      const form=!selected && location.pathname==='/purchasing-decisions' ? readPurchasingDraft(user)?.form : null
      const purchaseContext=form?.product_id && Number(form.quantity)>0
        ? Object.fromEntries(['product_id','warehouse_id','quantity','unit','required_by','supplier_id'].filter(key=>form[key]).map(key=>[key,form[key]]))
        : null

      abortRequest.current=new AbortController()

      const reply=await askIntelligence({question:value,language,conversation_id:conversation.current,entity,
        ...(purchaseContext?{purchase_context:purchaseContext}:{}),...(simulationId?{simulation_id:Number(simulationId)}:{})},abortRequest.current.signal)

      if(mounted.current&&ticket===sequence.current){conversation.current=reply.conversation_id;setContext(reply.context_entities||[reply.context].filter(Boolean));append({kind:'reply',reply,original:value})}

    }catch(error){
      if(error.name!=='AbortError'&&mounted.current&&ticket===sequence.current){
        if(error.status===429&&error.retryAfterSeconds)setCooldown(Date.now()+error.retryAfterSeconds*1000)
        append({kind:'error',text:assistantRequestError(error,language),original:value,selected,retryable:![401,403].includes(error.status)})
      }
    }

    finally{if(mounted.current&&ticket===sequence.current){pending.current=false;setBusy(false)}}

  }

  const stopWaiting=()=>{
    abortRequest.current?.abort();sequence.current++;pending.current=false;setBusy(false)
    setQuestion(current=>current||activeQuestion.current)
    input.current?.focus()
    append({kind:'notice',text:t('You stopped waiting. Your question is ready to edit or send again.','E ndale pritjen. Mund ta ndryshosh pyetjen ose ta dërgosh përsëri.')})
  }
  const sendingDisabled=busy||Boolean(cooldown)

  const suggestions=[['What needs my attention?','Çfarë kërkon vëmendjen time?']]
  if(location.pathname === '/customer-debts' && canOpenPage('/customer-debts',permissions)) suggestions.unshift(['Who owes us the most?','Kush na ka më shumë borxh?'])
  else if(location.pathname.startsWith('/shipments') && canOpenPage('/shipments/my-shipments',permissions)) suggestions.unshift(['Where is my incoming stock?','Ku është stoku që presim?'])
  else if(canOpenPage('/stock',permissions)) suggestions.push(['Which products may run out?','Cilat produkte mund të mbarojnë?'])

  if(!embedded) suggestions.push(['How do I use this page?','Si përdoret kjo faqe?'])

  if(!open)return null

  const content=<aside className={`aims-assistant ${embedded?'is-embedded':''}`} aria-label={t('AIMS Assistant','Asistenti AIMS')}>

    <div className="assistant-heading"><Sparkles size={21} strokeWidth={1.8}/><div><strong>{embedded?t('Conversation','Biseda'):t('Ask AIMS','Pyet AIMS')}</strong></div>{!embedded&&<Link className="assistant-full-chat" to="/intelligence-assistant" onClick={onClose}>{t('Open full chat','Hap bisedën e plotë')}</Link>}{messages.length>0&&<button type="button" onClick={clear} aria-label={t('Clear conversation','Pastro bisedën')}><Trash2 size={17}/></button>}{!embedded&&<button type="button" onClick={onClose} aria-label={t('Close assistant','Mbyll asistentin')}><X size={20}/></button>}</div>

    {context.length>0&&<div className="assistant-context"><span>{t('Current context','Konteksti aktual')}: {context.map(e=>e.title||t('Selected record','Regjistrimi i zgjedhur')).join(' · ')}</span><button disabled={busy} type="button" onClick={()=>{conversation.current=null;setContext([]);input.current?.focus()}} aria-label={t('Clear context','Pastro kontekstin')}><X size={14}/>{t('Clear','Pastro')}</button></div>}

    <div ref={transcript} className="assistant-transcript" role="log" aria-live="polite" aria-label={t('Assistant conversation','Biseda me asistentin')}>

      {!messages.length&&<div className="assistant-welcome">
        <h3>{t('How can I help?','Si mund të të ndihmoj?')}</h3>
        <p>{t('Ask about your company in English or Albanian.','Pyet për kompaninë tënde në shqip ose anglisht.')}</p>
        <div className="assistant-suggestions">{suggestions.map(([en,al])=><button key={en} disabled={sendingDisabled} onClick={()=>send(t(en,al))}>{t(en,al)}</button>)}</div>
      </div>}

      {messages.map(m=><article key={m.key} className={`assistant-message ${m.kind==='user'?'is-user':m.kind==='error'?'is-error':''}`}>

        {m.kind==='reply'?<IntelligenceReply reply={m.reply} language={language} busy={sendingDisabled} onSelect={(e,q)=>send(q||m.original,e)}/>:m.kind==='guide'?<><p>{m.data.text}</p><ol>{m.data.steps.map(step=><li key={step}>{step}</li>)}</ol><div className="assistant-links">{m.data.links.filter(p=>safeRecordPath(p)&&canOpenPage(p,permissions)).map(p=>{const entry=navigationContext(p)?.entry;return <Link key={p} to={p} onClick={!embedded?onClose:undefined}>{entry?.label?translate(entry.label):entry?.[sq?'sq':'en']||t('Open module','Hap modulin')} ↗</Link>})}</div></>:<><p role={m.kind==='error'?'alert':undefined}>{m.text}</p>{m.kind==='error'&&m.retryable&&<button className="assistant-retry" disabled={sendingDisabled} onClick={()=>send(m.original,m.selected)}>{t('Retry question','Provo pyetjen përsëri')}</button>}</>}

      </article>)}{busy&&<div className="assistant-working" role="status"><span className="assistant-processing-mark" aria-hidden="true"/><strong>{t('Checking your question…','Duke shqyrtuar pyetjen…')}</strong><button type="button" onClick={stopWaiting}>{t('Stop waiting','Ndalo pritjen')}</button></div>}

    </div>

    <form className="assistant-composer" onSubmit={e=>{e.preventDefault();send()}}><label className="sr-only" htmlFor={embedded?'aims-copilot-question':'aims-assistant-question'}>{t('Question for AIMS','Pyetje për AIMS')}</label><textarea rows={1} id={embedded?'aims-copilot-question':'aims-assistant-question'} ref={input} value={question} onChange={e=>{setQuestion(e.target.value);e.target.style.height='auto';e.target.style.height=Math.min(120,e.target.scrollHeight)+'px'}} onKeyDown={e=>{if(e.key==='Enter'&&!e.shiftKey&&!e.nativeEvent.isComposing){e.preventDefault();if(!sendingDisabled)send()}}} maxLength={600} placeholder={t('Ask about your business…','Pyet për biznesin tënd…')}/><button type="submit" disabled={sendingDisabled||!question.trim()} aria-label={t('Send question','Dërgo pyetjen')}><Send size={18}/></button></form>

    {embedded&&<details className="assistant-tools">
      <summary>{t('More tools','Mjete të tjera')}</summary>
      <p>{t('AIMS uses records you can access. Review and confirm a draft before saving it.','AIMS përdor regjistrimet ku ke qasje. Shqyrto dhe konfirmo draftin para ruajtjes.')}</p>
      <AssistantAttachments key={`${identity}:${attachmentEpoch}`} language={language} page={location.pathname} question={question}/>
      {permissions.includes('activity.view')&&<details className="assistant-usage"><summary onClick={()=>{if(!usage)assistantUsage().then(setUsage).catch(()=>{})}}>{t('Assistant usage · last 30 days','Përdorimi i asistentit · 30 ditët e fundit')}</summary>{usage&&<p>{t('Queries','Pyetjet')}: {usage.queries} · {t('Follow-ups','Vazhdimet')}: {usage.follow_ups} · {t('Tool failures','Gabimet e mjeteve')}: {usage.tool_failures} · {t('Helpful feedback','Vlerësime të dobishme')}: {usage.feedback.helpful}</p>}</details>}
    </details>}

  </aside>

  return embedded?content:createPortal(content,document.body)

}
