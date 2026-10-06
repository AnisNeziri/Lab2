import { navigationGroups, navigationContext } from '../config/navigation.js'
import { workspaceGuides, intelligenceWorkflows, pageSteps } from '../config/workspaceGuides.js'

export const normalizeQuestion = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()
const aliases = {
  'inventory-planning': ['replenishment', 'inventory planning', 'planifikim', 'sa te porosis'],
  'decision-center': ['decision center', 'qendra e vendimeve', 'alternatives'],
  'inventory-intelligence': ['forecast', 'parashikim', 'machine learning', 'model', 'intelligence'],
  'warehouse-operations': ['warehouse', 'transfer', 'depo', 'locator', 'lokacion'],
  'customer-debts': ['customer debt', 'credit limit', 'borxh', 'kredi', 'aging'],
  'daily-sales': ['daily sale', 'shitjet ditore', 'record sale', 'regjistro shitje'],
  'purchase-orders': ['purchase order', 'supplier order', 'porosi blerje', 'receive goods', 'prano mall'],
  'order-hub': ['customer order', 'order hub', 'porosi klient'],
  products: ['product', 'produkt', 'sku', 'barcode'],
  stock: ['stock movement', 'levizje stoku', 'stock adjustment'],
  suppliers: ['supplier', 'furnitor'],
  procurement: ['procurement', 'rfq', 'quote', 'prokurim', 'oferte'],
  'shipments/my-shipments': ['vessel', 'shipment', 'ais', 'anije', 'dergese'],
  finance: ['finance', 'financa'], invoices: ['invoice', 'fature'],
  'money-accounts': ['cash', 'bank', 'arke'], accounting: ['accounting', 'journal', 'kontabilitet'],
  'automation-studio': ['automation', 'automatizim'], 'action-center': ['task', 'approval', 'detyre', 'miratim'],
  quality: ['quality', 'quarantine', 'cilesi', 'karantine'],
  'operations-center': ['landed cost', 'batch', 'expiry', 'kosto', 'skadim'],
  reports: ['backup', 'restore', 'export', 'import', 'raport', 'report'],
}

export function assistantIntent(message) {
  const q = normalizeQuestion(message)
  if (/\b(delete|remove|create|save|approve|cancel|fshi|krijo|ruaj|mirato)\b/.test(q) && !/^(how|si|help|explain|shpjego)\b/.test(q)) return { kind: 'read_only' }
  const mode = /\b(movement|movements|levizje|levizjet)\b/.test(q) ? 'movements'
    : /^(forecast|parashikim|parashikimi)\s+(for|per|e|i)\b/.test(q) ? 'forecast'
    : /^(stock|stok|stoku|availability|available|where|ku|how much stock)\b/.test(q) ? 'stock' : 'record'
  const isGuide = /^(how (?!much stock)|help|explain|what is|what does|si |shpjego|ndihme|cfare eshte|si perdoret)/.test(q)
  if (isGuide) {
    const match = Object.entries(aliases).map(([id, terms]) => ({ id, score: Math.max(0, ...terms.filter(term => q.includes(term)).map(term => term.length)) })).sort((a,b) => b.score-a.score)[0]
    return { kind: 'guide', page: match?.score ? match.id : null }
  }
  const quoted = message.match(/["“]([^"”]+)["”]/)?.[1]
  const term = quoted || String(message).trim()
    .replace(/^(?:show\s+(?:me\s+)?|find\s+|search\s+|gjej\s+|kerko\s+|kërko\s+|what are\s+(?:the\s+)?|cilat jane\s+)?(?:recent\s+|latest\s+|last\s+)?(?:stock movements|movements|movement|lëvizjet|levizjet|lëvizje|levizje|how much stock do i have|stock|stoku|stok|availability|forecast|parashikimi|parashikim|where is|where are|ku është|ku eshte|ku jane)(?:\s+(?:for|of|about|per|për|e|i|the product|product|produktin|produkti))?\s*/i, '')
    .replace(/^(?:find|search|gjej|kërko|kerko)(?:\s+(?:for|product|produktin|produkti))?\s+/i, '')
    .replace(/[?!.]+$/, '').trim()
  return { kind: 'record', mode, term }
}

export function guideAnswer(page, language = 'en') {
  const sq = language === 'sq', context = navigationContext(page || '/dashboard')
  const id = context?.entry.id || 'dashboard', guide = workspaceGuides[id]
  const workflow = ({'inventory-intelligence':'forecasts','inventory-planning':'planning','decision-center':'decisions'})[id]
  return {
    text: guide?.[sq ? 1 : 0] || (sq ? 'Hap regjistrimin përkatës dhe rishiko të dhënat para konfirmimit të veprimit.' : 'Open the relevant record and review its data before confirming an operation.'),
    steps: workflow ? intelligenceWorkflows[workflow][sq ? 'sq' : 'en'].slice(1) : pageSteps[id]?.[sq ? 'sq' : 'en'] || [],
    links: [context?.entry.path, ...(guide?.[2] || []).map(id => navigationContext(id)?.entry.path)].filter(Boolean),
  }
}

export function recordCapability(group, mode) {
  if (group === 'products') return { name: mode === 'movements' ? 'get_stock_movements' : mode === 'forecast' ? 'get_demand_forecast' : 'get_product_availability', key: 'product_id' }
  if (mode !== 'record') return null
  return ({ customers: {name:'get_customer',key:'customer_id'}, suppliers: {name:'get_supplier',key:'supplier_id'}, purchase_orders: {name:'get_purchase_order',key:'purchase_order_id'}, shipments: {name:'get_shipment',key:'shipment_id'} })[group] || null
}

export function safeRecordPath(path) {
  if (typeof path !== 'string' || !path.startsWith('/') || path.startsWith('//') || path.includes('\\') || /[\u0000-\u001f]/.test(path)) return null
  const url = new URL(path, 'https://aims.local')
  const pages = navigationGroups.flatMap(group => group.items.map(item => item.path.split('?')[0]))
  return pages.includes(url.pathname) || /^\/control-tower\/\d+$/.test(url.pathname) ? url.pathname + url.search : null
}

export function recordAnswer(group, mode, data, language = 'en') {
  const sq = language === 'sq', t = (en,al) => sq ? al : en
  const n = value => value == null || value === '' || !Number.isFinite(Number(value)) ? '—' : new Intl.NumberFormat(sq ? 'sq-AL' : 'en-GB', {maximumFractionDigits:3}).format(Number(value))
  const facts = [], rows = []
  const add = (label, value) => { if (value !== undefined) facts.push([label, value == null || value === '' ? '—' : String(value)]) }
  let text = t('Facts read from the current company record.', 'Fakte të lexuara nga regjistrimi i kompanisë.')
  if (group === 'products' && mode === 'movements') {
    text = t('Latest recorded movements (up to 25). Quantities are shown in each movement’s recorded unit.', 'Lëvizjet e fundit (deri në 25). Sasitë shfaqen në njësinë e regjistruar të çdo lëvizjeje.')
    for (const m of Array.isArray(data) ? data : []) rows.push([m.occurred_at, m.movement_code || m.type, `${n(m.quantity)} ${m.unit || ''}`, m.reason || ''])
    if (!rows.length) text = t('No stock movements were recorded for this product.', 'Nuk ka lëvizje të regjistruara për këtë produkt.')
  } else if (group === 'products' && mode === 'forecast') {
    const p = data?.prediction, r = data?.current
    text = !p ? t('No usable forecast exists for this period. Record daily sales and review Forecast setup & accuracy.', 'Nuk ka parashikim të përdorshëm. Regjistro shitjet dhe rishiko Konfigurimi dhe saktësia.')
      : t('Advisory forecast for 30 days; not a promise or an automatic purchase.', 'Parashikim këshillues për 30 ditë; jo premtim apo blerje automatike.')
    if (p) {
      add(t('Forecast demand','Kërkesa e parashikuar'), `${n(p.value?.total)} ${data.product?.unit || ''}`)
      add(t('Suggested quantity','Sasia e sugjeruar'), r?.quantity == null ? '—' : `${n(r.quantity)} ${r.unit || ''}`)
      add(t('Expected shortage date','Data e mungesës së pritshme'), r?.stockout_date ?? null)
      add(t('Generated','Gjeneruar'), p.generated_at)
      add(t('Expires','Skadon'), p.valid_until)
      if (data.stale || new Date(p.valid_until).getTime() < Date.now()) text += ' ' + t('This forecast is stale; refresh it before acting.', 'Ky parashikim është i vjetruar; përditësoje para veprimit.')
    }
  } else if (group === 'products') {
    const p = data.product || {}, unit = p.unit || ''
    add(t('On hand','Në stok'), `${n(p.quantity)} ${unit}`)
    add(t('Available to sell','Në dispozicion për shitje'), `${n(p.available_quantity)} ${unit}`)
    add('SKU', p.sku)
    add(t('Category','Kategoria'), p.category?.name)
    add(t('Supplier','Furnitori'), p.supplier?.name)
    for (const w of data.warehouses || []) rows.push([w.warehouse?.name || '—', w.location?.path || w.location?.name || '—', `${n(w.on_hand)} ${unit}`, `${t('Available','Në dispozicion')}: ${n(w.available)} ${unit}`])
    if (!rows.length) text += ' ' + t('No warehouse allocation is recorded.', 'Nuk ka caktim të regjistruar sipas depove.')
  } else if (group === 'customers') {
    const c = data.credit || {}
    for (const [key,en,al] of [['current_debt','Debt','Borxhi'],['advance','Advance','Parapagimi'],['total_exposure','Exposure','Ekspozimi'],['overdue','Overdue','I vonuar'],['credit_limit','Credit limit','Limiti i kredisë'],['available_credit','Available credit','Kredia në dispozicion']]) add(t(en,al), key === 'credit_limit' && c[key] === null ? t('No limit','Pa limit') : n(c[key]))
    add(t('Payment terms (days)','Afati i pagesës (ditë)'), data.payment_terms_days)
    add(t('Oldest overdue','Vonesa më e vjetër'), c.oldest_overdue_date)
  } else if (group === 'purchase_orders') {
    add(t('Supplier','Furnitori'), data.supplier?.name)
    add(t('Status','Statusi'), data.status)
    add(t('Payment status','Statusi i pagesës'), data.payment_status)
    add(t('Total','Totali'), data.total_amount == null ? '—' : `${n(data.total_amount)} ${data.currency || ''}`)
    add(t('Remaining balance','Bilanci i mbetur'), data.remaining_balance == null ? '—' : `${n(data.remaining_balance)} ${data.currency || ''}`)
    add(t('Expected arrival','Mbërritja e pritshme'), data.expected_at)
  } else if (group === 'suppliers') {
    add(t('Email','Email'), data.email); add(t('Phone','Telefoni'), data.phone)
    add(t('Linked products','Produktet e lidhura'), data.products_count)
    add(t('Catalogue items','Artikujt në katalog'), data.catalogue_items_count)
  } else if (group === 'shipments') {
    const s = data.shipment || data
    add(t('Vessel','Anija'), s.vessel_name); add(t('Status','Statusi'), s.status)
    add(t('Expected arrival','Mbërritja e pritshme'), s.eta)
    add(t('Last position received','Pozicioni i fundit i marrë'), s.position_updated_at)
    text = t('Check the source record for signal freshness. Missing or old AIS data is not a live position.', 'Kontrollo burimin për freskinë e sinjalit. Të dhënat AIS të vjetra ose që mungojnë nuk janë pozicion live.')
  }
  return { text, facts, rows }
}
