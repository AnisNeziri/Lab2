export const simulationPermissions = ['analytics.view', 'analytics.finance', 'inventory.view', 'procurement.view', 'finance.view', 'financial_accounts.view', 'customers.manage', 'daily_sales.manage', 'shipments.view']
export const simulationHorizons = [30, 60, 90, 180, 365]

const action = (type, name, en, sq, field = 'value', min = 0, max = 365, required = [], optional = []) => ({ key: `${type}.${name}`, type, action: name, en, sq, field, min, max, required, optional })
export const simulationActions = [
  action('demand', 'percent', 'Demand change', 'Ndryshimi i kërkesës', 'value', -100, 500, [], ['product_id', 'category_id']),
  action('supplier', 'unavailable', 'Supplier unavailable', 'Furnitori i padisponueshëm', 'days', 1, 365, ['supplier_id']),
  action('supplier', 'lead_days', 'Supplier lead-time change', 'Ndryshimi i afatit të furnitorit', 'days', -365, 365, ['supplier_id']),
  action('supplier', 'price_percent', 'Supplier price change', 'Ndryshimi i çmimit të furnitorit', 'value', -100, 500, ['supplier_id']),
  action('supplier', 'moq', 'Minimum order quantity', 'Sasia minimale e porosisë', 'value', 0, 100000000, ['supplier_id'], ['product_id']),
  action('supplier', 'risk_percent', 'Supplier reliability change', 'Ndryshimi i besueshmërisë së furnitorit', 'value', -100, 100, ['supplier_id']),
  action('supplier', 'remove', 'Exclude supplier from products', 'Përjashto furnitorin nga produktet', null, 0, 0, ['supplier_id'], ['product_id']),
  action('logistics', 'delay', 'Shipment / route delay', 'Vonesa e dërgesës / rrugës', 'days', 0, 365, [], ['shipment_id', 'route_from', 'route_to']),
  action('inventory', 'safety_percent', 'Safety stock change', 'Ndryshimi i stokut të sigurisë', 'value', -100, 500, [], ['product_id', 'category_id']),
  action('inventory', 'service_level', 'Service target', 'Objektivi i shërbimit', 'value', .9, .99, [], ['product_id', 'category_id']),
  action('inventory', 'reduction_percent', 'Inventory reduction', 'Ulja e inventarit', 'value', 0, 100, [], ['product_id', 'category_id']),
  action('inventory', 'extra_cover_days', 'Additional inventory coverage', 'Mbulimi shtesë i inventarit', 'days', 0, 365, [], ['product_id', 'category_id']),
  action('warehouse', 'unavailable', 'Warehouse unavailable', 'Depoja e padisponueshme', 'days', 1, 365, ['warehouse_id']),
  action('warehouse', 'rebalance', 'Hypothetical warehouse transfer', 'Transferim hipotetik mes depove', 'value', 0, 100000000, ['product_id', 'source_warehouse_id', 'warehouse_id']),
  action('procurement', 'commitment_limit', 'New purchasing commitment limit', 'Kufiri i angazhimit të blerjeve të reja', 'value', 0, 100000000),
  action('procurement', 'purchase_delay', 'New purchase delay', 'Vonesa e blerjeve të reja', 'days', 0, 365),
  action('procurement', 'replenishment_multiplier', 'Replenishment requirement multiplier', 'Shumëzuesi i nevojës së furnizimit', 'value', 0, 3),
  action('procurement', 'restrict_supplier', 'Restrict proposed supplier', 'Kufizo furnitorin e propozuar', null, 0, 0, ['supplier_id']),
  action('procurement', 'allow_split', 'Allow split purchasing', 'Lejo blerje të ndara', 'boolean'),
  action('financial', 'collections_delay', 'Customer collections delay', 'Vonesa e arkëtimeve nga klientët', 'days', 0, 365),
  action('financial', 'payments_advance', 'Earlier supplier payments', 'Pagesa më të hershme të furnitorëve', 'days', 0, 365),
  action('financial', 'expense_percent', 'Expense change', 'Ndryshimi i shpenzimeve', 'value', -100, 500),
  action('customer', 'percent', 'Customer demand change', 'Ndryshimi i kërkesës së klientit', 'value', -100, 500, ['customer_id'], ['product_id']),
  action('customer', 'inactive', 'Customer temporarily inactive', 'Klienti përkohësisht joaktiv', 'days', 1, 365, ['customer_id']),
  action('customer', 'additional_quantity', 'Additional customer demand', 'Kërkesa shtesë e klientit', 'value', 0, 100000000, ['customer_id', 'product_id'], ['warehouse_id']),
  action('customer', 'payment_delay', 'Selected customer payment delay', 'Vonesa e pagesës së klientit të zgjedhur', 'days', 0, 365, ['customer_id']),
]

const labels = {
  demand:['Demand','Kërkesa'], supplier:['Suppliers','Furnitorët'], logistics:['Logistics','Logjistika'], inventory:['Inventory policy','Politika e inventarit'], warehouse:['Warehouses','Depot'], procurement:['Purchasing','Blerjet'], financial:['Finance','Financat'], customer:['Customers','Klientët'],
  queued:['Queued','Në radhë'], pending:['Queued','Në radhë'], running:['Running','Në proces'], preparing_baseline:['Preparing baseline','Përgatitja e bazës'], applying_assumptions:['Applying assumptions','Zbatimi i supozimeve'], projecting_inventory:['Projecting inventory','Projektimi i inventarit'], optimizing_response:['Comparing supply responses','Krahasimi i përgjigjeve të furnizimit'], evaluating_finance:['Evaluating financial impact','Vlerësimi i ndikimit financiar'], completed:['Completed','Përfunduar'], failed:['Failed','Dështuar'], cancelled:['Cancelled','Anuluar'], cancellation_requested:['Cancellation requested','Është kërkuar anulimi'],
  balanced:['Balanced response','Përgjigje e balancuar'], service_first:['Service-first response','Përparësi shërbimit'], lower_commitment:['Lower-commitment response','Angazhim më i ulët'], do_nothing:['No response','Pa përgjigje'], optimal:['Best within tested options','Më e mira brenda opsioneve të testuara'], feasible:['Feasible response','Përgjigje e realizueshme'], infeasible:['No feasible response','Asnjë përgjigje e realizueshme'], time_limit:['Calculation limit reached','U arrit kufiri i llogaritjes'], unavailable:['Unavailable','E padisponueshme'], insufficient_data:['Insufficient evidence','Dëshmi të pamjaftueshme'], strong:['Strong','E fortë'], moderate:['Moderate','Mesatare'], low:['Low','E ulët'], limited:['Limited','E kufizuar'], high:['High','E lartë'], known:['Recorded facts','Fakte të regjistruara'], predicted:['Expected inputs','Të dhëna të pritshme'], assumed:['Scenario assumptions','Supozime skenari'], calculated:['Calculated consequences','Pasojat e llogaritura'],
  product_id:['Product','Produkti'], category_id:['Category','Kategoria'], supplier_id:['Supplier','Furnitori'], customer_id:['Customer','Klienti'], shipment_id:['Shipment','Dërgesa'], warehouse_id:['Destination / affected warehouse','Depoja e destinacionit / e prekur'], source_warehouse_id:['Source warehouse','Depoja burimore'], route_from:['Route origin','Origjina e rrugës'], route_to:['Route destination','Destinacioni i rrugës'],
  stockout_exposures:['Scopes with projected stockout','Shtrirje me mungesë të parashikuar'], below_target_scopes:['Scopes below target','Shtrirje nën objektiv'], unsupported_scopes:['Scopes with insufficient history','Shtrirje me historik të pamjaftueshëm'], scope_count:['Product / warehouse scopes','Shtrirje produkti / depoje'], purchase_requirement_by_currency:['Estimated purchasing requirement','Nevoja e vlerësuar për blerje'], inventory_value_by_currency:['Recorded inventory value','Vlera e regjistruar e inventarit'], required_inventory_capital_by_currency:['Required inventory capital','Kapitali i nevojshëm i inventarit'], commitment:['New purchasing commitment','Angazhimi i blerjeve të reja'], unresolved_scopes:['Unresolved target gaps','Mungesa të pazgjidhura ndaj objektivit'], transfer_lines:['Transfer suggestions','Transferime të sugjeruara'],
  invalid_assumption:['Review the assumption type, value and required selections.','Rishiko llojin, vlerën dhe përzgjedhjet e detyrueshme.'], invalid_definition:['Add a name and valid horizon; use up to 24 assumptions and 40 products.','Shto emër dhe periudhë të vlefshme; deri në 24 supozime dhe 40 produkte.'], invalid_sensitivity:['Choose between 2 and 15 distinct numeric values within the assumption bounds.','Zgjidh 2 deri në 15 vlera numerike të ndryshme brenda kufijve të supozimit.'],
  demand_surge:['Demand surge','Rritje e kërkesës'], demand_downturn:['Demand downturn','Ulje e kërkesës'], supplier_disruption:['Supplier disruption','Ndërprerje furnizimi'], logistics_delay:['Logistics delay','Vonesë logjistike'], customer_growth:['Customer growth','Rritje e klientit'], cash_pressure:['Cash pressure','Presion financiar'], inventory_reduction:['Inventory reduction','Ulje e inventarit'], combined_supply_shock:['Combined supply stress','Stres i kombinuar furnizimi'],
}
export function simulationLabel(key, language = 'en') {
  const normalized = String(key ?? '').toLowerCase().replace(/[- ]/g, '_')
  const found = labels[normalized]
  return found ? found[language === 'sq' ? 1 : 0] : language === 'sq' ? 'Detaj i regjistruar' : 'Recorded detail'
}
export function assumptionAction(assumption) { return simulationActions.find(a => a.type === assumption?.type && a.action === assumption?.action) }
export function simulationPending(status) { return ['queued','pending','running','cancellation_requested'].includes(String(status ?? '').toLowerCase()) }
const invalid = key => { const e = new Error(key); e.code = key; throw e }
const present = value => value !== '' && value !== undefined && value !== null
const selectors = ['product_id','category_id','supplier_id','customer_id','shipment_id','warehouse_id','source_warehouse_id']
export function serializeAssumption(input) {
  const spec = assumptionAction(input)
  if (!spec) invalid('invalid_assumption')
  const output = { type: spec.type, action: spec.action }
  for (const field of [...new Set([...spec.required, ...spec.optional])]) {
    if (spec.required.includes(field) && !present(input[field])) invalid('invalid_assumption')
    if (!present(input[field])) continue
    if (selectors.includes(field)) { const value = Number(input[field]); if (!Number.isSafeInteger(value) || value <= 0) invalid('invalid_assumption'); output[field] = value }
    else output[field] = String(input[field]).trim().slice(0, 120)
  }
  if (spec.field === 'boolean') output.value = input.value === true || input.value === 'true' || input.value === 1 ? 1 : 0
  else if (spec.field) {
    const value = Number(input[spec.field])
    if (!present(input[spec.field]) || !Number.isFinite(value) || value < spec.min || value > spec.max || (spec.field === 'days' && !Number.isInteger(value))) invalid('invalid_assumption')
    if (spec.action === 'service_level' && ![.9,.95,.98,.99].includes(value)) invalid('invalid_assumption')
    output[spec.field] = value
  }
  for (const key of ['start_day','end_day']) if (present(input[key])) { const value = Number(input[key]); if (!Number.isInteger(value) || value < 0 || value > 365) invalid('invalid_assumption'); output[key] = value }
  if (output.end_day != null && output.end_day < (output.start_day ?? 0)) invalid('invalid_assumption')
  if (!!output.route_from !== !!output.route_to || (output.shipment_id && output.route_from) || (output.source_warehouse_id && output.source_warehouse_id === output.warehouse_id)) invalid('invalid_assumption')
  return output
}
export function serializeDefinition(form) {
  if (!String(form.name ?? '').trim() || !simulationHorizons.includes(Number(form.horizon)) || !Array.isArray(form.assumptions) || form.assumptions.length > 24) invalid('invalid_definition')
  const scope = { allow_transfers: Boolean(form.scope?.allow_transfers) }
  for (const key of ['product_ids','warehouse_ids','supplier_ids','category_ids']) {
    const ids = [...new Set((form.scope?.[key] ?? []).map(Number))]
    if (ids.some(id => !Number.isSafeInteger(id) || id <= 0) || ids.length > ({product_ids:40,warehouse_ids:3,supplier_ids:6,category_ids:20}[key])) invalid('invalid_definition')
    scope[key] = ids
  }
  if (present(form.scope?.transfer_lead_days)) { const days = Number(form.scope.transfer_lead_days); if (!Number.isInteger(days) || days < 0 || days > 30) invalid('invalid_definition'); scope.transfer_lead_days = days }
  scope.long_horizon_mode = form.scope?.long_horizon_mode === 'repeat_pattern' ? 'repeat_pattern' : 'no_extension'
  return { name: String(form.name).trim().slice(0,150), description: String(form.description ?? '').slice(0,2000), horizon: Number(form.horizon), scope, assumptions: form.assumptions.map(serializeAssumption), optimize: Boolean(form.optimize) }
}
export function sensitivityValues(text, assumption) {
  const spec = assumptionAction(assumption)
  const values = [...new Set(String(text).split(/[;,\s]+/).filter(Boolean).map(Number))]
  if (!spec?.field || spec.field === 'boolean' || values.length < 2 || values.length > 15) invalid('invalid_sensitivity')
  try { values.forEach(value => serializeAssumption({ ...assumption, [spec.field]: value })) } catch { invalid('invalid_sensitivity') }
  return values
}
export function assumptionLabel(assumption, language = 'en', options = {}) {
  const spec = assumptionAction(assumption)
  if (!spec) return simulationLabel('invalid_assumption', language)
  const parts = [language === 'sq' ? spec.sq : spec.en]
  const collections = { product_id:'products', category_id:'categories', supplier_id:'suppliers', customer_id:'customers', shipment_id:'shipments', warehouse_id:'warehouses', source_warehouse_id:'warehouses' }
  Object.entries(collections).forEach(([key, collection]) => { if (assumption[key]) parts.push(options[collection]?.find(row => String(row.id) === String(assumption[key]))?.name ?? `#${assumption[key]}`) })
  if (spec.field === 'days') parts.push(`${Number(assumption.days) > 0 ? '+' : ''}${assumption.days} ${language === 'sq' ? 'ditë' : 'days'}`)
  else if (spec.field === 'boolean') parts.push(assumption.value ? language === 'sq' ? 'Po' : 'Yes' : language === 'sq' ? 'Jo' : 'No')
  else if (spec.field) parts.push(`${assumption.action === 'service_level' ? Number(assumption.value) * 100 : assumption.value}${assumption.action.includes('percent') || assumption.action === 'service_level' ? '%' : ''}`)
  if (assumption.route_from) parts.push(`${assumption.route_from} → ${assumption.route_to}`)
  return parts.join(' · ')
}
export function inventoryComparison(baselineRow, scenarioRow) {
  if (!baselineRow || !scenarioRow || baselineRow.unit !== scenarioRow.unit) return []
  const baseline = new Map((baselineRow.timeline ?? []).map(row => [row.date, row]))
  return (scenarioRow.timeline ?? []).map(row => ({ date: row.date, baseline: baseline.get(row.date)?.balance ?? null, scenario: row.balance ?? null, incoming: row.incoming ?? null, target: row.safety_stock ?? null }))
}
export function financeComparison(baseline, scenario, response) {
  const source = finance => finance?.currencies ?? finance?.forecast?.currencies ?? {}
  const b = source(baseline), s = source(scenario), r = source(response)
  return [...new Set([...Object.keys(b), ...Object.keys(s), ...Object.keys(r)])].map(currency => {
    const points = new Map()
    for (const [key, finance] of [['baseline',b],['scenario',s],['response',r]]) for (const row of finance[currency]?.timeline ?? []) { const point = points.get(row.date) ?? { date: row.date }; point[key] = row.expected_cash ?? null; points.set(row.date, point) }
    return { currency, points: [...points.values()].sort((a,b) => a.date.localeCompare(b.date)), reliability: s[currency]?.confidence ?? scenario?.confidence ?? 'limited' }
  })
}
export function formatSimulationMoney(value, currency, language = 'en') {
  if (value == null || !Number.isFinite(Number(value)) || !/^[A-Z]{3}$/.test(currency ?? '')) return '—'
  try { return new Intl.NumberFormat(language === 'sq' ? 'sq-AL' : 'en-GB', { style:'currency', currency }).format(Number(value)) } catch { return '—' }
}
export function canPrepareSimulationResponse(run, alternative) {
  return String(run?.status).toLowerCase() === 'completed' && ['OPTIMAL','FEASIBLE'].includes(String(alternative?.status).toUpperCase()) && Boolean(run?.permissions?.can_prepare_response)
}
