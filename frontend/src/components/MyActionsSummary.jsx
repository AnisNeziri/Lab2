import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'
export default function MyActionsSummary(){
  const allowed=useAuthStore(s=>s.permissions.includes('tasks.view')),{language}=useTranslation(),[data,setData]=useState(null)
  useEffect(()=>{if(!allowed)return;let alive=true;const refresh=()=>apiRequest('/action-center?view=mine&summary_only=1').then(r=>alive&&setData(r.summary)).catch(()=>{});refresh();window.addEventListener('database-refresh',refresh);return()=>{alive=false;window.removeEventListener('database-refresh',refresh)}},[allowed])
  if(!allowed || !data)return null
  return <section style={{padding:'1rem',background:'var(--card-bg)',border:'1px solid var(--border-color)',borderRadius:12,display:'flex',flexWrap:'wrap',gap:'1.5rem',color:'var(--text-color)'}} aria-label={language==='sq'?'Veprimet e mia':'My actions'}><Link to="/action-center?view=mine">{language==='sq'?'Detyrat e mia':'My tasks'}: <strong>{data.tasks}</strong></Link><Link to="/action-center">{language==='sq'?'Miratime në pritje':'Approvals waiting'}: <strong>{data.approvals}</strong></Link><Link to="/action-center">{language==='sq'?'Detyra prioritare në pamje':'Priority tasks in preview'}: <strong>{data.issues}</strong></Link></section>
}
