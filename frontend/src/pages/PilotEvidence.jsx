import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useUiText } from '../hooks/useUiText'
import { useAuthStore } from '../store/authStore'
import { pilot, writePilot } from '../api/pilot'
import PilotDocumentPicker from '../components/PilotDocumentPicker'
import { pilotLabel } from './pilotText'
import '../styles/pilot.css'

export default function PilotEvidence({ embedded=false }) {
  const {language}=useTranslation(),tx=useUiText(),label=key=>pilotLabel(key,language)
  const user=useAuthStore(s=>s.user),role=useAuthStore(s=>s.role),permissions=useAuthStore(s=>s.permissions)
  const [kinds,setKinds]=useState([]),[rows,setRows]=useState(null),[page,setPage]=useState(1),[error,setError]=useState(''),[busy,setBusy]=useState(false),[message,setMessage]=useState('')
  const [kind,setKind]=useState(''),[date,setDate]=useState(new Date().toLocaleDateString('en-CA')),[outcome,setOutcome]=useState('not_tested'),[docs,setDocs]=useState([]),[notes,setNotes]=useState(''),[checks,setChecks]=useState([{name:'',result:'not_tested'}])
  const load=useCallback(async()=>{try{const data=await pilot(`/evidence?page=${page}`);setRows(data)}catch(cause){setError(cause.message)}},[page])
  useEffect(()=>{let active=true;pilot('/context').then(data=>{if(active){setKinds(data.evidence_kinds);setKind(data.evidence_kinds[0]||'')}}).catch(cause=>{if(active)setError(cause.message)});return()=>{active=false}},[])
  useEffect(()=>{load()},[load])
  const submit=async event=>{event.preventDefault();setBusy(true);setError('');setMessage('');try{
    await writePilot('/evidence',{kind,observed_on:date,outcome,document_id:docs[0]||null,checks,notes:notes||null});setMessage(tx('Observation saved. A different authorized reviewer must confirm it.'));setChecks([{name:'',result:'not_tested'}]);setNotes('');setDocs([]);await load()
  }catch(cause){setError(cause.message)}finally{setBusy(false)}}
  const review=async id=>{setBusy(true);setError('');try{await writePilot(`/evidence/${id}/review`,{});await load()}catch(cause){setError(cause.message)}finally{setBusy(false)}}
  const content=<>
    <header><div><h1>{tx('Pilot trials & evidence')}</h1><p>{tx('Record actual work. Evidence records are append-only; corrections are new observations.')}</p></div>{!embedded&&role==='admin'&&<Link to="/pilot">{tx('Owner review')}</Link>}</header>
    {error&&<p className="page-error" role="alert">{error}</p>}{message&&<p role="status">{message}</p>}
    <section><form className="pilot-form" onSubmit={submit}>
      <label>{tx('Trial')}<select value={kind} onChange={e=>setKind(e.target.value)} required>{kinds.map(k=><option key={k} value={k}>{label(k)}</option>)}</select></label>
      <label>{tx('Observed on')}<input type="date" value={date} onChange={e=>setDate(e.target.value)} required/></label>
      <label>{tx('Overall outcome')}<select value={outcome} onChange={e=>setOutcome(e.target.value)}>{['not_tested','confirmed','failed','not_applicable'].map(k=><option key={k} value={k}>{label(k)}</option>)}</select></label>
      {permissions.includes('documents.view')&&<PilotDocumentPicker value={docs} onChange={setDocs} disabled={busy}/>}
      <p className="pilot-meta">{tx('Opening balances, source review, restore drill and business comparisons require a source document. Do not enter customer details, passwords, bank numbers or document contents in notes.')}</p>
      <fieldset><legend>{tx('Checks performed')}</legend>{checks.map((check,i)=><div className="pilot-check-row" key={i}>
        <label>{tx('Check')} {i+1}<input required maxLength={100} value={check.name} onChange={e=>setChecks(checks.map((c,j)=>j===i?{...c,name:e.target.value}:c))}/></label>
        <label>{tx('Result')}<select value={check.result} onChange={e=>setChecks(checks.map((c,j)=>j===i?{...c,result:e.target.value}:c))}>{['not_tested','confirmed','failed','not_applicable'].map(k=><option key={k} value={k}>{label(k)}</option>)}</select></label>
        <button type="button" disabled={checks.length===1||busy} onClick={()=>setChecks(checks.filter((_,j)=>j!==i))}>{tx('Remove')}</button>
      </div>)}<button type="button" disabled={checks.length>=30||busy} onClick={()=>setChecks([...checks,{name:'',result:'not_tested'}])}>{tx('Add check')}</button></fieldset>
      <label>{tx('Notes (optional)')}<textarea maxLength={2000} value={notes} onChange={e=>setNotes(e.target.value)}/></label>
      <button disabled={busy||!kind}>{tx('Save observation')}</button>
    </form></section>
    <section><h2>{tx('Recorded observations')}</h2><div className="table-wrap"><table><thead><tr><th>{tx('Trial / date')}</th><th>{tx('Outcome & checks')}</th><th>{tx('Review')}</th></tr></thead><tbody>{rows?.data.map(row=><tr key={row.id}><td>#{row.id} {label(row.kind)}<small>{row.observed_on.slice(0,10)}</small>{row.document_id&&<Link to={`/documents?document=${row.document_id}`}>{tx('Source document')}</Link>}</td><td>{label(row.outcome)}{row.checks.map((c,i)=><small key={i}>{c.name}: {label(c.result)}</small>)}{row.notes&&<small>{row.notes}</small>}</td><td>{row.reviewed_at?<span>{tx('Reviewed')} #{row.reviewed_by}</span>:<><span>{tx(row.is_historical ? 'Historical evidence; record a new local observation.' : 'Awaiting independent review')}</span>{!row.is_historical&&Number(row.prepared_by)!==Number(user?.id)&&(role==='admin'||(permissions.includes('accounting.reports.view')&&['opening_finance','customer_payment','supplier_payment','supplier_invoice'].includes(row.kind)))&&<button type="button" disabled={busy} onClick={()=>review(row.id)}>{tx('Confirm review')}</button>}</>}</td></tr>)}</tbody></table></div>{rows?.data.length===0&&<p>{tx('No observations recorded.')}</p>}
      <div className="pilot-actions"><button disabled={page<=1||busy} onClick={()=>setPage(page-1)}>{tx('Previous')}</button><span>{page} / {rows?.last_page||1}</span><button disabled={!rows||page>=rows.last_page||busy} onClick={()=>setPage(page+1)}>{tx('Next')}</button></div>
    </section>
  </>
  return embedded?<div className="pilot-form">{content}</div>:<main className="pilot-page">{content}</main>
}
