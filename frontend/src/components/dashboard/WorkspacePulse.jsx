import { Link } from 'react-router-dom'
import { ArrowUpRight, BookOpen, FileCheck, Wallet } from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import { useTranslation } from '../../hooks/useTranslation'
import { useWidgetData } from './DashboardData'
import { BusinessFacts } from '../WorkflowSummary'
import { businessDate, businessMoney } from '../../utils/businessFormat'
import { businessStatus } from '../../utils/businessStatus'

export default function WorkspacePulse() {
  const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const permissions=useAuthStore(s=>s.permissions)
  const finance=permissions.includes('accounting.reports.view'),pay=permissions.includes('payroll.view'),fiscal=permissions.includes('fiscalization.view')
  const financial=useWidgetData(finance?'/accounting/control-center':null)
  const payment=useWidgetData(pay?'/payroll/summary':null)
  const fiscalization=useWidgetData(fiscal?'/fiscalization':null)
  const sources = [financial, payment, fiscalization]
  if(!finance&&!pay&&!fiscal)return null
  const f=financial.data,p=payment.data,x=fiscalization.data
  const qualified=f?.opening_readiness?.status==='verified'&&f?.controls_reconciled&&f?.books_balanced
  return <section ref={node=>{financial.ref.current=node;payment.ref.current=node;fiscalization.ref.current=node}} className="workspace-pulse" aria-label={t('Business at a glance','Biznesi me një shikim')}>
    <header>
      <h2>{t('Business at a glance','Biznesi me një shikim')}</h2>
      {f && <small>{businessDate(f.from,language)} – {businessDate(f.to,language)}</small>}
    </header>
    {f&&qualified&&<BusinessFacts facts={[
      {label:t('Cash & bank','Arka dhe banka'),value:f.summary.cash==null||f.summary.bank==null?'—':businessMoney(Number(f.summary.cash)+Number(f.summary.bank),f.currency,language),to:'/accounting?tab=bank'},
      {label:t('Customers owe us','Borxhet e klientëve'),value:businessMoney(f.summary.accounts_receivable,f.currency,language),to:'/accounting?tab=ar'},
      {label:t('We owe suppliers','Detyrimet ndaj furnitorëve'),value:businessMoney(f.summary.accounts_payable,f.currency,language),to:'/accounting?tab=ap'},
      {label:t('Net result','Rezultati neto'),value:businessMoney(f.summary.net_profit,f.currency,language),to:'/accounting?tab=owner',note:t('Current month','Muaji aktual')},
    ]}/>}
    <nav className="workspace-pulse-links" aria-label={t('Control centers','Qendrat e kontrollit')}>
      {finance && <PulseLink icon={BookOpen} title={t('Accounting','Kontabiliteti')}
        to={f && !qualified && permissions.includes('accounting.integrity.view') ? '/accounting?tab=reconciliation' : '/accounting'}
        attention={Boolean(f && !qualified)} source={financial} t={t}
        status={f && (qualified ? businessStatus(f.integrity_status,language) : t('Verification needed','Kërkohet verifikim'))}
        note={f && !qualified ? t('Cash and profit figures withheld','Shifrat e arkës dhe fitimit nuk shfaqen') : ''} />}
      {pay && <PulseLink icon={Wallet} title={t('Payroll','Pagat')} to={p?.latest_run ? '/payroll?run='+p.latest_run.id : '/payroll'}
        source={payment} t={t} status={p && (p.latest_run ? businessStatus(p.latest_run.status,language) : t('No payroll period','Pa periudhë pagash'))}
        note={p?.latest_run?.period?.name} />}
      {fiscal && <PulseLink icon={FileCheck} title={t('Fiscalization','Fiskalizimi')} to="/fiscalization" source={fiscalization} t={t}
        attention={x?.needs_review_count > 0}
        status={x && `${x.needs_review_count ?? 0} ${t('need review','për shqyrtim')}`}
        note={x && t('ATK test only','Vetëm test ATK')} />}
    </nav>
    {f && <details className="workspace-pulse-basis">
      <summary>{t('How to read these figures','Si t’i lexosh shifrat')}</summary>
      <p>{t('Figures come from the posted General Ledger. Forecasts are shown separately.','Shifrat vijnë nga libri kryesor i postuar. Parashikimet shfaqen veçmas.')}</p>
      <p>{f.controls_reconciled ? t('Current subledgers are reconciled.','Nënlibrat aktualë janë rakorduar.') : t('Current subledgers have differences.','Nënlibrat aktualë kanë diferenca.')}</p>
      {!qualified && <p>{t('Verify opening balances and accounting controls to see cash and profit figures.','Verifiko gjendjet fillestare dhe kontrollet kontabël për të parë shifrat e arkës dhe fitimit.')}</p>}
    </details>}
    {sources.some(source=>source.error) && <div className="workspace-pulse-refresh">
      <span role="status">{t('Some figures may be out of date.','Disa shifra mund të jenë të vjetruara.')}</span>
      <button type="button" className="secondary" onClick={()=>sources.filter(source=>source.error).forEach(source=>source.retry())}>{t('Retry summary','Provo përmbledhjen përsëri')}</button>
    </div>}
  </section>
}

function PulseLink({icon: Icon, title, to, status, note, attention, source, t}) {
  const label = source.error
    ? (status ? t('Could not refresh','Nuk u përditësua') : t('Unavailable','Nuk është në dispozicion'))
    : status || t('Loading…','Duke ngarkuar…')
  return <Link className="workspace-pulse-link" to={to}>
    <Icon size={18} aria-hidden="true"/>
    <span><strong>{title}</strong><span data-attention={attention || Boolean(source.error)}>{label}</span>{note && <small>{note}</small>}</span>
    <ArrowUpRight size={15} aria-hidden="true"/>
  </Link>
}
