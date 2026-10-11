import { createPortal } from 'react-dom'
import { useState } from 'react'
import { X } from 'lucide-react'
import { useDialog } from '../hooks/useDialog'
import { useTranslation } from '../hooks/useTranslation'
export default function WorkspaceModal({title,onClose,busy=false,children,closeOnBackdrop=false,closeOnEscape=true,warnOnUnsaved=false,discardTitle}) {
  const [discard,setDiscard]=useState(false),{language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const close=()=>{if(busy)return;if(warnOnUnsaved)setDiscard(true);else onClose()}
  const ref=useDialog(close,busy,true,{closeOnEscape})
  return createPortal(<div className="workspace-modal-overlay" onClick={e=>{if(e.target===e.currentTarget&&closeOnBackdrop)close()}}><section ref={ref} className="workspace-modal" role="dialog" aria-modal="true" aria-label={title} tabIndex={-1}><header><h2>{title}</h2><button type="button" disabled={busy} onClick={close} aria-label={t('Close','Mbyll')}><X size={20}/></button></header>{children}</section>{discard&&<WorkspaceModal title={discardTitle||t('Discard unsaved changes?','Të hidhen poshtë ndryshimet e paruajtura?')} onClose={()=>setDiscard(false)} closeOnBackdrop={false}><p>{t('Your changes have not been saved.','Ndryshimet nuk janë ruajtur.')}</p><div className="workspace-actions"><button onClick={()=>setDiscard(false)}>{t('Keep Editing','Vazhdo redaktimin')}</button><button onClick={onClose}>{t('Discard','Hidh poshtë')}</button></div></WorkspaceModal>}</div>,document.body)
}
