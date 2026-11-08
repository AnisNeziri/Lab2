import { businessNumber } from '../utils/businessFormat.js'

const warehouseIssues={allocation_incomplete:['Not enough stock to reserve','Stok i pamjaftueshëm për rezervim'],short_pick:['Picking quantity short','Sasi e pamjaftueshme për mbledhje'],delivery_failed:['Delivery needs review','Dorëzimi kërkon rishikim']}

// Labels describe the failed control, rather than repeating its success criterion.
const financeIssues = {
 critical_exceptions:['Unresolved critical accounting exceptions','Përjashtime kritike kontabël të pazgjidhura'],
 company_links:['Financial records with inconsistent company ownership','Regjistrime financiare me lidhje të pasakta me kompaninë'],
 required_postings:['Finalized financial documents missing journals','Dokumente financiare të finalizuara pa regjistrim kontabël'],
 posted_balance:['Posted journals do not balance','Regjistrime kontabël të postuara të pabalancuara'],
 valid_lines:['Invalid posted journal lines','Rreshta të pavlefshëm në regjistrimet e postuara'],
 source_links:['Financial sources missing a traceable link','Burime financiare pa lidhje të gjurmueshme'],
 closed_period_postings:['Posting after final period close requires investigation','Postim pas mbylljes përfundimtare të periudhës për hetim'],
 restored_source_links:['Restored financial source links need review','Lidhjet e burimeve financiare të rikthyera kërkojnë rishikim'],
 bank_company_links:['Bank records with inconsistent company ownership','Regjistrime bankare me lidhje të pasakta me kompaninë'],
 bank_match_integrity:['Bank matching ownership or balance needs review','Pronësia ose balanca e rakordimeve bankare kërkon rishikim'],
 opening_not_verified:['Opening balances await verification','Gjendjet fillestare presin verifikim'],
 overdue_receivables:['Overdue customer balances need collection review','Borxhet e vonuara të klientëve kërkojnë ndjekje'],
 unbalanced_drafts:['Unbalanced draft journals','Regjistrime draft të pabalancuara'],
 stale_drafts:['Draft journals older than seven days','Regjistrime draft më të vjetra se shtatë ditë'],
 unreconciled_bank_items:['Unreconciled bank items','Zëra bankarë të parakorduar'],
 unreconciled_system_bank_items:['Bank activity awaiting a statement match','Lëvizje bankare në pritje të lidhjes me ekstraktin'],
 unreviewed_supplier_bills:['Supplier invoices need review','Faturat e furnitorëve kërkojnë rishikim'],
 unposted_financial_documents:['Unposted financial documents','Dokumente financiare të papostuara'],
 pending_landed_costs:['Landed costs awaiting posting','Kosto importi në pritje të postimit'],
 opening_issues:['Opening reconciliation is unfinished','Rakordimi i gjendjes fillestare është i papërfunduar'],
 failed_financial_jobs:['Financial posting needs recovery','Postimi financiar kërkon rikuperim'],
 closed_period_attempts:['Posting attempts blocked by a closed period','Tentativa postimi të bllokuara nga periudha e mbyllur'],
 vat_gl:['VAT source balances differ from tax accounts','Gjendjet burimore të TVSH-së ndryshojnë nga llogaritë tatimore'],
 tax_mapping:['Tax account mappings need review','Lidhjet me llogaritë tatimore kërkojnë rishikim'],
 tax_document_journal:['Document VAT differs from posted tax journals','TVSH-ja e dokumenteve ndryshon nga regjistrimet kontabël'],
 fiscal_evidence:['Fiscal receipts have incomplete evidence','Kuponë fiskalë me dëshmi të paplota'],
 fiscal_duplicate:['Possible duplicate fiscal receipts','Kuponë fiskalë të mundshëm të dyfishuar'],
 fiscal_unresolved:['Required TEST fiscalization needs operator review','Fiskalizimi i kërkuar TEST kërkon rishikim nga operatori'],
}
const controlNames = {
 accounts_receivable:['Customer receivables','Borxhet e klientëve'], customer_advances:['Customer advances','Paradhëniet e klientëve'],
 accounts_payable:['Supplier payables','Detyrimet ndaj furnitorëve'], supplier_advances:['Supplier advances','Paradhëniet ndaj furnitorëve'],
 inventory:['Inventory value','Vlera e inventarit'], cash_bank:['Cash and bank','Arka dhe banka'],
}
const financeTasks = {
 cash_pressure_detected:['Review cash pressure','Rishiko presionin mbi likuiditetin'],
 data_quality_issue:['Review financial data quality','Rishiko cilësinë e të dhënave financiare'],
 large_commitment_upcoming:['Review upcoming large commitments','Rishiko angazhimet e mëdha të ardhshme'],
 receivable_risk_changed:['Review changed receivable risk','Rishiko ndryshimin e rrezikut të arkëtimit'],
}
export function attentionTitle(item,language='en') {
 const sq=language==='sq',code=item.kind==='finance'?String(item.id).replace(/^dc2-/,''):''
 const warehouse=warehouseIssues[item.exception_type||item.title]
 if(warehouse)return warehouse[sq?1:0]
 if(financeIssues[code])return financeIssues[code][sq?1:0]
 const control=controlNames[code.replace(/^control_/,'')]
 if(code.startsWith('control_')&&control)return sq?`Diferencë me librin kryesor: ${control[1]}`:`${control[0]} differ from the General Ledger`
 return item.title
}
export function taskTitle(task,language='en') {
 if(task.source_type!=='FinancialIntelligenceSnapshot')return task.title
 const entry=Object.entries(financeTasks).find(([key])=>task.title===`Review financial intelligence: ${key.replaceAll('_',' ')}`)
 return entry?entry[1][language==='sq'?1:0]:task.title
}
export function taskDescription(task,language='en') {
 return language==='sq'&&task.source_type==='FinancialIntelligenceSnapshot'&&task.description==='Review current evidence, unknowns and source documents. Advisory only.'
  ?'Shqyrto dëshmitë aktuale, të panjohurat dhe dokumentet burimore. Vetëm këshillim.':task.description
}
export function attentionPreview(items=[],now=Date.now()) {
 const groups=new Map()
 for(const item of items){const key=JSON.stringify([item.kind,item.title,item.description,item.url]);const previous=groups.get(key);if(previous)previous.occurrences++;else groups.set(key,{...item,occurrences:1})}
 const priority=item=>String(item.severity||item.values?.risk).toUpperCase()==='CRITICAL'?0:item.overdue||(item.due_at&&new Date(item.due_at).getTime()<now)?1:item.kind==='approval'?2:3
 return [...groups.values()].sort((a,b)=>priority(a)-priority(b))
}
export function attentionDescription(item,language='en') {
 if((item.exception_type||item.title)==='allocation_incomplete')return language==='sq'?'Stoku i lejuar nuk mbulon porosinë. Shqyrtoni rezervimet, furnizimin në ardhje ose përmbushjen e pjesshme.':'Eligible stock does not cover the order. Review reservations, incoming supply or partial fulfillment.'
 if(item.kind==='finance'&&/^\d+ items require review$/.test(item.description)){
  const count=Number(item.description.split(' ')[0]),n=businessNumber(count,language,0)
  return language==='sq'?`${n} ${count===1?'regjistrim kërkon':'regjistrime kërkojnë'} rishikim`:`${n} ${count===1?'record requires':'records require'} review`
 }
 const labels={
  'Review opening source group':['Review grouped opening source rows','Shqyrto grupin e rreshtave burimorë të hapjes'],
  'Reconcile opening source totals':['Reconcile opening source totals','Rakordo totalet burimore të hapjes'],
  'Review incoming delivery risk':['Review incoming delivery risk','Rishiko rrezikun e furnizimit në ardhje'],
  'Review qualified candidate':['Review a qualified delivery forecast','Rishiko një parashikim të kualifikuar të dorëzimit'],
  'Review enterprise decision':['Review the recommended stock action','Rishiko veprimin e rekomanduar për stokun'],
  'Review warehouse transfer opportunity':['Review stock available in another warehouse','Rishiko stokun në dispozicion në një depo tjetër'],
  'Review inventory purchasing plan':['Review the suggested purchase plan','Rishiko planin e sugjeruar të blerjes'],
  'Review forecast performance and data quality':['Review forecast reliability','Rishiko besueshmërinë e parashikimit'],
  'Review forecast model candidate':['Review a new forecast option','Rishiko një alternativë të re parashikimi'],
  'Review forecast replenishment':['Review restocking needs','Rishiko nevojat për furnizim'],
  'Analytics data-quality problem':['Recorded information needs review','Informacioni i regjistruar kërkon rishikim'],
  'Purchase order overdue':['Purchase order arrival is overdue','Mbërritja e porosisë së blerjes është me vonesë'],
  'Review or replace expiring document':['Review or replace an expiring document','Rishiko ose zëvendëso dokumentin që po skadon'],
  'Customer payment overdue':['Customer payment is overdue','Pagesa e klientit është me vonesë'],
  'Quotation needs follow-up':['Quotation is expiring or expired — contact review needed','Oferta po skadon ose ka skaduar — kërkohet shqyrtim i ndjekjes'],
  'Review order':['Review this order before continuing','Rishiko këtë porosi para se të vazhdosh'],
 }
 return labels[item.description]?.[language==='sq'?1:0]||item.description
}
