import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'
import { canOpenPage } from '../config/pageAccess'
const sections=[
 ['/accounting?tab=owner','Overview','Përmbledhja'],['/accounting?tab=ar','Receivables','Të arkëtueshmet'],['/accounting?tab=ap','Payables','Detyrimet'],['/money-accounts','Cash & bank','Arka dhe banka'],['/finance?tab=expenses','Expenses','Shpenzimet'],['/accounting?tab=journal','General Ledger','Libri kryesor'],['/accounting?tab=reconciliation','Reconciliation','Rakordimi'],['/accounting?tab=reports','Statements','Pasqyrat'],['/accounting?tab=periods','Period close','Mbyllja e periudhës'],['/tax','Tax obligations','Detyrimet tatimore'],['/finance','Operational activity','Aktiviteti operativ'],['/financial-intelligence','Forecast','Parashikimi'],
]
export default function FinanceWorkspaceNav(){
 const location=useLocation(),navigate=useNavigate(),{language}=useTranslation(),permissions=useAuthStore(s=>s.permissions),capabilities=useAuthStore(s=>s.user?.commercial?.capabilities)
 if(!['/accounting','/finance','/money-accounts','/invoices','/tax','/fiscalization','/financial-intelligence'].includes(location.pathname))return null
 const t=(en,sq)=>language==='sq'?sq:en
 const allowed=sections.filter(([url])=>canOpenPage(url,permissions,capabilities)&&(!url.includes('tab=periods')||permissions.includes('accounting.periods.manage'))&&(!url.includes('tab=journal')||permissions.includes('accounting.journal.view'))&&(!url.includes('tab=reconciliation')||permissions.includes('accounting.integrity.view')))
 const current=allowed.find(([url])=>url===location.pathname+location.search)?.[0]||allowed.find(([url])=>url===location.pathname)?.[0]||''
 return <nav className="dc9-finance-nav" aria-label={t('Finance workspace','Hapësira financiare')}><strong>{t('Finance','Financat')}</strong><label><span>{t('Go to','Shko te')}</span><select aria-label={t('Finance section','Seksioni financiar')} value={current} onChange={e=>navigate(e.target.value)}><option value="" disabled>{t('Choose section','Zgjidh seksionin')}</option>{allowed.map(([url,en,sq])=><option value={url} key={url}>{t(en,sq)}</option>)}</select></label>{location.pathname==='/money-accounts'&&<p className="dc9-nav-caption">{t('Operational cash/bank ledger · current balances. Reconcile with Posted Books.','Libri operativ i arkës/bankës · gjendjet aktuale. Rakordo me librat e postuar.')}</p>}{location.pathname==='/financial-intelligence'&&<p className="dc9-nav-caption">{t('Forecast · projected timing and commitments. These are not booked balances.','Parashikim · koha dhe angazhimet e parashikuara. Këto nuk janë gjendje të postuara.')}</p>}</nav>
}
