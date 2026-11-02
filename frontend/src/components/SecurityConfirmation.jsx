import { useEffect, useRef, useState } from 'react'
import { apiRequest } from '../api/client'
import { usePasskey } from '../lib/passkeys'
import { useTranslation } from '../hooks/useTranslation'
import WorkspaceModal from './WorkspaceModal'
import { businessError } from '../utils/businessErrors'
import '../pages/SecurityCenter.css'

export default function SecurityConfirmation() {
  const { language } = useTranslation(), t = (en, sq) => language === 'sq' ? sq : en
  const [pending, setPending] = useState(null), [password, setPassword] = useState(''), [error, setError] = useState(''), [busy, setBusy] = useState(false)
  const active = useRef(null)
  useEffect(() => {
    const handler = e => { if (active.current) { e.detail.resolve(false); return }; active.current=e.detail; setPassword('');setError('');setPending(e.detail) }
    window.addEventListener('aims-step-up', handler)
    return () => { window.removeEventListener('aims-step-up', handler);active.current?.resolve(false) }
  }, [])
  const finish = success => { active.current?.resolve(success);active.current=null;setPending(null);setPassword('');setBusy(false) }
  const confirm = async passkey => {
    setBusy(true);setError('')
    try { if (passkey) await usePasskey('step_up');else await apiRequest('/security/confirm',{method:'POST',body:JSON.stringify({password})});finish(true) }
    catch (e) { setError(businessError(e.message,undefined,language));setBusy(false);setPassword('') }
  }
  if (!pending) return null
  return <WorkspaceModal title={t('Confirm your identity','Konfirmo identitetin')} onClose={()=>finish(false)} busy={busy} className="workspace-confirm-dialog security-identity-dialog">
    <p>{t('This sensitive action needs a recent identity check.','Ky veprim i ndjeshëm kërkon konfirmim të identitetit.')}</p>
    {error && <p role="alert">{error}</p>}
    {!pending.passkey && <form onSubmit={e=>{e.preventDefault();confirm(false)}}><label>{t('Current password','Fjalëkalimi aktual')}<input autoFocus type="password" autoComplete="current-password" value={password} onChange={e=>setPassword(e.target.value)} required /></label><button disabled={busy} type="submit">{t('Confirm','Konfirmo')}</button></form>}
    <div className="workspace-actions"><button disabled={busy} onClick={()=>confirm(true)}>{t('Confirm with passkey','Konfirmo me passkey')}</button>
    <button disabled={busy} onClick={()=>finish(false)}>{t('Cancel','Anulo')}</button></div>
  </WorkspaceModal>
}
