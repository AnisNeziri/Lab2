import DataIntegrityReview from '../components/DataIntegrityReview'
import CommissioningChecklist from '../components/CommissioningChecklist'
import Reports from './Reports'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useUiText } from '../hooks/useUiText'
import { useCallback, useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2, RefreshCw, ShieldCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { getSystemIntegrity } from '../api/systemIntegrity'
import { canOpenPage } from '../config/pageAccess'
import { useAuthStore } from '../store/authStore'

export default function SystemIntegrity() {
  const [params]=useSearchParams(), {language}=useTranslation(),text=(en,sq)=>language==='sq'?sq:en
  const role=useAuthStore(s=>s.role)
  const permissions = useAuthStore(s=>s.permissions)
 const tx = useUiText()

  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const load = useCallback(async () => {
    setLoading(true); setError('')
    try { setData(await getSystemIntegrity()) }
    catch (cause) { setData(null); setError(cause?.message || 'Could not load system diagnostics.') }
    finally { setLoading(false) }
  }, [])
  const referenceView=params.get('view')==='references'
  useEffect(() => { if(!referenceView)load() }, [load,referenceView])

  return <main className="integrity-page page-shell">
    <header className="page-header"><div><p className="eyebrow">{tx("AIMS ADMINISTRATION")}</p><h1>{tx("System Integrity")}</h1><span>{tx("Real diagnostics from company data, local jobs, integrations and reconciliation controls.")}</span></div>{!referenceView&&<button type="button" className="secondary dc9-inline-action" onClick={load} disabled={loading}><RefreshCw size={17}/>{loading ? tx(" Checking…") : tx(" Refresh checks")}</button>}</header>
    <nav className="dc9-section-links" aria-label={text('System administration','Administrimi i sistemit')}><Link to="/system-integrity">{text('Health checks','Kontrollet e gjendjes')}</Link><Link to="/system-integrity?view=recovery">{text('Backup & recovery','Kopjeruajtja dhe rikuperimi')}</Link>{role==='admin'&&permissions.includes('deployment.view')&&<Link to="/system-integrity?view=references">{text('Data integrity review','Shqyrtimi i integritetit të të dhënave')}</Link>}</nav>
    {params.get('view')==='references'?<DataIntegrityReview/>:params.get('view')==='recovery'?<Reports recoveryOnly/>:<>
    {error && <p className="page-error" role="alert">{error}</p>}
    {data && <>
      <CommissioningChecklist data={data.operational_readiness?.commissioning}/>
      {data.operational_readiness&&<section className="dc10-readiness" aria-label={text('Recovery and opening readiness','Gatishmëria e rikuperimit dhe hapjes')}>
        {[
          [text('Database recovery rehearsal','Prova e rikuperimit të bazës'),data.operational_readiness.database_recovery.status,text('An isolated SQL restore does not switch this installation.','Rikuperimi SQL i izoluar nuk e kalon këtë instalim.')],
          [text('Financial opening','Gjendjet fillestare financiare'),data.operational_readiness.financial_opening.status,text('Independent source statements and accountant approval are required.','Kërkohen pasqyra burimore të pavarura dhe miratimi i kontabilistit.')],
          [text('Encrypted installation restore','Rikuperimi i enkriptuar i instalimit'),data.operational_readiness.encrypted_installation_restore.status,text('Only a successful DC8 installation restore verifies this control.','Vetëm rikuperimi i suksesshëm i instalimit DC8 e verifikon këtë kontroll.')],
          [text('Live company operation','Puna me kompani reale'),data.operational_readiness.live_company_operation.status,text('Requires all controls and a separately approved active cutover.','Kërkon të gjitha kontrollet dhe kalim aktiv të miratuar veçmas.')],
        ].map(([label,status,detail])=><article className="card" key={label}><strong>{label}</strong><p>{({VERIFIED_ISOLATED:text('Verified in isolation','Verifikuar në izolim'),NOT_VERIFIED:text('Not verified','I paverifikuar'),BLOCKED_SOURCE_REQUIRED:text('BLOCKED — SOURCE REQUIRED','BLLOKUAR — KËRKOHET BURIMI'),APPROVED:text('Approved','Miratuar'),VERIFIED:text('Verified','Verifikuar'),READY:text('Ready','Gati'),BLOCKED:text('Blocked','Bllokuar')})[status]||status}</p><small>{detail}</small></article>)}
      </section>}
      <section className={`integrity-overview is-${data.status}`}><ShieldCheck size={28}/><div><strong>{data.status === 'healthy' ? tx("AIMS is healthy") : tx("AIMS needs attention")}</strong><span>{data.summary.healthy} {tx("healthy ·")} {data.summary.attention} {tx("attention ·")} {data.summary.critical} {tx("critical")}</span></div><time>{new Date(data.checked_at).toLocaleString()}</time></section>
      <section className="integrity-grid">{data.checks.map((check) => <article key={check.key} className={`integrity-check is-${check.status}`}>
        <div className="integrity-check-icon">{check.status === 'healthy' ? <CheckCircle2/> : <AlertTriangle/>}</div>
        <div><h2>{tx(check.label)}</h2><p>{tx(check.detail)}</p>
          {check.last_success_at && <p>{tx('Last successful run')}: {new Date(check.last_success_at).toLocaleString()}</p>}
          {check.last_verified_backup_at && <p>{tx('Last verified backup')}: {new Date(check.last_verified_backup_at).toLocaleString()}</p>}
          {check.last_backup_failure_at && <p>{tx('Last backup failure')}: {new Date(check.last_backup_failure_at).toLocaleString()}</p>}
          {check.last_observation_at && <p>{tx('Last observation')}: {new Date(check.last_observation_at).toLocaleString()}</p>}
          {check.error_code && <code>{check.error_code}</code>}
        </div><strong>{check.count}</strong>
        <button type="button" className="secondary" disabled={!canOpenPage(check.url.replace(/^\//,""),permissions)} onClick={() => navigate(check.url)}>{tx("Open details")}</button>
      </article>)}</section>
      {data.backup_history?.length > 0 ? <section className="card integrity-history"><header><div><h2>{tx("Backup history")}</h2><p>{tx("Recent encrypted export and restore executions. Passphrases and secrets are never stored.")}</p></div></header><div className="table-wrap"><table><thead><tr><th>{tx("Started")}</th><th>{tx("Operation")}</th><th>{tx("Type")}</th><th>{tx("Status")}</th><th>{tx("Size")}</th><th>{tx("Verification")}</th></tr></thead><tbody>{data.backup_history.map((run) => <tr key={run.id}><td>{new Date(run.started_at).toLocaleString()}</td><td>{run.operation}</td><td>{run.backup_type}</td><td><span className={`status-badge ${run.status}`}>{run.status}</span></td><td>{run.size_bytes == null ? '—' : `${Math.max(1, Math.round(run.size_bytes / 1024))} KB`}</td><td>{run.verification_result || run.error_summary || 'In progress'}</td></tr>)}</tbody></table></div></section> : null}
      <p className="integrity-request-id">{tx("Diagnostic request:")} {data.request_id}</p>
    </>}
    </>}
  </main>
}
