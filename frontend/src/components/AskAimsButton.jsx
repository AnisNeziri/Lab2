import { MessageCircle } from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'
export function openAimsAssistant(entity=null,question=null){window.dispatchEvent(new CustomEvent('aims-assistant:open',{detail:{entity,question}}))}
export default function AskAimsButton({type,id,question,onBeforeOpen}){const {language}=useTranslation();return <button type="button" className="workspace-assistant-trigger" onClick={()=>{onBeforeOpen?.();openAimsAssistant({type,id},question||(language==='sq'?'Shpjego këtë regjistrim':'Explain this record'))}}><MessageCircle size={16}/>{language==='sq'?'Pyet AIMS për këtë':'Ask AIMS about this'}</button>}
