import { useEffect } from 'react'
import { useLocation } from 'react-router-dom'

// Menus only: never close content disclosures or business forms implicitly.
const selector = 'details.widget-menu, details.hub-secondary-actions, details[data-lightweight-menu]'
export function useLightweightMenus() {
  const location=useLocation()
  useEffect(()=>{document.querySelectorAll(selector).forEach(menu=>{menu.open=false})},[location.key])
  useEffect(()=>{
    const close=(except,focus=false)=>document.querySelectorAll(selector).forEach(menu=>{
      if(menu.open&&menu!==except){menu.open=false;if(focus)menu.querySelector('summary')?.focus({preventScroll:true})}
    })
    const pointer=e=>close(e.target.closest(selector))
    const toggle=e=>{if(e.target.matches?.(selector)&&e.target.open){close(e.target);const panel=e.target.querySelector(':scope > div');if(panel){e.target.removeAttribute('data-placement');const rect=panel.getBoundingClientRect();if(rect.bottom>window.innerHeight-12)e.target.dataset.placement='above'}}}
    const key=e=>{if(e.key==='Escape'&&[...document.querySelectorAll(selector)].some(m=>m.open)){e.preventDefault();close(null,true)}}
    // Close after pointerup has identified the clicked target. Collapsing an
    // inline disclosure on pointerdown can move the link under the pointer and
    // swallow its click (especially the Orders warehouse navigation).
    document.addEventListener('click',pointer,true)
    document.addEventListener('toggle',toggle,true)
    document.addEventListener('keydown',key)
    return()=>{document.removeEventListener('click',pointer,true);document.removeEventListener('toggle',toggle,true);document.removeEventListener('keydown',key)}
  },[])
}
