import { createContext, useContext, useEffect, useRef, useState } from 'react'
import { apiRequest } from '../../api/client'
import { createDashboardResources as resources } from './dashboardResources.js'

// A dashboard shares reads by endpoint, caps concurrent requests and retains
// good data during background refresh. No financial values are calculated here.
export const createDashboardResources = (fetcher=apiRequest) => resources(fetcher)
export const DashboardDataContext=createContext(null)
export function useWidgetData(path) {
  const client=useContext(DashboardDataContext),ref=useRef(null),[state,setState]=useState({data:null,error:'',loading:Boolean(path)}),[visible,setVisible]=useState(false)
  useEffect(()=>{
    const element=ref.current
    if(!element||typeof IntersectionObserver==='undefined'){setVisible(true);return}
    const observer=new IntersectionObserver(([entry])=>setVisible(entry.isIntersecting),{rootMargin:'160px'})
    observer.observe(element);return()=>observer.disconnect()
  },[])
  useEffect(()=>{
    if(!path){setState({data:null,error:'',loading:false});return}
    if(!visible)return
    setState(client.state(path));const unsubscribe=client.subscribe(path,setState);client.load(path)
    return unsubscribe
  },[path,client,visible])
  return {...state,ref,retry:()=>path&&client.load(path,true)}
}
