import { useCallback, useEffect, useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { getSystemMode } from '../api/system'
import { systemStatus } from './systemStatus'

export default function SystemStatusBanner() {
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
  if (!status) return null
  return <div className={`system-mode-banner ${status.tone}`} role="status">
    <div><strong>{status.title}</strong><p>{status.message}</p></div>
    <button type="button" onClick={refresh} disabled={busy} title="Refresh system status" aria-label="Refresh system status"><RefreshCw size={16}/></button>
  </div>
}
