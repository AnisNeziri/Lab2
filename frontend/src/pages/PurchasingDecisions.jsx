import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useSearchParams } from 'react-router-dom'
import { apiRequest } from '../api/client'
import PageHeader from '../components/PageHeader'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'
import { useRevealTarget } from '../hooks/useRevealTarget'
import { businessDate, businessMoney, businessNumber } from '../utils/businessFormat'
import PurchasePortfolio, { quoteDraft } from '../components/PurchasePortfolio'
import { readPurchasingDraft, storePurchasingDraft } from '../utils/purchasingDraft'
import './PurchasingDecisions.css'

const keys = ['product_id','warehouse_id','quantity','unit','priority','required_by','supplier_id','budget']
const fromParams = params => Object.fromEntries(keys.map(k => [k,params.get(k) || (k === 'priority' ? 'balanced' : '')]))
const budgetPermissions = ['analytics.finance','finance.view','financial_accounts.view','purchase_orders.view']
function planFromParams(params) {
  try {
    const lines = JSON.parse(params.get('plan') || '[]')
    return Array.isArray(lines) ? lines.slice(0,5).filter(line => Number.isInteger(line?.product_id) && line.product_id > 0 && Number(line.quantity) > 0) : []
  } catch { return [] }
}

export default function PurchasingDecisions() {
  const { language } = useTranslation()
  const reveal = useRevealTarget()
  const permissions = useAuthStore(s => s.permissions)
  const user = useAuthStore(s => s.user)
  const { hash } = useLocation()
  const [params] = useSearchParams()
  const [plan,setPlan] = useState([])
  const [planBudget,setPlanBudget] = useState('')
  const [planBuffer,setPlanBuffer] = useState('')
  const [draftScope,setDraftScope] = useState(null)
  const t = (en,sq) => language === 'sq' ? sq : en
  const [form,setForm] = useState(() => fromParams(params))
  const [term,setTerm] = useState('')
  const [products,setProducts] = useState([])
  const [warehouses,setWarehouses] = useState([])
  const [amounts,setAmounts] = useState({})
  const [data,setData] = useState(null)
  const [busy,setBusy] = useState(false)
  const [scenarioChanged,setScenarioChanged] = useState(false)
  const [error,setError] = useState('')
  const [scenarioError,setScenarioError] = useState('')
  const [lookupError,setLookupError] = useState(false)
  const [warehouseError,setWarehouseError] = useState(false)
  const request = useRef(null)
  const supplierEvidence = useRef(null)
  const canBudget = budgetPermissions.every(p => permissions.includes(p))
  const canManage = permissions.includes('procurement.manage')
  const planRequested = canBudget && hash === '#purchase-portfolio'
  const scope = `${user?.company_id}:${user?.id}:${canBudget}:${params.toString()}`
  useEffect(() => {
    const controller = new AbortController()
    const timer = setTimeout(() => apiRequest('/products?reference_only=1&per_page=30&search=' + encodeURIComponent(term),{signal:controller.signal})
      .then(r => { setProducts(r.data || []); setLookupError(false) })
      .catch(e => { if (e.name !== 'AbortError') setLookupError(true) }), term ? 220 : 0)
    return () => { clearTimeout(timer); controller.abort() }
  },[term])
  useEffect(() => {
    const controller = new AbortController()
    apiRequest('/warehouses',{signal:controller.signal}).then(r => setWarehouses((r.data || r).filter(w => w.is_active !== false)))
      .catch(e => { if (e.name !== 'AbortError') setWarehouseError(true) })
    return () => controller.abort()
  },[])
  async function compare(fields, quantities = {}, destination) {
    request.current?.abort()
    const controller = new AbortController()
    request.current = controller
    const reportError = destination === 'scenarios' ? setScenarioError : setError
    setBusy(true); setError(''); setScenarioError('')
    try {
      const payload = Object.fromEntries(Object.entries(fields).filter(([,value]) => value !== ''))
      if (!canBudget) delete payload.budget
      if (Object.keys(quantities).length) payload.scenario_quantities = quantities
      const result = await apiRequest('/procurement/purchase-comparison',{method:'POST',body:JSON.stringify(payload),signal:controller.signal})
      if (!controller.signal.aborted) {
        setData(result); setScenarioChanged(false)
        if (destination === 'suppliers') reveal('purchase-suppliers')
      }
    } catch(e) {
      if (!controller.signal.aborted) reportError(e.message)
    } finally { if (!controller.signal.aborted) setBusy(false) }
  }
  useEffect(() => {
    const saved = readPurchasingDraft(user)
    const initial = params.has('product_id') ? fromParams(params) : saved?.form || fromParams(params)
    if (!canBudget) initial.budget = ''
    setForm(initial); setAmounts({}); setData(null); setScenarioChanged(false); setBusy(false); setError(''); setScenarioError('')
    setTerm(saved?.term || '')
    setPlan(canBudget ? (params.has('plan') ? planFromParams(params) : saved?.plan || []) : [])
    setPlanBudget(canBudget ? params.get('budget') ?? saved?.budget ?? '' : '')
    setPlanBuffer(canBudget ? params.get('buffer') ?? saved?.buffer ?? '' : '')
    setDraftScope(scope)
    if (initial.product_id && initial.quantity) compare(initial)
    return () => request.current?.abort()
    // Read query parameters when a new navigation or Ask AIMS link is opened.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  },[scope])
  useEffect(() => {
    if (scope === draftScope) storePurchasingDraft(user,{form,term,plan,budget:planBudget,buffer:planBuffer})
  },[scope,draftScope,user,form,term,plan,planBudget,planBuffer])
  useEffect(() => {
    if (planRequested) reveal('portfolio-title')
  },[planRequested,reveal])
  const field = (name,value) => {
    const reset = ['product_id','quantity','unit'].includes(name)
    setForm(f => ({...f,[name]:value,...(name === 'product_id' ? {unit:''} : {}),...(reset ? {supplier_id:''} : {})}))
    request.current?.abort()
    setBusy(false)
    setData(null)
    setError('')
    setScenarioError('')
    setAmounts({})
    setScenarioChanged(false)
  }
  const product = products.find(p => String(p.id) === form.product_id) || data?.product
  const priorities = {balanced:t('Balance cost, time and reliability','Ekuilibër mes kostos, afatit dhe besueshmërisë'),cost:t('Lowest quoted material cost','Kostoja më e ulët sipas ofertës'),speed:t('Shortest quoted lead time','Afati më i shkurtër sipas ofertës'),reliability:t('Most reliable on record','Më i besueshmi sipas historikut')}
  const scenarios = {
    conservative:[t('Smaller quantity','Sasi më e vogël'),t('Test a smaller order and lower immediate exposure.','Provo një porosi më të vogël me angazhim më të ulët të menjëhershëm.')],
    balanced:[t('Requested quantity','Sasia e kërkuar'),t('Start from the requested quantity.','Nisu nga sasia e kërkuar.')],
    growth:[t('Larger quantity','Sasi më e madhe'),t('Test a larger order and higher exposure.','Provo një porosi më të madhe me angazhim më të lartë.')],
  }
  const types = {comparable_offer:t('Comparable current offer','Ofertë aktuale e krahasueshme'),recorded_offer:t('Recorded offer','Ofertë e regjistruar'),
    historical_candidate:t('Historical candidate','Furnitor me historik'),registered_supplier:t('Registered supplier · verify supply','Furnitor i regjistruar · verifiko furnizimin')}
  const gapLabels = {current_quote:t('Current quote for this quantity','Ofertë aktuale për këtë sasi'),dated_fx:t('Dated exchange rate','Kurs valutor me datë'),
    delivery:t('Delivery lead time','Afati i dorëzimit'),requested_date:t('Delivery by requested date','Dorëzimi deri në datën e kërkuar'),
    landed_cost:t('Freight, duty and tax','Transporti, dogana dhe tatimi')}
  const addToPlan = () => {
    setPlan(current => [...current.filter(line => line.product_id !== data.product.id), {
    ...current.find(line => line.product_id === data.product.id),
    product_id:data.product.id,name:data.product.name,quantity:data.quantity,unit:data.normalized.unit,warehouse_id:data.warehouse?.id,
    supplier_id:form.supplier_id,required_by:data.required_by,priority:data.priority,suppliers:data.suppliers.map(({id,name})=>({id,name})),
    }].slice(0,5))
    reveal('portfolio-title')
  }
  const unknown = t('Unknown','Nuk dihet')
  const money = n => n == null ? unknown : businessMoney(n,data.base_currency,language)
  const qty = n => n == null ? unknown : new Intl.NumberFormat(language === 'sq' ? 'sq' : 'en',{maximumFractionDigits:3}).format(n) + ' ' + data.normalized.inventory_unit
  const date = value => businessDate(value,language)
  const currentOffers = data?.suppliers.filter(supplier => supplier.quote) || []
  const rankedOffer = data?.recommendation && currentOffers.find(supplier => supplier.id === data.recommendation.id)
  const offerToReview = rankedOffer || (currentOffers.length === 1 ? currentOffers[0] : null)
  const candidate = data?.quote_candidates.length === 1 && !data.evidence_limited ? data.quote_candidates[0] : null
  const showSupplierEvidence = () => {
    supplierEvidence.current.open = true
    reveal('purchase-supplier-evidence')
  }

  return <main className="page-shell purchasing-decisions">
    <PageHeader title={t('Purchasing Decisions','Vendimet për blerje')} description={t('Check stock and supplier quotes before ordering.','Kontrollo stokun dhe ofertat e furnitorëve para porosisë.')}/>
    <form id="purchase-comparison" className="card purchase-panel" onSubmit={e => {e.preventDefault(); compare(form,amounts,'suppliers')}}>
      <fieldset disabled={busy}><legend>{t('What are you planning to buy?','Çfarë planifikon të blesh?')}</legend>
        <div className="purchase-fields">
          <label>{t('Find a product','Gjej një produkt')}<input type="search" value={term} onChange={e => setTerm(e.target.value)} placeholder={t('Name or SKU','Emri ose kodi')}/></label>
          <label>{t('Product','Produkti')}<select value={form.product_id} required onChange={e => field('product_id',e.target.value)}>
            <option value="">{t('Select a product','Zgjidh produktin')}</option>
            {form.product_id && !products.some(p => String(p.id) === form.product_id) && <option value={form.product_id}>{product?.name || t('Selected product','Produkti i zgjedhur')}</option>}
            {products.map(p => <option key={p.id} value={p.id}>{p.name} · {p.sku}</option>)}
          </select></label>
          <label>{t('Quantity','Sasia')}<input required type="number" min="0.001" max="100000000" step="0.001" value={form.quantity} onChange={e => field('quantity',e.target.value)}/></label>
        </div>
        <details className="purchase-disclosure" onInvalid={event => { event.currentTarget.open = true }}>
          <summary>{t('Purchase options','Opsionet e blerjes')}</summary>
          <div className="purchase-fields">
          <label>{t('Purchase unit','Njësia e blerjes')}<input list="purchase-units" value={form.unit} onChange={e => field('unit',e.target.value)} placeholder={product?.unit || t('Product’s stock unit','Njësia e stokut')}/>
            <datalist id="purchase-units">{[...new Set([product?.unit,...(product?.units || []).map(u => u.code)])].filter(Boolean).map(u => <option key={u} value={u}/>)}</datalist></label>
          <label>{t('Destination warehouse','Depoja e destinacionit')}<select value={form.warehouse_id} onChange={e => field('warehouse_id',e.target.value)}><option value="">{t('All warehouses','Të gjitha depot')}</option>{warehouses.map(w => <option key={w.id} value={w.id}>{w.name}</option>)}</select></label>
          <label>{t('Needed by (optional)','Nevojitet deri më (opsionale)')}<input type="date" value={form.required_by} onChange={e => field('required_by',e.target.value)}/></label>
          <label>{t('What matters most?','Çfarë ka më shumë rëndësi?')}<select value={form.priority} onChange={e => field('priority',e.target.value)}>{Object.entries(priorities).map(([k,v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          {canBudget && <label>{t('Budget envelope (optional)','Buxheti i planifikuar (opsional)')}{data && ' · ' + data.base_currency}<input type="number" min="0" max="100000000" step="0.01" value={form.budget} onChange={e => field('budget',e.target.value)}/></label>}
          </div>
        </details>
      </fieldset>
      {lookupError && <p role="alert">{t('Product lookup failed. Try searching again.','Kërkimi i produkteve dështoi. Provo përsëri.')}</p>}
      {warehouseError && <p role="alert">{t('Warehouses could not be loaded.','Depot nuk u ngarkuan.')}</p>}
      <div className="purchase-actions">
        <button disabled={busy || !form.product_id}>{busy ? t('Checking…','Duke kontrolluar…') : t('Check stock and quotes','Kontrollo stokun dhe ofertat')}</button>
        {plan.length > 0 && <button className="secondary" type="button" onClick={() => reveal('portfolio-title')}>{t('View purchasing plan','Shiko planin e blerjeve')}</button>}
      </div>
      {error && <p role="alert">{error}</p>}
    </form>
    {data && <>
      <section className="card purchase-panel" aria-labelledby="purchase-suppliers">
        <header>
          <h2 id="purchase-suppliers" tabIndex={-1}>{data.product.name} · {businessNumber(data.quantity,language,3)} {data.normalized.unit}</h2>
          <p>{data.warehouse?.name || t('All warehouses','Të gjitha depot')} · {date(data.as_of)}{data.required_by && ' · ' + t('Needed by','Nevojitet deri më') + ' ' + date(data.required_by)}</p>
        </header>
        <div className="purchase-callout purchase-decision-result">
          <strong>{offerToReview
            ? (rankedOffer ? t('Suggested supplier','Furnitori i sugjeruar') : t('Current quote','Oferta aktuale')) + ' · ' + offerToReview.name
            : currentOffers.length ? t('Current quotes to review','Oferta aktuale për t’u shqyrtuar')
            : data.evidence_limited ? t('No current quote in the results shown','Nuk ka ofertë aktuale në rezultatet e shfaqura')
            : t('No current quote for this quantity','Nuk ka ofertë aktuale për këtë sasi')}</strong>
          {rankedOffer && <small>{priorities[data.priority]}</small>}
          {offerToReview && data.can_view_cost && offerToReview.quote.total != null &&
            <p>{money(offerToReview.quote.total)} · {t('Materials only. Confirm delivery and other costs.','Vetëm materiali. Konfirmo dorëzimin dhe kostot e tjera.')}</p>}
          {offerToReview?.quoted_date_fits === false &&
            <p>{t('Delivery by your requested date is unconfirmed.','Dorëzimi deri në datën e kërkuar nuk është konfirmuar.')}</p>}
          {!currentOffers.length && !canManage &&
            <p>{t('Ask Purchasing to get a current quote.','Kërko nga ekipi i blerjeve një ofertë aktuale.')}</p>}
          <div className="purchase-actions">
            {offerToReview ? <Link className="btn btn-primary" to={offerToReview.quote.url}>{t('Review supplier quote','Shqyrto ofertën e furnitorit')}</Link>
              : currentOffers.length ? <button type="button" onClick={showSupplierEvidence}>{t('Compare current quotes','Krahaso ofertat aktuale')}</button>
              : canManage && (candidate?.quote_recording_url
                ? <Link className="btn btn-primary" to={candidate.quote_recording_url}>{t('Open existing quote request','Hap kërkesën ekzistuese për ofertë')}</Link>
                : <Link className="btn btn-primary" to="/procurement#purchase-request-form" state={quoteDraft(data,candidate,t)}>{t('Prepare quote request','Përgatit kërkesën për ofertë')}</Link>)}
          </div>
        </div>
        <dl className="purchase-facts">
          <Fact label={t('Available now','Në dispozicion tani')} value={qty(data.stock.available_to_promise)}/>
          <Fact label={t('Reserved','Rezervuar')} value={qty(data.stock.reserved)}/>
          <Fact label={t('Incoming on record','Sasia në ardhje sipas regjistrit')} value={qty(data.stock.incoming)}/>
        </dl>
        {!data.stock.incoming_quantity_complete && <p>{t('Incoming quantities are incomplete.','Sasitë në ardhje janë të paplota.')}</p>}
        <details className="purchase-disclosure" ref={supplierEvidence}>
        <summary id="purchase-supplier-evidence">{t('Supplier details','Detajet e furnitorëve')} · {data.suppliers.length}</summary>
        {Object.values(data.preferences || {}).some(Boolean) && <details><summary>{t('How priorities change the result','Si e ndryshojnë rezultatin përparësitë')}</summary>
          <dl className="purchase-facts">{Object.entries(data.preferences).map(([key,winner]) => <Fact key={key} label={priorities[key]} value={winner ? winner.name : t('No clear winner','Nuk ka fitues të qartë')}/>)}</dl>
        </details>}
        {!data.can_view_cost && <p>{t('Your role can compare delivery evidence. Prices require financial permissions.','Roli yt mund të krahasojë të dhënat e dorëzimit. Për çmimet duhen leje financiare.')}</p>}
        {data.evidence_limited && <p>{t('Only a limited set of evidence is shown; no ranking is available.','Po shfaqet vetëm një pjesë e të dhënave; nuk ka renditje.')}</p>}
        {!data.suppliers.length && <p>{t('No active supplier mapping, recorded quote or eligible order history was found.','Nuk u gjet furnitor aktiv, ofertë e regjistruar ose historik i përshtatshëm porosish.')}</p>}
        {data.other_registered_suppliers?.length > 0 && <details><summary>{t('Other registered suppliers','Furnitorë të tjerë të regjistruar')}</summary>
          <p>{t('These suppliers have no verified link to this product. Confirm that they can supply it before requesting prices.','Këta furnitorë nuk kanë lidhje të verifikuar me produktin. Konfirmo se mund ta furnizojnë para se të kërkosh çmim.')}</p>
          <ul>{data.other_registered_suppliers.map(s => <li key={s.id}>{s.name}</li>)}</ul>
        </details>}
        <div className="purchase-cards">{data.suppliers.map(s => <article className="purchase-option" key={s.id}><h3>{s.name}</h3>
          <strong className="purchase-price-label" data-source={s.evidence_type === 'comparable_offer' ? 'quote' : s.catalogue_estimate ? 'catalogue' : 'unknown'}>{types[s.evidence_type]}</strong>
          {!s.quote && !s.catalogue_linked && <small>{s.product_supplier ? t('Assigned product supplier · request a quote','Furnitori i produktit · kërko ofertë') : t('Prior orders only · confirm current supply','Vetëm porosi të mëparshme · verifiko furnizimin aktual')}</small>}
          <dl className="purchase-details">
            {data.can_view_cost && <Fact label={s.quote?.total != null ? t('Quoted materials','Materiali sipas ofertës') : t('Catalogue estimate','Vlerësim nga katalogu')} value={money(s.quote?.total ?? s.catalogue_estimate?.total)}/>}
            <Fact label={t('Quoted lead time','Afati sipas ofertës')} value={s.quote?.lead_days == null ? t('Unverified','I paverifikuar') : s.quote.lead_days + ' ' + t('days','ditë')}/>
            <Fact label={t('On-time history','Dorëzime në kohë')} value={s.history.on_time_percent == null ? unknown : s.history.on_time_percent + '% · ' + s.history.promised_samples + ' ' + t('orders','porosi')}/>
          </dl>
          {s.quoted_date_fits === false && <p>{t('Delivery by the requested date is unsupported.','Dorëzimi deri në datën e kërkuar nuk mbështetet nga dëshmitë.')}</p>}
          {s.quote_gaps?.length > 0 && <p>{t('Still needed: ','Ende duhen: ')}{s.quote_gaps.map(g=>gapLabels[g]).join(' · ')}</p>}
          {s.evidence_type !== 'comparable_offer' && <details>
            <summary>{t('What to ask this supplier','Çfarë t’i kërkosh këtij furnitori')}</summary>
            <ul>
              <li>{t('Current unit price, currency and offer expiry','Çmimi aktual për njësi, valuta dhe vlefshmëria e ofertës')}</li>
              <li>{t('Minimum order and pack or roll size','Sasia minimale dhe madhësia e paketës ose rrotullës')}</li>
              <li>{t('Expected delivery to ','Dorëzimi i pritshëm në ')}{data.warehouse?.name || t('a destination you confirm','destinacionin që konfirmon')}</li>
              <li>{t('Freight terms, customs, taxes and other landed costs','Kushtet e transportit, dogana, tatimet dhe kostot e tjera deri në depo')}</li>
            </ul>
            {canManage && (s.quote_recording_url
              ? <Link to={s.quote_recording_url}>{t('Record a received quote','Regjistro një ofertë të marrë')} →</Link>
              : <p>{t('Prepare a purchase request. After approval, add this supplier to a request for quotes in Procurement and record their received offer there.','Përgatit një kërkesë blerjeje. Pas miratimit, shtoje furnitorin në kërkesën për oferta te Blerjet dhe regjistro ofertën e marrë atje.')}</p>)}
          </details>}
          {canManage && s.evidence_type !== 'comparable_offer' && !s.quote_recording_url && <Link to="/procurement#purchase-request-form" state={quoteDraft(data,s,t)}>{t('Prepare quote request','Përgatit kërkesën për ofertë')} →</Link>}
          <details><summary>{t('View evidence','Shiko dëshmitë')}</summary><dl className="purchase-details">
            {s.quote && <Fact label={t('Offer valid through','Oferta vlen deri më')} value={date(s.quote.valid_until)}/>}
            {s.quote?.unit_price != null && <Fact label={t('Quote per stock unit','Oferta për njësi stoku')} value={s.quote.unit_price + ' ' + data.base_currency}/>}
            {(s.quote?.fx_date || s.catalogue_estimate?.fx_date) && <Fact label={t('Recorded FX date','Data e kursit të regjistruar')} value={date(s.quote?.fx_date || s.catalogue_estimate.fx_date)}/>}
            {s.minimum_base_quantity != null && <Fact label={t('Catalogue minimum','Minimumi i katalogut')} value={qty(s.minimum_base_quantity)}/>}
            {s.catalogue_next_valid_base_quantity != null && <Fact label={t('Next quantity meeting catalogue pack and minimum','Sasia e radhës që plotëson paketimin dhe minimumin')} value={qty(s.catalogue_next_valid_base_quantity)}/>}
            {s.history.assessed_from && <Fact label={t('On-time sample period','Periudha e dorëzimeve të vlerësuara')} value={date(s.history.assessed_from) + ' – ' + date(s.history.assessed_to)}/>}
            {s.quote?.shipping_terms && <Fact label={t('Recorded shipping terms','Kushtet e regjistruara të transportit')} value={s.quote.shipping_terms}/>}
            <Fact label={t('Recent ordered / accepted','Porositur / pranuar së fundi')} value={qty(s.recent_ordered) + ' / ' + qty(s.recent_accepted)}/>
            <Fact label={t('Quality evidence','Të dhënat e cilësisë')} value={s.inspections ? s.inspections + ' ' + t('inspections','inspektime') + ' · ' + qty(s.rejected_quantity) + ' ' + t('rejected','refuzuar') : t('No inspections in these records','Nuk ka inspektime në këto regjistrime')}/>
          </dl>{s.quote && <Link to={s.quote.url}>{s.quote.reference}</Link>}{s.orders.length > 0 && <ul>{s.orders.map(o => <li key={o.url}><Link to={o.url}>{o.reference}</Link></li>)}</ul>}</details>
        </article>)}</div>
        </details>
        {canBudget && <div className="purchase-actions">
          <button className="secondary" type="button" disabled={plan.length >= 5 && !plan.some(line => line.product_id === data.product.id)} onClick={addToPlan}>{plan.some(line => line.product_id === data.product.id) ? t('Update product in plan','Përditëso produktin në plan') : t('Add to purchasing plan','Shto në planin e blerjeve')}</button>
          {plan.length >= 5 && !plan.some(line => line.product_id === data.product.id) && <small>{t('The plan holds up to five products.','Plani mban deri në pesë produkte.')}</small>}
        </div>}
      </section>
      <details className="card purchase-panel">
        <summary id="purchase-budget">{t('Compare sizes for this product only','Krahaso sasi vetëm për këtë produkt')}</summary>
        <p>{t('Start with 50%, 100% and 150% of your request, rounded to permitted units. These editable what-ifs cover one product. They do not forecast demand or approve spending.','Nisu nga 50%, 100% dhe 150% e kërkesës, të rrumbullakosura sipas njësisë. Këta skenarë të ndryshueshëm mbulojnë një produkt. Nuk parashikojnë kërkesën ose miratojnë shpenzimin.')}</p>
        <form className="purchase-scenarios-form" onSubmit={event => { event.preventDefault(); compare(form,amounts,'scenarios') }}>
        {data.can_view_cost && <label>{t('Supplier for the scenarios','Furnitori për skenarët')}<select disabled={busy} value={form.supplier_id} onChange={e => {setForm(current => ({...current,supplier_id:e.target.value})); setScenarioChanged(true)}}><option value="">{t('Use qualified recommendation, if available','Përdor rekomandimin e mbështetur, nëse ka')}</option>{data.suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>}
        <div className="purchase-cards">{data.scenarios.map(s => <article key={s.id} className="purchase-option" data-scenario={s.id}>
          <h3>{scenarios[s.id][0]}</h3><p>{scenarios[s.id][1]}</p>
          <label>{t('Quantity','Sasia')} · {data.normalized.unit}<input type="number" min="0.001" max="100000000" step="0.001" disabled={busy} value={amounts[s.id] ?? s.quantity} onChange={e => {setAmounts(a => ({...a,[s.id]:e.target.value})); setScenarioChanged(true)}} required/></label>
          {data.can_view_cost && !scenarioChanged && <dl className="purchase-details"><Fact label={s.cost_source === 'quote' ? t('Quoted materials','Materiali sipas ofertës') : t('Estimated materials','Kostoja e vlerësuar e materialit')} value={money(s.material_cost)}/>
            {s.material_cost == null && <Fact label={t('Evidence needed','Dëshmia e nevojshme')} value={t('Choose a supplier with a usable price for this quantity.','Zgjidh furnitor me çmim të përdorshëm për këtë sasi.')}/>}
            {data.budget != null && <Fact label={t('Envelope left before freight and tax','Buxheti i mbetur para transportit dhe tatimeve')} value={money(s.remaining_envelope)}/>}
          </dl>}
        </article>)}</div>
        <div className="purchase-actions"><button className="secondary" type="submit" disabled={busy}>{busy ? t('Checking…','Duke kontrolluar…') : t('Recalculate with these assumptions','Rillogarit me këto supozime')}</button></div>
        {scenarioError && <p role="alert">{scenarioError}</p>}
        </form>
        <details><summary>{t('Supply over 30, 60 and 90 days','Furnizimi për 30, 60 dhe 90 ditë')}</summary>
          <p>{t('Dated outstanding purchase orders only. Dates may change. Undated and overdue quantities are excluded.','Vetëm porositë e mbetura të blerjes me datë. Datat mund të ndryshojnë. Sasitë pa datë dhe me vonesë nuk përfshihen.')}</p>
          <dl className="purchase-facts">{data.horizons.map(h => <Fact key={h.days} label={h.days + ' ' + t('days','ditë')} value={qty(h.dated_incoming)}/>)}</dl>
        </details>
        {data.financial && <details><summary>{t('Recorded cash and purchasing obligations','Gjendja e arkës dhe detyrimet e blerjeve')}</summary>
          <p>{t('These source subtotals can be incomplete. Open commitments and unpaid purchase orders overlap. Finance must confirm total obligations and spending capacity. Expected customer collections are excluded.','Këto nëntotale mund të jenë të paplota. Angazhimet dhe porositë e papaguara mbivendosen. Financat duhet të konfirmojnë detyrimet totale dhe mundësinë e shpenzimit. Arkëtimet e pritshme nga klientët nuk përfshihen.')}</p>
          <dl className="purchase-facts"><Fact label={t('Recorded cash and bank','Arka dhe banka sipas regjistrit')} value={money(data.financial.recorded_cash)}/><Fact label={t('Outstanding on purchase orders','Saldoja e papaguar në porositë e blerjes')} value={money(data.financial.po_outstanding)}/><Fact label={t('Open purchasing commitments','Angazhimet në porosi të hapura')} value={money(data.financial.open_po_commitments)}/><Fact label={t('Unlinked supplier invoices','Faturat e furnitorëve pa lidhje me porosi')} value={money(data.financial.unlinked_supplier_documents)}/></dl>
          {data.financial.upcoming_po_payments.length > 0 && <><h3>{t('PO payments due within 30 days or overdue','Pagesa të porosive brenda 30 ditëve ose të vonuara')}</h3><ul>{data.financial.upcoming_po_payments.map(p => <li key={p.url}><Link to={p.url}>{p.reference}</Link> · {date(p.due_date)} · {money(p.amount)}</li>)}</ul></>}
          {(data.financial.excluded_currency_accounts > 0 || data.financial.unknown_fx_records > 0) && <p>{t('Some foreign currency balances or obligations are excluded.','Disa gjendje ose detyrime në valutë të huaj nuk përfshihen.')}</p>}
        </details>}
      </details>
    </>}
    {canBudget && (plan.length > 0 || planRequested) && <PurchasePortfolio lines={plan} setLines={setPlan} budget={planBudget} setBudget={setPlanBudget} buffer={planBuffer} setBuffer={setPlanBuffer} language={language} canManage={canManage}/>}
  </main>
}

function Fact({label,value}) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>
}
