import { useState } from 'react'
import { apiRequest } from '../api/client'

export default function AutomationOutcomeVerification({ executionId, t, onChanged }) {
  const [reason,setReason]=useState(''),[outcome,setOutcome]=useState('effect_reviewed'),[busy,setBusy]=useState(false),[error,setError]=useState('')
  return <form className="auto-confirmation" onSubmit={async e=>{e.preventDefault();setBusy(true);setError('');try{await apiRequest(`/automation-executions/${executionId}/verification`,{method:'POST',body:JSON.stringify({reason,outcome})});await onChanged()}catch(e){setError(e.message)}finally{setBusy(false)}}}>
    <p>{t('An uncertain result must be checked in the linked source and action history before any replay.','Rezultati i pasigurt duhet verifikuar në burim dhe në historinë e veprimeve para riprovimit.')}</p>
    <label>{t('Verified outcome','Rezultati i verifikuar')}<select value={outcome} onChange={e=>setOutcome(e.target.value)}><option value="effect_reviewed">{t('Outcome inspected — do not replay','Rezultati u kontrollua — mos e përsërit')}</option><option value="no_effect">{t('Verified no persistent effect — allow explicit safe retry','U verifikua pa efekt të regjistruar — lejo riprovim të kontrolluar')}</option></select></label>
    <label>{t('Verification evidence / reason','Dëshmia / arsyeja e verifikimit')}<textarea required minLength={10} maxLength={1000} value={reason} onChange={e=>setReason(e.target.value)}/></label>
    <button disabled={busy}>{t('Record verification','Regjistro verifikimin')}</button>{error&&<p role="alert">{error}</p>}
  </form>
}
