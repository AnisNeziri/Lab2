import { useLayoutEffect } from 'react'

const historyGuards=[]
let restoring=false
const protectHistory=e=>{
  if(restoring){restoring=false;return}
  const guard=historyGuards.at(-1)
  if(!guard)return
  const next=new URL(location.href),nextIndex=e.state?.idx
  if(next.pathname===guard.url.pathname&&next.search===guard.url.search)return
  if(!Number.isInteger(guard.index)||!Number.isInteger(nextIndex)||nextIndex===guard.index)return
  if(!window.confirm(guard.message)){
    e.stopImmediatePropagation();restoring=true;window.history.go(guard.index-nextIndex)
  }
}
// Installed before BrowserRouter so its listener cannot unmount a dirty form
// before the user decides. Only active editors register a guard.
window.addEventListener('popstate',protectHistory,true)
if(import.meta.hot)import.meta.hot.dispose(()=>window.removeEventListener('popstate',protectHistory,true))

export function useUnsavedNavigation(dirty,message) {
  useLayoutEffect(()=>{
    if(!dirty)return
    const unload=e=>{e.preventDefault();e.returnValue=''}
    const guard={index:window.history.state?.idx,url:new URL(location.href),message}
    historyGuards.push(guard)
    const click=e=>{
      const link=e.target.closest?.('a[href]')
      if(!link||link.target==='_blank'||link.hasAttribute('download')||link.href===location.href)return
      const next=new URL(link.href);if(next.pathname===location.pathname&&next.search===location.search)return
      if(!window.confirm(message)){e.preventDefault();e.stopPropagation()}
    }
    window.addEventListener('beforeunload',unload)
    const navigation=e=>{if(!window.confirm(message))e.preventDefault()}
    window.addEventListener('aims:before-navigate',navigation)
    document.addEventListener('click',click,true)
    return()=>{historyGuards.splice(historyGuards.indexOf(guard),1);window.removeEventListener('beforeunload',unload);window.removeEventListener('aims:before-navigate',navigation);document.removeEventListener('click',click,true)}
  },[dirty,message])
}
