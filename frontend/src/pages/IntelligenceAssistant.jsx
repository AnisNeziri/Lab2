import AimsAssistant from '../components/AimsAssistant'
import PageHeader from '../components/PageHeader'
import { useTranslation } from '../hooks/useTranslation'

export default function IntelligenceAssistant() {
  const { language } = useTranslation()
  return <main className="assistant-workspace"><PageHeader title={language === 'sq' ? 'Pyet AIMS' : 'Ask AIMS'}/><AimsAssistant embedded/></main>
}
