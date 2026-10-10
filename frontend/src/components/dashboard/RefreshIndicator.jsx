import {useEffect,useState,useSyncExternalStore} from 'react'
import {refreshHealth,refreshState} from '../../lib/refreshHealth'
export default function RefreshIndicator({resources,t,label}){
  const health=useSyncExternalStore(refreshHealth.subscribe,refreshHealth.state)
  const [now,setNow]=useState(Date.now()),[visible,setVisible]=useState(!document.hidden),[dataHealthy,setDataHealthy]=useState(true)
  useEffect(()=>{
    const update=()=>{setVisible(!document.hidden);setNow(Date.now())}
    const timer=setInterval(update,15000)
    document.addEventListener('visibilitychange',update);window.addEventListener('online',update);window.addEventListener('offline',update)
    const unsubscribe=resources.subscribeHealth(setDataHealthy)
    return()=>{clearInterval(timer);unsubscribe();document.removeEventListener('visibilitychange',update);window.removeEventListener('online',update);window.removeEventListener('offline',update)}
  },[resources])
  const state=refreshState(health,visible,navigator.onLine,now,dataHealthy)
  const hint=state==='healthy'?t('Automatic refresh is active. Data updates quietly every 15 seconds and after relevant system events.','Përditësimi automatik është aktiv. Të dhënat përditësohen në heshtje çdo 15 sekonda dhe pas ngjarjeve përkatëse.'):state==='paused'?t('Automatic refresh will resume when this workspace becomes active.','Përditësimi automatik vazhdon kur kjo hapësirë aktivizohet.'):state==='warning'?t('Automatic refresh has failed or the connection is unavailable. Existing data is kept; updates retry automatically.','Përditësimi ka dështuar ose lidhja mungon. Të dhënat ruhen; përditësimet riprovohen automatikisht.'):t('Checking automatic refresh connection…','Duke kontrolluar lidhjen e përditësimit…')
  return <span className="dashboard-live-chip" data-refresh-state={state} tabIndex={0} role="status" title={hint} aria-label={`${label}. ${hint}`}><i className={`refresh-health-dot ${state}`} aria-hidden="true"/>{label}</span>
}
