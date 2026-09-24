import { useUiText } from '../hooks/useUiText'
import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, RefreshCw, ShieldCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { getSystemIntegrity } from '../api/systemIntegrity'
import { canOpenPage } from '../config/pageAccess'
import { useAuthStore } from '../store/authStore'

export default function SystemIntegrity() {
  const permissions = useAuthStore(s=>s.permissions)
 const tx = useUiText()

  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = useCallback(async () => {
    setLoading(true); setError('')
    try { setData(await getSystemIntegrity()) }
    catch (cause) { setError(cause?.message || 'Could not load system diagnostics.') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  return <main className="integrity-page page-shell">
    <header className="page-header"><div><p className="eyebrow">{tx("AIMS ADMINISTRATION")}</p><h1>{tx("System Integrity")}</h1><span>{tx("Real diagnostics from company data, local jobs, integrations and reconciliation controls.")}</span></div><button type="button" className="secondary" onClick={load} disabled={loading}><RefreshCw size={17}/>{loading ? tx(" Checking…") : tx(" Refresh checks")}</button></header>
    {error && <p className="page-error" role="alert">{error}</p>}
    {data && <>
      <section className={`integrity-overview is-${data.status}`}><ShieldCheck size={28}/><div><strong>{data.status === 'healthy' ? tx("AIMS is healthy") : tx("AIMS needs attention")}</strong><span>{data.summary.healthy} {tx("healthy ·")} {data.summary.attention} {tx("attention ·")} {data.summary.critical} {tx("critical")}</span></div><time>{new Date(data.checked_at).toLocaleString()}</time></section>
      <section className="integrity-grid">{data.checks.map((check) => <article key={check.key} className={`integrity-check is-${check.status}`}>
        <div className="integrity-check-icon">{check.status === 'healthy' ? <CheckCircle2/> : <AlertTriangle/>}</div>
        <div><h2>{tx(check.label)}</h2><p>{tx(check.detail)}</p></div><strong>{check.count}</strong>
        <button type="button" className="secondary" disabled={!canOpenPage(check.url.replace(/^\//,""),permissions)} onClick={() => navigate(check.url)}>{tx("Open details")}</button>
      </article>)}</section>
      {data.backup_history?.length > 0 ? <section className="card integrity-history"><header><div><h2>{tx("Backup history")}</h2><p>{tx("Recent encrypted export and restore executions. Passphrases and secrets are never stored.")}</p></div></header><div className="table-wrap"><table><thead><tr><th>{tx("Started")}</th><th>{tx("Operation")}</th><th>{tx("Type")}</th><th>{tx("Status")}</th><th>{tx("Size")}</th><th>{tx("Verification")}</th></tr></thead><tbody>{data.backup_history.map((run) => <tr key={run.id}><td>{new Date(run.started_at).toLocaleString()}</td><td>{run.operation}</td><td>{run.backup_type}</td><td><span className={`status-badge ${run.status}`}>{run.status}</span></td><td>{run.size_bytes == null ? '—' : `${Math.max(1, Math.round(run.size_bytes / 1024))} KB`}</td><td>{run.verification_result || run.error_summary || 'In progress'}</td></tr>)}</tbody></table></div></section> : null}
      <p className="integrity-request-id">{tx("Diagnostic request:")} {data.request_id}</p>
    </>}
  </main>
}
