import { Link } from 'react-router-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'
import { canOpenPage } from '../config/pageAccess'
import { workProfiles } from '../config/workProfiles'
import { dailyStarts } from '../config/everydayWorkspace'

const starts={
 owner:[['/action-center?area=approval#related-approvals','Approvals to review','Miratimet për shqyrtim'],['/accounting?tab=owner','Money & company state','Paratë dhe gjendja e kompanisë'],['/purchasing-decisions','Suppliers & purchase budgets','Furnitorët dhe buxheti i blerjeve'],['/order-hub','Orders & delivery','Porositë dhe dorëzimi']],
 sales:[['/customers','Customers & follow-ups','Klientët dhe ndjekjet'],['/customers?view=quotations','Quotations','Ofertat'],['/order-hub','Orders needing review','Porositë për shqyrtim']],
 warehouse:[['/fulfillment','Pick & dispatch','Përgatit dhe dërgo'],['/warehouse-operations?tab=receipts','Goods receipts','Pranimet e mallit','purchase_orders.view'],['/warehouse-mobile','Scan, find & count','Skano, gjej dhe numëro']],
 purchasing:[['/purchasing-decisions','Compare suppliers','Krahaso furnitorët'],['/procurement','Review purchase requests','Shqyrto kërkesat e blerjes'],['/purchase-orders','Purchase orders','Porositë e blerjes'],['/shipments/my-shipments','Incoming shipments','Dërgesat në ardhje']],
 finance:[['/accounting','Finance workspace','Hapësira financiare'],['/accounting?tab=reconciliation','Investigate differences','Shqyrto diferencat','accounting.integrity.view'],['/accounting?tab=periods','Month close','Mbyllja mujore','accounting.periods.manage']],
 hr:[['/workforce','People & time approvals','Personeli dhe miratimi i orëve'],['/payroll','Prepare payroll','Përgatit pagat'],['/action-center?view=mine','Assigned reviews','Shqyrtimet e caktuara']],
 manager:[['/action-center','Team reviews','Shqyrtimet e ekipit'],['/order-hub','Orders','Porositë'],['/warehouse-operations','Warehouse','Depoja']],
}
export default function DailyWorkspace({profile}){
 const {language}=useTranslation(),permissions=useAuthStore(s=>s.permissions),caps=useAuthStore(s=>s.user?.commercial?.capabilities),t=(en,sq)=>language==='sq'?sq:en
 if(!profile)return null
 const name=workProfiles[profile]?.[language==='sq'?'sq':'en']||profile
 const shortcuts = (starts[profile] || [[dailyStarts[profile],'Open workspace','Hap hapësirën']])
  .filter(([path,,,permission]) => canOpenPage(path,permissions,caps) && (!permission || permissions.includes(permission)))
 return <section className="everyday-start" aria-label={t('Daily workspace','Hapësira ditore')}>
  <header>
   <div><span className="everyday-eyebrow">{name}</span><h2>{t('Quick links','Lidhje të shpejta')}</h2></div>
   {canOpenPage('/action-center',permissions,caps)&&<Link className="everyday-tasks-link" to="/action-center?view=mine&status=active">{t('My tasks','Detyrat e mia')}</Link>}
  </header>
  <nav aria-label={t('Daily actions','Veprimet ditore')}>{shortcuts.map(([path,en,sq])=><Link key={path} to={path}>{t(en,sq)}<span aria-hidden="true">↗</span></Link>)}</nav>
 </section>
}
