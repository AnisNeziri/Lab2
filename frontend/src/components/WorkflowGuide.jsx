import { useTranslation } from '../hooks/useTranslation'
import { intelligenceWorkflows } from '../config/workspaceGuides'

export default function WorkflowGuide({ workflow }) {
  const { language } = useTranslation()
  const copy = intelligenceWorkflows[workflow]?.[language === 'sq' ? 'sq' : 'en']
  if (!copy) return null
  return <details className="intelligence-workflow" aria-label={language === 'sq' ? 'Si të fillosh' : 'Getting started'}>
    <summary>{language === 'sq' ? 'Si përdoret kjo faqe?' : 'How to use this page'}<span>{copy[0]}</span></summary>
    <ol>{copy.slice(1).map((step, index) => <li key={step}><span aria-hidden="true">{index + 1}</span><p>{step}</p></li>)}</ol>
    <details className="intelligence-glossary"><summary>{language === 'sq' ? 'Shpjegimi i termave' : 'Explain the terms'}</summary><dl>{[
      ['Forecast', 'Parashikimi', 'An estimate from recorded history, not a guaranteed sale.', 'Vlerësim nga historiku, jo shitje e garantuar.'],
      ['Available stock', 'Stoku në dispozicion', 'The server-calculated stock you may use; reservations and restrictions are already accounted for.', 'Stoku i llogaritur nga serveri; rezervimet dhe kufizimet janë marrë parasysh.'],
      ['Lead time', 'Afati i furnizimit', 'Time between ordering and receiving goods.', 'Koha ndërmjet porositjes dhe pranimit.'],
      ['Safety stock', 'Stoku i sigurisë', 'A buffer used in planning for demand and delivery uncertainty.', 'Rezervë planifikimi për pasigurinë e kërkesës dhe dorëzimit.'],
      ['MOQ', 'Sasia minimale e porosisë', 'The supplier’s minimum order quantity.', 'Sasia minimale që kërkon furnitori.'],
      ['Draft request', 'Draft kërkese', 'A proposal for review and approval, not an order or stock movement.', 'Propozim për rishikim dhe miratim, jo porosi apo lëvizje stoku.'],
    ].map(([en,sq,description,al]) => <div key={en}><dt>{language === 'sq' ? sq : en}</dt><dd>{language === 'sq' ? al : description}</dd></div>)}</dl></details>
  </details>
}
