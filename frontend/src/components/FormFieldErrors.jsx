import {useEffect,useId,useState} from 'react'
import {createPortal} from 'react-dom'

// Server validation stays authoritative. Field errors are attached to existing labelled controls.
export default function FormFieldErrors({formRef,errors}) {
 const prefix=useId(),[targets,setTargets]=useState([])
 useEffect(()=>{
  const form=formRef.current;if(!form){setTargets([]);return}
  const controls=Array.from(form.elements||[]),items=[]
  for(const [name,messages] of Object.entries(errors||{})) {
   const input=controls.find(control=>control.name===name),label=input?.closest('label');if(!label)continue
   const id=`${prefix}-${items.length}`,previous=input.getAttribute('aria-describedby')
   input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',[previous,id].filter(Boolean).join(' '))
   const detail=input.closest('details');if(detail)detail.open=true
   items.push({name,message:Array.isArray(messages)?messages[0]:messages,input,label,id,previous})
  }
  setTargets(items);items[0]?.input.focus({preventScroll:false})
  const clear=event=>{const item=items.find(i=>i.input===event.target);if(item){item.input.removeAttribute('aria-invalid');item.previous?item.input.setAttribute('aria-describedby',item.previous):item.input.removeAttribute('aria-describedby');setTargets(rows=>rows.filter(i=>i.name!==item.name))}}
  form.addEventListener('input',clear)
  return()=>{form.removeEventListener('input',clear);for(const item of items){item.input.removeAttribute('aria-invalid');item.previous?item.input.setAttribute('aria-describedby',item.previous):item.input.removeAttribute('aria-describedby')}}
 },[errors,formRef,prefix])
 return targets.map(item=>createPortal(<span className="field-validation" id={item.id} role="alert">{item.message}</span>,item.label,item.name))
}
