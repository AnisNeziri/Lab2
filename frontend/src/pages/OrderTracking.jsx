import { useEffect,useState } from 'react'
import { useParams } from 'react-router-dom'
import { apiRequest } from '../api/client'
import { useSettingsStore } from '../store/settingsStore'
import './Fulfillment.css'
export default function OrderTracking(){
  const {token}=useParams(),[order,setOrder]=useState(null),[error,setError]=useState(''),language=useSettingsStore(s=>s.language)
  const t=(en,sq)=>language==='sq'?sq:en
  const statuses={draft:t('Under review','Në shqyrtim'),received:t('Received','Pranuar'),confirmed:t('Confirmed','Konfirmuar'),cancelled:t('Cancelled','Anuluar'),planned:t('Preparing','Në përgatitje'),in_transit:t('In transit','Në transit'),delivered:t('Delivered','Dorëzuar'),failed:t('Delivery needs attention','Dorëzimi kërkon vëmendje')}
  useEffect(()=>{let active=true;const load=()=>apiRequest(`/order-api/v1/tracking/${encodeURIComponent(token)}`).then(x=>{if(active)setOrder(x)}).catch(()=>{if(active)setError(t('This tracking link is invalid or expired.','Kjo lidhje gjurmimi është e pavlefshme ose ka skaduar.'))});load();const timer=setInterval(()=>{if(document.visibilityState==='visible')load()},60000);return()=>{active=false;clearInterval(timer)}},[token,language])
  return <main className="fulfillment-page"><header className="fulfillment-heading"><h1>AIMS · {t('Order tracking','Gjurmimi i porosisë')}</h1></header>{error?<p role="alert">{error}</p>:order?<section className="fulfillment-panel"><h2>{order.reference}</h2><strong>{statuses[order.states.delivery]||statuses[order.states.order]||t('Preparing','Në përgatitje')}</strong><p>{order.total} {order.currency}</p><div className="fulfillment-table"><table><thead><tr><th>{t('Product','Produkti')}</th><th>{t('Quantity','Sasia')}</th></tr></thead><tbody>{order.items?.map((i,n)=><tr key={n}><td>{i.name}</td><td>{i.quantity} {i.unit}</td></tr>)}</tbody></table></div>{order.deliveries?.map(d=><p key={d.reference}>{d.reference} · {statuses[d.status]||t('In transit','Në transit')}</p>)}</section>:<p role="status">{t('Loading…','Duke ngarkuar…')}</p>}</main>
}
