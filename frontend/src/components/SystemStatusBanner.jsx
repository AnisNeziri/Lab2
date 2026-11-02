import { useCallback, useEffect, useState } from 'react'
import { CircleAlert, RefreshCw } from 'lucide-react'
import { getSystemMode } from '../api/system'
import { systemStatus } from './systemStatus'
import { useTranslation } from '../hooks/useTranslation'
import { businessError } from '../utils/businessErrors'

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
  const optional=status.kind==='redis'
  return <div className={`system-mode-banner system-status-banner ${status.tone}`} role="status" aria-busy={busy}>
    <CircleAlert className="system-status-icon" size={19} aria-hidden="true"/>
    <div className="system-status-copy"><strong>{optional?t('Optional connection unavailable','Lidhja opsionale nuk është e disponueshme'):t('System connection needs attention', 'Lidhja e sistemit kërkon vëmendje')}</strong>
      <p>{optional?t('You can keep working. Some cached summaries may be unavailable.','Mund të vazhdosh punën. Disa përmbledhje të ruajtura mund të mos jenë të disponueshme.'):t('Check the connection before relying on refreshed information.','Kontrollo lidhjen para se të mbështetesh në informacionin e rifreskuar.')}</p>
      <details><summary>{t('Connection details','Hollësitë e lidhjes')}</summary>{optional&&<span>{t('Optional cache service: Redis','Shërbimi opsional i memories: Redis')}</span>}<p>{businessError(status.message,undefined,language)}</p></details>
    </div>
    <button className="system-status-refresh" type="button" onClick={refresh} disabled={busy} aria-label={t('Refresh system status','Rifresko gjendjen e sistemit')}><RefreshCw size={16} className={busy?'is-refreshing':''} aria-hidden="true"/><span>{busy?t('Checking…','Duke kontrolluar…'):t('Check again','Kontrollo përsëri')}</span></button>
  </div>
}
