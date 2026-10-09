import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { useDialog } from '../hooks/useDialog'
import { useTranslation } from '../hooks/useTranslation'
export default function WorkspaceModal({title,onClose,busy=false,children}) {
  const ref=useDialog(onClose,busy),{language}=useTranslation()
  return createPortal(<div className="workspace-modal-overlay"><section ref={ref} className="workspace-modal" role="dialog" aria-modal="true" aria-label={title} tabIndex={-1}><header><h2>{title}</h2><button type="button" disabled={busy} onClick={onClose} aria-label={language==='sq'?'Mbyll':'Close'}><X size={20}/></button></header>{children}</section></div>,document.body)
}
