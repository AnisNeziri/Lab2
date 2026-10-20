import { useLocation } from 'react-router-dom'
import { MessageCircle, ChevronRight } from 'lucide-react'
import { navigationContext } from '../config/navigation'
import { useTranslation } from '../hooks/useTranslation'
import { openAimsAssistant } from './AskAimsButton'


export default function WorkspaceContext({ onHelp }) {
  const location = useLocation(), { t, language } = useTranslation()
  const context = navigationContext(location.pathname + location.search)
  if (!context) return null
  const { group, entry } = context
  const label = entry.label ? t(entry.label) : entry[language === 'sq' ? 'sq' : 'en']
  const params=new URLSearchParams(location.search)
  const record=[['decision','decision'],['product','product'],['customer','customer'],['shipment','shipment'],['task','task'],['po','purchase_order'],['supplier','supplier'],['warehouse','warehouse'],['order','sales_order']].find(([key])=>Number(params.get(key))>0)
  return <div className="workspace-context">
    <nav aria-label={language === 'sq' ? 'Vendndodhja' : 'Page location'}><span>{group[language === 'sq' ? 'sq' : 'en']}</span><ChevronRight size={14} aria-hidden="true"/><span aria-current="page">{label}</span></nav>
    <button type="button" className="workspace-assistant-trigger" onClick={()=>record?openAimsAssistant({type:record[1],id:Number(params.get(record[0]))},'Explain this record'):onHelp()}><MessageCircle size={16}/>{language === 'sq' ? 'Pyet asistentin AIMS' : 'Ask AIMS'}</button>
  </div>
}
