import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'

export default function OrdersOverview(){
  const allowed=useAuthStore(s=>s.permissions.includes('fulfillment.view')),{language}=useTranslation(),[data,setData]=useState(null)
  useEffect(()=>{if(!allowed)return;let live=true;const load=()=>apiRequest('/order-hub/overview').then(r=>{if(live)setData(r)}).catch(()=>{});load();window.addEventListener('database-refresh',load);return()=>{live=false;window.removeEventListener('database-refresh',load)}},[allowed])
  if(!allowed||!data)return null
  return <section className="card" style={{margin:'1rem 0',padding:'1rem'}}><h2>{language==='sq'?'Porositë':'Orders'}</h2><div style={{display:'flex',gap:'1rem',flexWrap:'wrap'}}>{[['new','New','Të reja'],['attention','Needs attention','Kërkojnë vëmendje'],['ready_to_allocate','Ready to fulfill','Gati për përmbushje'],['packed','Ready to dispatch','Gati për nisje'],['late','Late','Vonuar']].map(([view,en,sq])=><Link key={view} to={`/order-hub?view=${view}`} style={{display:'flex',gap:'.5rem',alignItems:'center',padding:'.75rem',border:'1px solid var(--border-color,#64748b)',borderRadius:10,color:'inherit'}}><strong>{data[view]}</strong>{language==='sq'?sq:en}</Link>)}</div></section>
}
