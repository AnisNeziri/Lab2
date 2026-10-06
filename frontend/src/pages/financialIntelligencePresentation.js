export const financialTabs = ['overview', 'cash', 'receivables', 'commitments', 'inventory', 'scenarios', 'health']
export function financialMoney(value, currency = 'EUR', language = 'en') {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  return Number.isFinite(number) ? new Intl.NumberFormat(language === 'sq' ? 'sq-AL' : 'en-GB', { style: 'currency', currency }).format(number) : '—'
}
export function scenarioDifference(result, currency) {
  const base = result?.base?.currencies?.[currency]?.net_change
  const scenario = result?.scenario?.currencies?.[currency]?.net_change
  return base == null || scenario == null ? null : (Math.round(Number(scenario) * 100) - Math.round(Number(base) * 100)) / 100
}
export function evidenceLabel(type, language = 'en') {
  const labels = { observed: ['Recorded', 'Regjistruar'], scheduled: ['Scheduled', 'Planifikuar'], predicted: ['Predicted timing', 'Kohë e parashikuar'], scenario: ['Scenario', 'Skenar'] }
  return labels[type]?.[language === 'sq' ? 1 : 0] || type
}
const assumptionTranslations = {
  'Combined V4 coverage uses both hypothetical receipt dates. Supplier split permission is documented; actual freight economics and delivery agreement still require human review.': 'Mbulimi V4 përdor të dyja datat hipotetike të mbërritjes. Leja e furnitorit është e dokumentuar; kostot e transportit dhe marrëveshja ende kërkojnë shqyrtim njerëzor.',
  'Demand changes affect V4 coverage only. Uncontracted future sales are not invented as cash inflows.': 'Ndryshimet e kërkesës ndikojnë vetëm mbulimin V4. Shitjet e ardhshme pa kontratë nuk shpiken si hyrje parash.',
  'Supplier payment timing change is hypothetical, not approved renegotiation.': 'Ndryshimi i afatit të pagesës së furnitorit është hipotetik, jo rinegocim i miratuar.',
  'Arrival delay shifts only documented arrival-dependent obligations with an existing warehouse ETA.': 'Vonesa e mbërritjes ndryshon vetëm detyrimet e dokumentuara të lidhura me mbërritjen dhe me afat ekzistues të depos.',
  'Additional purchase is incremental; it does not replace an existing PO/payable. Costs with no recorded price are not invented.': 'Blerja shtesë nuk zëvendëson një porosi ose detyrim ekzistues. Kostot pa çmim të regjistruar nuk shpiken.',
}
export function financialAssumption(value, language = 'en') {
  return language === 'sq' ? assumptionTranslations[value] || value : value
}
export const financialCopy = {
  local_model_unavailable: ['Local Python diagnostic unavailable. Deterministic due dates and local historical statistics remain usable.', 'Diagnostikimi lokal Python nuk është i disponueshëm. Afatet e regjistruara dhe statistikat lokale mbeten të përdorshme.'],
  inventory_unvalued: ['Some products have no recorded valuation. Inventory totals are the known-valued subtotal only.', 'Disa produkte nuk kanë vlerësim të regjistruar. Totali i inventarit përfshin vetëm vlerat e njohura.'], high: ['High', 'E lartë'], moderate: ['Moderate', 'Mesatare'], limited: ['Limited', 'E kufizuar'],
  overview: ['Overview', 'Përmbledhje'], cash: ['Cash forecast', 'Parashikimi i parasë'], receivables: ['Customer collections', 'Arkëtimet nga klientët'], commitments: ['Supplier commitments', 'Detyrimet ndaj furnitorëve'], inventory: ['Inventory capital', 'Kapitali në inventar'], scenarios: ['Compare scenarios', 'Krahaso skenarët'], health: ['Data & model health', 'Të dhënat dhe modeli'],
  ON_TRACK: ['On track', 'Në kohë'], WATCH: ['Watch', 'Vëzhgo'], ELEVATED: ['Elevated timing risk', 'Rrezik i shtuar i afatit'], HIGH_RISK: ['High predicted timing risk', 'Rrezik i lartë i parashikuar i afatit'], OVERDUE: ['Overdue — recorded fact', 'Me vonesë — fakt i regjistruar'], INSUFFICIENT_DATA: ['Insufficient history', 'Histori e pamjaftueshme'],
  consistent_payer: ['Consistent payment timing', 'Afate të qëndrueshme'], frequently_late: ['Frequently late payments', 'Pagesa shpesh me vonesë'], variable_payer: ['Variable payment timing', 'Afate të ndryshueshme'], insufficient_history: ['Insufficient payment history', 'Histori e pamjaftueshme e pagesave'],
  customer_ledger: ['Customer ledger', 'Libri i klientit'], invoice: ['Invoice', 'Faturë'], supplier_payable: ['Supplier document / expense', 'Dokument furnitori / shpenzim'], purchase_order: ['Uninvoiced PO commitment', 'Pjesa e pafaturuar e porosisë'], recommendation: ['Recommendation — not a payable', 'Rekomandim — jo detyrim pagese'], purchase_request: ['Purchase Request — not a payable', 'Kërkesë blerjeje — jo detyrim pagese'],
  cash_incomplete: ['Complete cash position unavailable. Only registered cash/bank accounts are included.', 'Pozicioni i plotë i parasë nuk është i disponueshëm. Përfshihen vetëm llogaritë e regjistruara.'],
  receivables_undated: ['Some outstanding collections have no reliable future date; they are excluded from the dated forecast.', 'Disa arkëtime nuk kanë datë të besueshme dhe përjashtohen nga parashikimi me data.'], commitments_undated: ['Some outstanding payments have no reliable date; they are excluded from the dated forecast.', 'Disa pagesa nuk kanë datë të besueshme dhe përjashtohen nga parashikimi me data.'], payment_history_sparse: ['Insufficient history for ML-supported prediction. Due-date baseline retained.', 'Histori e pamjaftueshme për parashikim me ML. Përdoret afati i pagesës.'], customer_balance_mismatch: ['Ledger allocation and customer summary differ. Review the source.', 'Shpërndarja në libër dhe përmbledhja e klientit ndryshojnë. Kontrollo burimin.'], po_invoice_currency_mismatch: ['Linked PO/document currencies differ. The unknown remainder is excluded to avoid double counting.', 'Monedhat e porosisë dhe dokumentit ndryshojnë. Pjesa e panjohur përjashtohet për të shmangur numërimin e dyfishtë.'], po_invoice_mismatch: ['Supplier documents exceed the PO. Documents remain authoritative.', 'Dokumentet e furnitorit tejkalojnë porosinë. Dokumentet mbeten burimi autoritativ.'],
}
