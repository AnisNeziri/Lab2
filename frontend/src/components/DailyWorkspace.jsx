import { Link } from 'react-router-dom'
import { useSessionState } from '../hooks/useSessionState'
import { useEffect } from 'react'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'
import { canOpenPage } from '../config/pageAccess'
const profiles={
 owner:['Owner','Pronari','/accounting?tab=owner','Business state and cash exposure','Gjendja e biznesit dhe ekspozimi i parasë'],
 sales:['Sales','Shitjet','/customers','Orders, delivery and customer follow-ups','Porositë, dorëzimi dhe ndjekja e klientëve'],
 purchasing:['Purchasing','Blerjet','/procurement','Replenishment requests and supplier commitments','Kërkesat për furnizim dhe angazhimet ndaj furnitorëve'],
 warehouse:['Warehouse','Depoja','/warehouse-mobile','Receive, pick and count stock','Prano, përgatit dhe numëro stokun'],
 finance:['Accountant','Kontabilisti','/accounting','Posted books, differences and month close','Librat e postuar, diferencat dhe mbyllja mujore'],
 hr:['HR / Payroll','Personeli / Pagat','/workforce','Employment, time approvals and payroll preparation','Punësimi, miratimi i orëve dhe përgatitja e pagave'],
}
export default function DailyWorkspace({onProfileChange}){
 const {language}=useTranslation(),role=useAuthStore(s=>s.role),permissions=useAuthStore(s=>s.permissions),caps=useAuthStore(s=>s.user?.commercial?.capabilities),[profile,setProfile]=useSessionState('daily.profile',role==='admin'?'owner':'sales'),t=(en,sq)=>language==='sq'?sq:en
 const available=Object.entries(profiles).filter(([,p])=>canOpenPage(p[2],permissions,caps)),selected=available.find(([id])=>id===profile)||available[0]
 useEffect(()=>{onProfileChange?.(selected?.[0]||null)},[selected?.[0],onProfileChange])
 if(!selected)return null
 const [,p]=selected
 return <section className="dc9-daily-work" aria-label={t('Daily workspace','Hapësira ditore')}><label>{t('My work','Puna ime')}<select value={selected[0]} onChange={e=>setProfile(e.target.value)}>{available.map(([id,p])=><option key={id} value={id}>{t(p[0],p[1])}</option>)}</select></label><div><strong>{t(p[3],p[4])}</strong><p>{t('Review the current state, then work through assigned actions.','Shqyrto gjendjen aktuale, pastaj veprimet e caktuara.')}</p></div><Link className="secondary" to={p[2]}>{t('Open workspace','Hap hapësirën')}</Link>{canOpenPage('/action-center',permissions,caps)&&<Link to="/action-center?view=mine">{t('My attention queue','Radha ime e veprimeve')}</Link>}</section>
}
