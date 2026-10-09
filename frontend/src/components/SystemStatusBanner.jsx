import { useCallback, useEffect, useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { getSystemMode } from '../api/system'
import { systemStatus } from './systemStatus'
import { useTranslation } from '../hooks/useTranslation'

export default function SystemStatusBanner() {
  const { language } = useTranslation()
  const t = (en, sq) => language === 'sq' ? sq : en
  const [data, setData] = useState(null)
  const [failed, setFailed] = useState(false)
  const [busy, setBusy] = useState(false)
  const refresh = useCallback(async () => {
    setBusy(true)
    try {
      setData(await getSystemMode())
      setFailed(false)
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }, [])
  useEffect(() => { void refresh() }, [refresh])
  const status = systemStatus(data, failed)
  if (data?.synthetic) return <div className="system-mode-banner" role="status"><strong>{t('SYNTHETIC / TEST DATA — isolated PM3 company', 'TË DHËNA SINTETIKE / TEST — kompani e izoluar PM3')}</strong></div>
  if (!status || status.tone === 'is-online') return null
  return <div className={`system-mode-banner ${status.tone}`} role="status">
    <div><strong>{t('System connection needs attention', 'Lidhja e sistemit kërkon vëmendje')}</strong><details><summary>{t('Connection details','Hollësitë e lidhjes')}</summary><p>{status.message}</p></details></div>
    <button type="button" onClick={refresh} disabled={busy} title={t('Refresh system status','Rifresko gjendjen e sistemit')} aria-label={t('Refresh system status','Rifresko gjendjen e sistemit')}><RefreshCw size={16}/></button>
  </div>
}
