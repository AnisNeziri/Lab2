import AimsAssistant from '../components/AimsAssistant'
import PageHeader from '../components/PageHeader'
import { useTranslation } from '../hooks/useTranslation'

export default function IntelligenceAssistant() {
  const { language } = useTranslation()
  return <main className="assistant-workspace"><PageHeader title={language === 'sq' ? 'Asistenti inteligjent' : 'Intelligence Assistant'} description={language === 'sq' ? 'Pyet për të dhënat e kompanisë, kontrollo provat dhe shiko hapat e ardhshëm.' : 'Ask about company records, check the evidence and review next steps.'}/><AimsAssistant embedded/></main>
}
