import { useEffect, useState } from 'react'
import { useLocation, Link } from 'react-router-dom'
import WorkspaceModal from './WorkspaceModal'
import { useTranslation } from '../hooks/useTranslation'
import { useUiText } from '../hooks/useUiText'
import { useAuthStore } from '../store/authStore'
import { pilot, writePilot } from '../api/pilot'
import { pilotEntityContext } from './pilotContext'
import '../styles/pilot.css'

const categories = [ ['confusing','Confusing','E paqartë'], ['missing_action','Missing action','Veprim që mungon'], ['too_many_clicks','Too many clicks','Shumë klikime'], ['slow','Slow','E ngadaltë'], ['terminology','Wrong terminology','Terminologji e gabuar'], ['missing_data','Missing data','Të dhëna që mungojnë'], ['error','Error','Gabim'], ['feature_request','Feature request','Kërkesë për funksion'] ]

export default function PilotFeedback() {
  const { language } = useTranslation(), tx = useUiText()
  const role = useAuthStore(s => s.role), location = useLocation()
  const [open,setOpen] = useState(false), [busy,setBusy] = useState(false), [error,setError] = useState(''), [sent,setSent] = useState(false)
  const [category,setCategory] = useState('confusing'), [description,setDescription] = useState(''), [reference,setReference] = useState(''), [telemetry,setTelemetry] = useState(false)
  useEffect(() => {
    if (role === 'superadmin') return
    let active = true
    pilot('/context').then(data => { if (active) setTelemetry(data.telemetry_enabled) }).catch(() => {})
    return () => { active = false }
  }, [role])
  useEffect(() => {
    if (telemetry && document.visibilityState === 'visible') writePilot('/visit', {}).catch(() => {})
  }, [location.pathname,telemetry])
  if (role === 'superadmin') return null
  const submit = async event => {
    event.preventDefault(); setBusy(true); setError('')
    try {
      await writePilot('/feedback', { category, page: location.pathname, description: description || null, reference: reference || null, ...pilotEntityContext(location.pathname, location.search) })
      setSent(true); setDescription(''); setReference('')
    } catch (cause) { setError(cause.message) } finally { setBusy(false) }
  }
  return <footer className="pilot-feedback-bar">
    <button type="button" className="secondary" onClick={() => {setOpen(true);setSent(false);setError('')}}>{tx('Report a problem')}</button>
    <Link to="/pilot-evidence">{tx('Record a pilot trial')}</Link>
    {telemetry && <span>{tx('Pilot usage aggregates enabled')}</span>}
    {open && <WorkspaceModal title={tx('Report a problem')} onClose={() => setOpen(false)} busy={busy} warnOnUnsaved={Boolean(description)}>
      {sent ? <p role="status">{tx('Report saved for your company administrator.')}</p> : <form className="pilot-form" onSubmit={submit}>
        <p>{tx('Page and role are included. Do not include passwords, customer details, account numbers or document contents.')}</p>
        <label>{tx('Category')}<select value={category} onChange={e=>setCategory(e.target.value)}>{categories.map(([value,en,sq])=><option key={value} value={value}>{language === 'sq' ? sq : en}</option>)}</select></label>
        <label>{tx('What happened? (optional)')}<textarea maxLength={2000} value={description} onChange={e=>setDescription(e.target.value)}/></label>
        <label>{tx('Error reference (optional)')}<input pattern="AIMS-[a-f0-9]{16}" maxLength={21} placeholder="AIMS-…" value={reference} onChange={e=>setReference(e.target.value)}/></label>
        {error && <p role="alert" className="page-error">{error}</p>}
        <button disabled={busy}>{busy ? tx('Saving…') : tx('Send report')}</button>
      </form>}
    </WorkspaceModal>}
  </footer>
}
