import { Link } from 'react-router-dom'
import { useAuthStore } from '../../store/authStore'
import { useTranslation } from '../../hooks/useTranslation'
import { useWidgetData } from './DashboardData'
import { BusinessFacts } from '../WorkflowSummary'
import { businessMoney } from '../../utils/businessFormat'
import { businessStatus } from '../../utils/businessStatus'

export default function WorkspacePulse() {
  const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const permissions=useAuthStore(s=>s.permissions)
  const finance=permissions.includes('accounting.reports.view'),pay=permissions.includes('payroll.view'),fiscal=permissions.includes('fiscalization.view')
  const financial=useWidgetData(finance?'/accounting/control-center':null)
  const payment=useWidgetData(pay?'/payroll/summary':null)
  const fiscalization=useWidgetData(fiscal?'/fiscalization':null)
  const state={finance:financial,pay:payment,fiscal:fiscalization}
  if(!finance&&!pay&&!fiscal)return null
  const f=financial.data,p=payment.data,x=fiscalization.data
  return <section ref={node=>{financial.ref.current=node;payment.ref.current=node;fiscalization.ref.current=node}} className="workspace-pulse" aria-label={t('Business at a glance','Biznesi me një shikim')}>
    <header><h2>{t('Business at a glance','Biznesi me një shikim')}</h2><small>{t('Recorded balances · forecasts are shown separately below','Gjendje të regjistruara · parashikimet shfaqen veçmas më poshtë')}</small></header>
    {f&&<BusinessFacts facts={[
      {label:t('Cash & bank','Arka dhe banka'),value:f.summary.cash==null||f.summary.bank==null?'—':businessMoney(Number(f.summary.cash)+Number(f.summary.bank),f.currency,language),to:'/accounting?tab=bank'},
      {label:t('Customers owe us','Borxhet e klientëve'),value:businessMoney(f.summary.accounts_receivable,f.currency,language),to:'/accounting?tab=ar'},
      {label:t('We owe suppliers','Detyrimet ndaj furnitorëve'),value:businessMoney(f.summary.accounts_payable,f.currency,language),to:'/accounting?tab=ap'},
      {label:t('Net result','Rezultati neto'),value:businessMoney(f.summary.net_profit,f.currency,language),to:'/accounting?tab=owner',note:t('Current month','Muaji aktual')},
    ]}/>}
    <nav className="workspace-pulse-links" aria-label={t('Control centers','Qendrat e kontrollit')}>
      {finance&&<Link to="/accounting"><strong>{t('Accounting','Kontabiliteti')}</strong><span>{f?businessStatus(f.integrity_status.toLowerCase(),language):state.finance?.error?t('Unavailable · review source','Jo në dispozicion · shqyrto burimin'):t('Loading…','Duke ngarkuar…')}</span></Link>}
      {pay&&<Link to={p?.latest_run?'/payroll?run='+p.latest_run.id:'/payroll'}><strong>{t('Payroll','Pagat')}</strong><span>{p?.latest_run?`${p.latest_run.period?.name||''} · ${businessStatus(p.latest_run.status,language)}`:p?t('No payroll period','Pa periudhë pagash'):state.pay?.error?t('Unavailable · review source','Jo në dispozicion · shqyrto burimin'):t('Loading…','Duke ngarkuar…')}</span></Link>}
      {fiscal&&<Link to="/fiscalization"><strong>{t('Fiscalization','Fiskalizimi')}</strong><span>{x?`${x.needs_review_count||0} ${t('need review · ATK TEST only','për shqyrtim · vetëm ATK TEST')}`:state.fiscal?.error?t('Unavailable · review source','Jo në dispozicion · shqyrto burimin'):t('Loading…','Duke ngarkuar…')}</span></Link>}
    </nav>
    {Object.values(state).some(s=>s.error)&&<p className="wf-note" role="status">{t('Some controls could not refresh. Previously loaded figures remain visible; open the source before acting.','Disa kontrolle nuk u përditësuan. Gjendjet e ngarkuara mbeten të dukshme; hap burimin para veprimit.')}</p>}
    {Object.values(state).some(s=>s.error)&&<button type="button" className="secondary" onClick={()=>Object.values(state).filter(s=>s.error).forEach(s=>s.retry())}>{t('Retry summary','Provo përmbledhjen përsëri')}</button>}
  </section>
}
