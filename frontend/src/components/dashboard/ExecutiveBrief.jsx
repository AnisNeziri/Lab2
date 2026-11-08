import { DashboardGlyph as Sparkles, CustomersGlyph as Users, PurchasingGlyph as Truck, PaymentsGlyph as Wallet, ReportsGlyph as ChartNoAxesCombined, InventoryGlyph as Package, ShipmentsGlyph as Ship, AccountingGlyph as BookOpen, TasksGlyph as ClipboardList } from '../icons/AimsGlyph'
import { useState } from 'react'

import { Link } from 'react-router-dom'

import { ArrowRight, AlertCircle, RefreshCw } from 'lucide-react'

import { useWidgetData } from './DashboardData'

import { useTranslation } from '../../hooks/useTranslation'

import { useAuthStore } from '../../store/authStore'

import { attentionTitle, attentionDescription, taskTitle } from '../../lib/actionCenterPresentation'

import { shipmentIntelligenceLabel } from '../shipmentIntelligencePresentation'

import { businessMoney, businessDate } from '../../utils/businessFormat'

import './ExecutiveBrief.css'



const sections={performance:['Business performance','Ecuria e biznesit'],collections:['Money & collections','Paratë dhe arkëtimet'],inventory:['Inventory & incoming supply','Inventari dhe furnizimet në ardhje'],purchasing:['Purchasing & shipments','Blerjet dhe dërgesat'],accounting:['Accounting & compliance','Kontabiliteti dhe detyrimet'],people:['People & payroll','Personeli dhe pagat'],decisions:['Decisions & assigned work','Vendimet dhe detyrat']}

const impacts={customer:['Overdue collections can delay available cash.','Arkëtimet mund të vonojnë paratë në dispozicion. Shqyrto klientin dhe cakto ndjekjen.'],stock:['Review supply coverage before buying or promising delivery.','Shqyrto dëshmitë e stokut dhe furnizimit para premtimit të dorëzimit ose blerjes.'],shipment:['Review arrival evidence and exposed orders.','Koha e mbërritjes mund të ndikojë furnizimin. Shqyrto etapat e konfirmuara dhe produktet e prekura.'],finance:['Unresolved controls limit financial conclusions and month close.','Kontrollet e pazgjidhura kufizojnë përfundimet financiare dhe mbylljen mujore.'],payroll:['Payroll preparation or settlement requires an authorized review.','Përgatitja ose shlyerja e pagave kërkon shqyrtim të autorizuar.']}

const topicIcons={customer:Users,stock:Package,shipment:Ship,procurement:Truck,finance:BookOpen,task:ClipboardList}
const controlLabels={accounts_receivable:['Customer receivables','Borxhet e klientëve'],accounts_payable:['Supplier payables','Detyrimet ndaj furnitorëve'],inventory:['Recorded inventory value','Vlera e regjistruar e inventarit'],customer_advances:['Customer advances','Paradhëniet e klientëve'],supplier_advances:['Supplier advances','Paradhëniet ndaj furnitorëve'],cash_bank:['Cash / bank control','Kontrolli i arkës / bankës']}

const evidenceLabels={overdue:['Overdue','Me vonesë'],available:['Available','Në dispozicion'],reserved:['Reserved','Rezervuar'],incoming:['Incoming','Në ardhje'],minimum:['Minimum','Minimumi'],difference:['Difference','Diferenca'],count:['Records','Regjistrime'],risk:['Risk','Rreziku'],exposed_products:['Exposed products','Produkte të prekura'],recommended_quantity:['Suggested quantity','Sasia e sugjeruar'],unit:['Unit','Njësia'],observed_days:['Observed days','Ditë të vëzhguara'],qualified:['Evidence qualified','Dëshmitë e kualifikuara'],estimated_cost:['Estimated cost · not a commitment','Kostoja e vlerësuar · jo angazhim'],currency:['Currency','Valuta']}



export default function ExecutiveBrief({profile}) {

  const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en

  const [days,setDays]=useState(7),[expanded,setExpanded]=useState(false)

  const permissions=useAuthStore(s=>s.permissions)

  const candidate=profile==='owner'?'management':profile,required={management:'tasks.manage',sales:'debts.view',purchasing:'procurement.view',warehouse:'inventory.view',finance:'finance.view',hr:'employees.view'}

  const team=permissions.includes(required[candidate])?candidate:null

  const state=useWidgetData(profile?`/operating-brief?view=management${team?'&team='+encodeURIComponent(team):''}&days=${days}`:null)

  const d=state.data,financeFocus=['owner','finance','manager'].includes(profile),books=financeFocus?d?.books:null,ops=d?.operations

  const money=v=>v==null?'—':businessMoney(v,books?.currency||ops?.currency,language)

  const title=item=>item.kind==='task'?taskTitle(item,language):attentionTitle(item,language)

  const issue=(item,index)=>{const Icon=topicIcons[item.kind]||ClipboardList;return <article className="executive-issue" key={item.identity}>

    <div className="executive-issue-top"><span className="executive-rank">{index+1}</span><Icon size={15} strokeWidth={1.8} aria-hidden="true"/><span>{t(...(sections[Object.entries(d.sections).find(([,ids])=>ids.includes(item.identity))?.[0]]||sections.decisions))}</span>{['critical','urgent','high'].includes(item.severity)&&<strong>{t('Priority','Përparësi')}</strong>}</div>

    <h3>{title(item)}</h3><p>{item.kind==='task'?t('Assigned work — review the source and current evidence.','Detyrë e caktuar — shqyrto burimin dhe dëshmitë aktuale.'):item.kind==='shipment'?shipmentIntelligenceLabel(item.description,language):attentionDescription(item,language)}</p>

    <p className="executive-impact">{t(...(impacts[item.kind]||['Open the source to review the next step.','Shqyrto burimin për të kuptuar ndikimin dhe hapin e radhës.']))}</p>

    <details><summary>{t('Evidence & limitations','Dëshmitë dhe kufizimet')}</summary>

      <dl>{Object.entries(item.evidence||{}).filter(([k,v])=>evidenceLabels[k]&&v!=null&&typeof v!=='object').map(([k,v])=><div key={k}><dt>{t(...evidenceLabels[k])}</dt><dd>{typeof v==='boolean'?(v?t('Yes','Po'):t('No','Jo')):String(v)}</dd></div>)}{item.due_at&&<div><dt>{t('Due','Afati')}</dt><dd>{businessDate(item.due_at,language)}</dd></div>}</dl>

      {item.related?.length>1&&<ul>{item.related.map(r=><li key={r.id}>{attentionTitle({kind:item.kind,...r},language)}</li>)}</ul>}<p>{t('This is a source-linked review, not an instruction to commit. Predictions and recommendations must be checked in the original workflow; current state is revalidated there.','Ky është shqyrtim i lidhur me burimin. Parashikimet dhe rekomandimet duhen kontrolluar në rrjedhën origjinale; gjendja aktuale rivlerësohet atje.')}</p>

    </details>{item.task_url&&<Link to={item.task_url}>{t('Open assigned follow-up','Hap ndjekjen e caktuar')}</Link>}<Link className="executive-next" to={item.url}>{t('Review & decide','Shqyrto dhe vendos')}<ArrowRight size={16}/></Link>

  </article>}

  return <section ref={state.ref} className="executive-brief" aria-label={t('Executive Command Center','Qendra e drejtimit')}>

    <header className="executive-heading"><div><span className="executive-eyebrow"><Sparkles size={14} strokeWidth={1.8} aria-hidden="true"/>{t('Today in AIMS','Sot në AIMS')}</span><h2>{profile==='owner'?t('Your business, in focus','Biznesi juaj, në fokus'):t('Your next actions','Veprimet e tua të radhës')}</h2><p>{t('Current facts, decisions and recorded changes across your permitted workspace.','Faktet aktuale, vendimet dhe ndryshimet e regjistruara në hapësirën ku ke qasje.')}</p></div><label>{t('Review period','Periudha e shqyrtimit')}<select aria-label={t('Review period','Periudha e shqyrtimit')} value={days} onChange={e=>setDays(Number(e.target.value))}><option value={1}>{t('Today','Sot')}</option><option value={7}>{t('Last 7 days','7 ditët e fundit')}</option><option value={30}>{t('Last 30 days','30 ditët e fundit')}</option></select></label></header>

    {(state.error||d?.unavailable?.length>0)&&<div className="executive-warning" role="alert"><AlertCircle size={18}/><div><strong>{t('Some evidence is unavailable','Disa dëshmi nuk janë në dispozicion')}</strong><p>{t('This view cannot confirm current health. Retained figures may be stale; open the source before acting.','Kjo pamje nuk mund të konfirmojë gjendjen aktuale. Shifrat e ruajtura mund të jenë të vjetra; hap burimin para veprimit.')}</p><button type="button" className="secondary" onClick={state.retry}><RefreshCw size={15}/>{t('Try again','Provo përsëri')}</button></div></div>}

    {state.loading&&!d&&<p role="status">{t('Gathering permitted evidence…','Duke mbledhur dëshmitë e lejuara…')}</p>}

    {d&&<><p className="executive-asof">{t('Checked','Kontrolluar')} {businessDate(d.as_of,language)} · {d.period.from} — {d.period.to}{state.loading&&` · ${t('Refreshing…','Duke përditësuar…')}`} {books&&!books.values&&<Link className="executive-book-chip" to="/accounting?tab=reconciliation"><AlertCircle size={14} aria-hidden="true"/>{t('Financial position unverified','Gjendja financiare e paverifikuar')}</Link>}</p>

      <div className="executive-section-heading"><div><h2>{t('What needs your attention','Çfarë kërkon vëmendjen tënde')}</h2><p>{t('Declared severity, then due date. Open the evidence before deciding.','Sipas rëndësisë së deklaruar, pastaj afatit. Hap dëshmitë para vendimit.')}</p></div><Link to="/action-center">{t('All work','Të gjitha punët')}<ArrowRight size={16}/></Link></div>

      <div className="executive-attention">{(expanded?d.attention:d.attention.slice(0,3)).map(issue)}</div>

      {!d.attention.length&&<p className="executive-empty">{t('No items in this bounded review. This does not certify company readiness.','Nuk ka zëra në këtë shqyrtim të kufizuar. Kjo nuk certifikon gatishmërinë e kompanisë.')}</p>}

      {d.attention.length>3&&<button type="button" className="secondary executive-more" onClick={()=>setExpanded(!expanded)}>{expanded?t('Show top three','Shfaq tre kryesoret'):t(`Show all ${d.attention.length} review items`,`Shfaq të ${d.attention.length} zërat për shqyrtim`)}</button>}

      {['owner','sales','manager'].includes(profile)&&d.sales&&<div className="executive-sales"><div><span>{t('Recorded daily-sale activity','Aktiviteti i regjistruar i shitjeve ditore')}</span><strong>{businessMoney(d.sales.document_total,d.sales.currency,language)}</strong><small>{d.sales.count} {d.sales.count===1?t('document · selected period','dokument · periudha e zgjedhur'):t('documents · selected period','dokumente · periudha e zgjedhur')}</small></div><details><summary>{t('Source basis · not booked revenue','Baza burimore · jo të ardhura kontabël')}</summary><p>{t('Document totals, counted once. Excludes standalone invoices and later credits/returns; not booked revenue or profit.','Totalet e dokumenteve, të numëruara një herë. Përjashton faturat e pavarura dhe kreditimet/kthimet e mëvonshme; nuk janë të ardhura ose fitim kontabël.')} <Link to={d.sales.url}>{t('Open sales','Hap shitjet')}</Link></p></details></div>}

      {books&&<><div className="executive-facts">

        <Link to="/accounting?tab=ar"><span><Users size={16} aria-hidden="true"/>{t('Customers owe us','Borxhet e klientëve')}</span><strong>{money(ops?.rows.find(r=>r.control==='accounts_receivable')?.amount)}</strong><small>{t('Operational position · current','Gjendja operative · aktuale')}</small></Link>

        <Link to="/accounting?tab=ap"><span><Truck size={16} aria-hidden="true"/>{t('We owe suppliers','Detyrimet ndaj furnitorëve')}</span><strong>{money(ops?.rows.find(r=>r.control==='accounts_payable')?.amount)}</strong><small>{t('Operational position · advances separate','Gjendja operative · paradhëniet veçmas')}</small></Link>

        <Link to="/accounting?tab=bank"><span><Wallet size={16} aria-hidden="true"/>{t('Cash & bank','Arka dhe banka')}</span><strong>{books.values?money(d.books.working_capital?.cash_bank):'—'}</strong><small>{t(books.values?'Posted books':'Opening / reconciliation required',books.values?'Librat e postuar':'Kërkohet hapja / rakordimi')}</small></Link>

        <Link to="/accounting?tab=owner"><span><ChartNoAxesCombined size={16} aria-hidden="true"/>{t('Net result','Rezultati neto')}</span><strong>{books.values?money(books.values.net_profit):'—'}</strong><small>{t('Posted books · selected period','Librat e postuar · periudha e zgjedhur')}</small></Link>

      </div>{!books.values&&<p className="executive-book-limit"><AlertCircle size={16}/>{t('Financial conclusions are blocked: opening balances or control reconciliations are unverified. Operational balances above are provisional source records, not verified books.','Përfundimet financiare janë bllokuar: gjendjet fillestare ose rakordimet nuk janë verifikuar. Gjendjet operative më sipër janë regjistrime burimore provizore, jo libra të verifikuar.')} <Link to="/accounting?tab=reconciliation">{t('Review differences','Shqyrto diferencat')}</Link></p>}</>}

      <div className="executive-detail-grid">

        {books&&<details className="executive-detail"><summary>{t('Money, performance & financial limits','Paratë, ecuria dhe kufizimet financiare')}</summary><p>{t('Posted books','Librat e postuar')} · {books.currency} · {books.from} — {books.to}</p><dl>{[['revenue',t('Revenue','Të ardhurat')],['gross_profit',t('Gross profit','Fitimi bruto')],['expenses',t('Operating expenses','Shpenzimet operative')]].map(([key,label])=><div key={key}><dt>{label}</dt><dd>{money(books.values?.[key])}</dd></div>)}</dl><h3>{t('Current operational positions','Gjendjet operative aktuale')}</h3><dl>{ops?.rows.map(r=><div key={r.control}><dt><Link to={r.url}>{t(...(controlLabels[r.control]||[r.control,r.control]))}</Link></dt><dd>{money(r.amount)}{!r.reconciled&&' *'}</dd></div>)}</dl><p>{t('* Differs from GL. Advances remain separate; missing currency evidence is never converted into a base-currency balance.','* Ndryshon nga libri kryesor. Paradhëniet mbeten veçmas; mungesa e dëshmisë së valutës nuk shndërrohet në gjendje në valutën bazë.')}</p>

        {books.working_capital&&<p>{t('Working capital','Kapitali qarkullues')}: {money(books.working_capital.total)} · {t('Cash + AR + inventory − AP','Arka + borxhet e klientëve + inventari − detyrimet ndaj furnitorëve')}</p>}

        {d.forecast&&<><h3>{t('Forecast · advisory timing','Parashikimi · kohë këshilluese')}</h3><p>{d.forecast.state==='qualified'?t('Qualified snapshot · 30 days. Collections are predicted; scheduled outflows reflect recorded commitments.','Pamje e kualifikuar · 30 ditë. Arkëtimet janë parashikime; daljet e planifikuara pasqyrojnë angazhimet e regjistruara.'):t('Unavailable for financial decisions: opening, freshness or evidence is incomplete.','Jo në dispozicion për vendime financiare: hapja, freskia ose dëshmitë janë të paplota.')}</p>{d.forecast.values&&<dl>{Object.entries(d.forecast.values).map(([k,v])=><div key={k}><dt>{t(...({expected_collections:['Expected collections','Arkëtime të pritshme'],scheduled_outflows:['Scheduled outflows','Dalje të planifikuara'],expected_closing_cash:['Expected closing cash','Arka e pritshme në fund']}[k]))}</dt><dd>{money(v)}</dd></div>)}</dl>}<Link to={d.forecast.url}>{t('Review forecast evidence','Shqyrto dëshmitë e parashikimit')}</Link></>}</details>}

        <details className="executive-detail"><summary>{t('What changed?','Çfarë ka ndryshuar?')}</summary><p>{t('Recorded events in the selected period. No past balance is reconstructed; this is a bounded event preview.','Ngjarjet e regjistruara në periudhën e zgjedhur. Nuk rindërtohet asnjë gjendje e kaluar; kjo është pamje e kufizuar e ngjarjeve.')}</p>{d.changes?.rows?.length?<ul className="executive-changes">{d.changes.rows.map(r=><li key={r.id}><Link to={r.url}>{language==='sq'?r.label_sq:r.label}</Link><span>{r.reference} · {businessDate(r.at,language)}</span></li>)}</ul>:<p>{t('No permitted recorded events found. Historical comparisons are unavailable.','Nuk u gjetën ngjarje të regjistruara ku ke qasje. Krahasimet historike nuk janë në dispozicion.')}</p>}</details>

        {['owner','hr','manager'].includes(profile)&&(d.readiness||d.payroll)&&<details className="executive-detail"><summary>{t('Readiness & people','Gatishmëria dhe personeli')}</summary>{d.payroll&&<p>{t('Payroll preparation','Përgatitja e pagave')}: <Link to={d.payroll.url}>{t('Open payroll review','Hap shqyrtimin e pagave')}</Link> · {d.payroll.missing_terms} {t('missing employment terms','kushte punësimi që mungojnë')}</p>}{d.readiness&&<p><strong>{d.readiness.status==='READY'?t('Pilot evidence available','Dëshmitë e pilotit në dispozicion'):t('Live-company pilot blocked','Piloti me kompaninë reale është bllokuar')}</strong><br/>{t('SQL recovery, encrypted installation restore, accountant approval and commissioning are separate requirements.','Rikuperimi SQL, rikthimi i instalimit të enkriptuar, miratimi i kontabilistit dhe vënia në punë janë kërkesa të veçanta.')} <Link to={d.readiness.url}>{t('Review readiness evidence','Shqyrto dëshmitë e gatishmërisë')}</Link></p>}</details>}

      </div>

    </>}

  </section>

}

