import { Link } from 'react-router-dom'
import { useEffect } from 'react'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'
import { canOpenPage } from '../config/pageAccess'
import { workProfiles } from '../config/workProfiles'
import { dailyStarts } from '../config/everydayWorkspace'
import { useWorkFocus } from '../hooks/useWorkFocus'

const starts={
 owner:[['/action-center','Decisions & approvals','Vendimet dhe miratimet'],['/accounting?tab=owner','Money & company state','Paratë dhe gjendja e kompanisë'],['/purchasing-decisions','Suppliers & purchase budgets','Furnitorët dhe buxheti i blerjeve'],['/order-hub','Orders & delivery','Porositë dhe dorëzimi']],
 sales:[['/customers','Customers & follow-ups','Klientët dhe ndjekjet'],['/customers?view=quotations','Quotations','Ofertat'],['/order-hub','Orders needing review','Porositë për shqyrtim']],
 warehouse:[['/fulfillment','Pick & dispatch','Përgatit dhe dërgo'],['/warehouse-operations?tab=receipts','Receive goods','Prano mallin'],['/warehouse-mobile','Scan, find & count','Skano, gjej dhe numëro']],
 purchasing:[['/purchasing-decisions','Compare suppliers','Krahaso furnitorët'],['/procurement','Review purchase requests','Shqyrto kërkesat e blerjes'],['/purchase-orders','Purchase orders','Porositë e blerjes'],['/shipments/my-shipments','Incoming shipments','Dërgesat në ardhje']],
 finance:[['/accounting','Finance workspace','Hapësira financiare'],['/accounting?tab=reconciliation','Investigate differences','Shqyrto diferencat'],['/accounting?tab=periods','Month close','Mbyllja mujore']],
 hr:[['/workforce','People & time approvals','Personeli dhe miratimi i orëve'],['/payroll','Prepare payroll','Përgatit pagat'],['/action-center?view=mine','Assigned reviews','Shqyrtimet e caktuara']],
 manager:[['/action-center','Team reviews','Shqyrtimet e ekipit'],['/order-hub','Orders','Porositë'],['/warehouse-operations','Warehouse','Depoja']],
}
export default function DailyWorkspace({onProfileChange}){
 const {language}=useTranslation(),permissions=useAuthStore(s=>s.permissions),caps=useAuthStore(s=>s.user?.commercial?.capabilities),{profile}=useWorkFocus(),t=(en,sq)=>language==='sq'?sq:en
 useEffect(()=>{onProfileChange?.(profile||null)},[profile,onProfileChange])
 if(!profile)return null
 const name=workProfiles[profile]?.[language==='sq'?'sq':'en']||profile
 return <section className="everyday-start" aria-label={t('Daily workspace','Hapësira ditore')}><header><div><span className="everyday-eyebrow">{name}</span><h2>{t('Start with today’s work','Fillo me punët e sotme')}</h2><p>{t('Review urgent work below, or open a daily workflow.','Shqyrto punët urgjente më poshtë, ose hap një veprim të përditshëm.')}</p></div>{canOpenPage('/action-center',permissions,caps)&&<Link className="workspace-primary" to="/action-center?view=mine&status=active">{t('My tasks','Detyrat e mia')}</Link>}</header><nav aria-label={t('Daily actions','Veprimet ditore')}>{(starts[profile]||[[dailyStarts[profile],'Open workspace','Hap hapësirën']]).filter(([path])=>canOpenPage(path,permissions,caps)&&(!path.includes('tab=periods')||permissions.includes('accounting.periods.manage'))&&(!path.includes('tab=reconciliation')||permissions.includes('accounting.integrity.view'))).map(([path,en,sq])=><Link key={path} to={path}>{t(en,sq)}<span aria-hidden="true">↗</span></Link>)}</nav></section>
}
