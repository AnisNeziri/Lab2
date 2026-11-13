import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../api/client'
import { businessDate, businessMoney, businessNumber } from '../utils/businessFormat'

export function quoteDraft(comparison, supplier, t) {
  const product = comparison.product
  const notes = [
    supplier && t('Proposed supplier: ', 'Furnitori i propozuar: ') + supplier.name,
    t('SKU: ', 'Kodi: ') + product.sku,
    comparison.warehouse && t('Destination: ', 'Destinacioni: ') + comparison.warehouse.name,
    t('Request unit price, currency, offer validity, minimum order, pack size, delivery date and shipping terms. Ask for freight, duty and tax separately.',
      'Kërko çmimin për njësi, valutën, vlefshmërinë, minimumin, paketimin, datën e dorëzimit dhe kushtet e transportit. Kërko veçmas transportin, doganën dhe tatimin.'),
  ].filter(Boolean).join('\n')
  return { purchaseDraft: {currency:comparison.base_currency, required_by:comparison.required_by || '', notes,
    items:[{product_id:product.id,description:product.name,unit:comparison.normalized.unit,quantity:comparison.quantity,estimated_unit_price:''}]} }
}

export default function PurchasePortfolio({ lines, setLines, budget, setBudget, buffer, setBuffer, language, canManage }) {
  const t = (en,sq) => language === 'sq' ? sq : en
  const [result,setResult] = useState(null)
  const [error,setError] = useState('')
  const [busy,setBusy] = useState(false)
  const [run,setRun] = useState(0)
  const payload = JSON.stringify({ lines:lines.map(({ product_id, quantity, unit, warehouse_id, supplier_id, required_by, priority, assumed_unit_price, assumed_extra_cost }) =>
    Object.fromEntries(Object.entries({ product_id, quantity, unit, warehouse_id, supplier_id, required_by, priority, assumed_unit_price, assumed_extra_cost }).filter(([,v]) => v !== '' && v != null))),
    ...(budget !== '' && { budget }), ...(buffer !== '' && { buffer }) })
  useEffect(() => {
    if (!lines.length || lines.some(line => !line.product_id || !(Number(line.quantity) > 0))) { setBusy(false); setResult(null); setError(''); return }
    setBusy(true); setResult(null); setError('')
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      try {
        const next = await apiRequest('/procurement/purchase-comparison', { method:'POST', body:payload, signal:controller.signal })
        if (!controller.signal.aborted) setResult(next)
      } catch (err) {
        if (!controller.signal.aborted) setError(err.message)
      } finally { if (!controller.signal.aborted) setBusy(false) }
    }, 300)
    return () => { clearTimeout(timer); controller.abort() }
    // The serialized input changes only when a scenario assumption changes.
  }, [payload,run])
  const update = (index, changes) => setLines(current => current.map((line,i) => i === index ? { ...line,...changes } : line))
  const money = value => value == null ? t('Unknown','Nuk dihet') : businessMoney(value,result.base_currency,language)
  const fact = (label,value) => <div><dt>{label}</dt><dd>{money(value)}</dd></div>
  const strategies = result?.strategy === 'confirmed_shortfall_review'
  const labels = strategies ? [t('Minimum confirmed need','Nevoja minimale e konfirmuar'),t('Confirmed need review','Shqyrtimi i nevojës së konfirmuar'),t('Broader confirmed need','Nevoja më e gjerë e konfirmuar')]
    : [t('Smaller order','Porosi më e vogël'),t('Requested order','Porosia e kërkuar'),t('Larger order','Porosi më e madhe')]
  const sourceLabels = {quote:t('Verified current quotation','Ofertë aktuale e verifikuar'),
    catalogue:t('Catalogue estimate','Vlerësim nga katalogu'),assumption:t('Your planning assumption','Supozimi yt për planifikim')}
  const baseScenario = result?.scenarios[1]

  return <section id="purchase-portfolio" className="card purchase-panel" aria-labelledby="portfolio-title">
    <h2 id="portfolio-title">{t('Purchasing plan','Plani i blerjeve')}</h2>
    <p>{t('Add up to five products from the supplier comparison. Edit quantities and review the cost and cash impact together.',
      'Shto deri në pesë produkte nga krahasimi i furnitorëve. Ndrysho sasitë dhe shqyrto së bashku koston dhe ndikimin në arkë.')}</p>
    {!lines.length && <p>{t('Compare a product above, then add it to the plan.','Krahaso një produkt më sipër, pastaj shtoje në plan.')}</p>}
    {lines.map((line,index) => {
      const evidence = result?.lines[index]
      const choice = baseScenario?.lines[index]
      const unpriced = choice?.quantity != null && choice.cost == null
      return <article className="purchase-option" key={line.product_id}>
        <div className="purchase-heading"><h3>{line.name || evidence?.product.name || t('Selected product','Produkti i zgjedhur')}</h3>
          <button className="secondary" type="button" aria-label={t('Remove product ', 'Hiq produktin ') + (line.name || index + 1)} onClick={() => setLines(previous => previous.filter((_,i) => i !== index))}>{t('Remove','Hiq')}</button></div>
        <div className="purchase-fields">
          <label>{t('Proposed quantity','Sasia e propozuar')} · {line.unit || evidence?.normalized.unit}<input type="number" min=".001" max="100000000" step=".001" value={line.quantity} onChange={e => update(index,{quantity:e.target.value})}/></label>
          <label>{t('Supplier evidence','Dëshmitë e furnitorit')}<select value={line.supplier_id || ''} onChange={e => update(index,{supplier_id:e.target.value})}>
            <option value="">{t('Use qualified recommendation','Përdor rekomandimin e mbështetur')}</option>
            {(evidence?.suppliers || line.suppliers || []).map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select></label>
        </div>
        {evidence && <p>{t('Available to promise: ','Në dispozicion për porosi: ')}{evidence.stock.available_to_promise} {evidence.normalized.inventory_unit} · {t('Confirmed shortage now: ','Mungesa e konfirmuar tani: ')}{evidence.firm_shortfall_now} {evidence.normalized.inventory_unit}</p>}
        {choice?.cost_source && <div className="purchase-actions"><strong className="purchase-price-label" data-source={choice.cost_source}>{sourceLabels[choice.cost_source]}</strong>
          <span>{businessNumber(choice.unit_price,language,6)} {result.base_currency} / {choice.unit} · {money(choice.cost)} {t('materials','materiale')}</span>
          {choice.price_evidence?.url && <Link to={choice.price_evidence.url}>{choice.price_evidence.reference}</Link>}
          {choice.price_evidence?.valid_until && <small>{t('Valid through ','Vlen deri më ')}{businessDate(choice.price_evidence.valid_until,language)}</small>}
          {choice.cost_source === 'catalogue' && <small>{t('Catalogue price recorded: ','Çmimi i katalogut u regjistrua: ')}{choice.price_evidence?.updated_at || t('date unavailable','data mungon')}</small>}
        </div>}
        {unpriced && <p><strong className="purchase-price-label" data-source="unknown">{t('Price needed','Duhet çmimi')}</strong> {t('Request a current quote or enter a what-if price below.','Kërko ofertë aktuale ose vendos një çmim hipotetik më poshtë.')}</p>}
        <details open={line.assumed_unit_price != null && line.assumed_unit_price !== '' || undefined}>
          <summary>{t('Enter a planning assumption','Vendos një supozim planifikimi')}</summary>
          <p>{t('Use your company currency. The unit price applies to the purchase unit shown above. A verified quotation for the same quantity takes priority.','Përdor valutën e kompanisë. Çmimi zbatohet për njësinë e blerjes më sipër. Oferta e verifikuar për të njëjtën sasi ka përparësi.')}</p>
          <div className="purchase-fields">
            <label>{t('Assumed unit price','Çmimi i supozuar për njësi')}{result && ' · ' + result.base_currency}<input type="number" min="0" max="1000000" step=".0001" value={line.assumed_unit_price ?? ''} onChange={e => update(index,{assumed_unit_price:e.target.value})}/></label>
            <label>{t('Assumed extra costs per scenario','Kostot shtesë të supozuara për skenar')}{result && ' · ' + result.base_currency}<input type="number" min="0" max="100000000" step=".01" value={line.assumed_extra_cost ?? ''} onChange={e => update(index,{assumed_extra_cost:e.target.value})}/></label>
          </div>
        </details>
        {unpriced && canManage && evidence.quote_candidates.length > 0 && (evidence.quote_candidates.some(s=>String(s.id)===String(line.supplier_id))
          ? <Link to="/procurement#purchase-request-form" state={quoteDraft(evidence,evidence.quote_candidates.find(s => String(s.id) === String(line.supplier_id)),t)}>{t('Review a quote request','Shqyrto kërkesën për ofertë')} →</Link>
          : <p>{t('Choose a supplier above to prepare their quote request.','Zgjidh furnitorin më sipër për të përgatitur kërkesën për ofertë.')}</p>)}
      </article>
    })}
    {lines.length > 0 && <div className="purchase-fields">
      <label>{t('Planning budget','Buxheti i planifikuar')}<input type="number" min="0" max="100000000" step=".01" value={budget} onChange={e => setBudget(e.target.value)}/><small>{t('The amount you choose for this plan.','Shuma që zgjedh për këtë plan.')}</small></label>
      <label>{t('Cash buffer to keep','Rezerva e parasë për t’u ruajtur')}<input type="number" min="0" max="100000000" step=".01" value={buffer} onChange={e => setBuffer(e.target.value)}/><small>{t('Cash you want to keep aside. It does not reduce the planning budget.','Para që dëshiron t’i mbash mënjanë. Rezerva nuk e ul buxhetin e planit.')}</small></label>
    </div>}
    {busy && <p role="status">{t('Recalculating the plan…','Duke rillogaritur planin…')}</p>}
    {error && <p role="alert">{error} <button type="button" className="secondary" onClick={() => setRun(r => r + 1)}>{t('Try again','Provo përsëri')}</button></p>}
    {result && <>
      <section className="purchase-callout" id="portfolio-costs" aria-labelledby="purchase-summary">
        <h3 id="purchase-summary">{t('Decision at a glance','Vendimi në një vështrim')}</h3>
        <ul className="purchase-summary-lines">{result.lines.map((item,i) => <li key={item.product.id}><strong>{item.product.name}</strong> · {item.quantity} {item.normalized.unit} · {item.warehouse?.name || t('Destination to confirm','Destinacioni për t’u konfirmuar')}
          <small>{item.recommendation?.name || (item.quote_candidates.length ? t('Quote candidates: ','Kandidatë për ofertë: ')+item.quote_candidates.map(s=>s.name).join(', ') : t('Supplier to confirm','Furnitori për t’u konfirmuar'))} · {sourceLabels[baseScenario.lines[i]?.cost_source] || t('Price unconfirmed','Çmimi i pakonfirmuar')}</small>
        </li>)}</ul>
        <dl className="purchase-facts">
          {fact(t('Planning budget','Buxheti i planifikuar'),result.budget)}
          {fact(baseScenario.unpriced_count ? t('Subtotal of priced items','Nëntotali i artikujve me çmim') : baseScenario.has_assumptions ? t('What-if plan cost','Kostoja hipotetike e planit') : t('Estimated plan cost','Kostoja e vlerësuar e planit'),baseScenario.priced_subtotal)}
          {fact(baseScenario.has_assumptions ? t('What-if budget remaining','Buxheti hipotetik i mbetur') : t('Remaining planning budget','Buxheti i mbetur i planit'),baseScenario.illustrative_envelope_remaining)}
        </dl>
        <p>{t('The remaining planning budget is the amount you set minus included costs. Freight, customs and taxes still need confirmation.','Buxheti i mbetur i planit është shuma që caktove minus kostot e përfshira. Transporti, dogana dhe tatimet ende duhen konfirmuar.')}</p>
        <p>{baseScenario.unpriced_count ? t('Some items still need prices. Use the priced subtotal and request the missing quotes before deciding.','Disa artikuj ende nuk kanë çmim. Përdor nëntotalin dhe kërko ofertat që mungojnë para vendimit.')
          : baseScenario.has_assumptions ? t('The plan uses your assumptions. Confirm them with suppliers before relying on the result.','Plani përdor supozimet e tua. Konfirmoji me furnitorët para se të mbështetesh te rezultati.')
            : t('Review the price sources and outstanding obligations before committing.','Shqyrto burimet e çmimeve dhe detyrimet para angazhimit.')}</p>
        <p><strong>{t('Spendable cash is unknown.','Paratë e lira për shpenzim nuk dihen.')}</strong> {t('Finance must verify actual obligations and available funds separately.','Financat duhet të verifikojnë veçmas detyrimet dhe fondet në dispozicion.')}</p>
        <a href="#portfolio-next-step">{t('Go to the next action','Kalo te hapi i radhës')} →</a>
      </section>
      <p>{strategies
        ? t('Only quantities supported by uncovered confirmed orders enter these plans. The plans step up from smaller quantities when demand supports more. Other lines need separate review.','Planet përfshijnë vetëm sasi të mbështetura nga porosi të konfirmuara të pambuluara. Sasitë rriten vetëm kur kërkesa e lejon. Artikujt e tjerë kërkojnë shqyrtim veçmas.')
        : t('Compare half, the requested quantity and one and a half times that quantity, rounded to valid units. This shows the cost of changing the order size.','Krahaso gjysmën, sasinë e kërkuar dhe një herë e gjysmë atë sasi, të rrumbullakosura sipas njësisë. Kjo tregon si ndryshon kostoja me madhësinë e porosisë.')}</p>
      <div className="purchase-cards" id="portfolio-scenarios" tabIndex={-1}>{result.scenarios.map((scenario,i) => <article className="purchase-option" key={scenario.id}>
        <h3>{labels[i]}</h3>
        <dl className="purchase-details">
          {fact(scenario.has_assumptions ? t('Subtotal with assumptions','Nëntotali me supozime') : t('Subtotal of priced items','Nëntotali i artikujve me çmim'),scenario.priced_subtotal)}
          {Object.entries(scenario.material_subtotals).map(([source,value]) => value.count > 0 && <div key={source}><dt><span className="purchase-price-label" data-source={source}>{sourceLabels[source]}</span></dt><dd>{money(value.amount)}</dd></div>)}
          {scenario.lines.some(l=>l.assumed_extra_cost != null) && fact(t('Assumed extra costs','Kosto shtesë të supozuara'),scenario.assumed_extras_subtotal)}
          {result.budget !== null && fact(scenario.has_assumptions ? t('What-if budget remaining','Buxheti hipotetik i mbetur') : t('Illustrative envelope left','Buxheti ilustrues i mbetur'),scenario.illustrative_envelope_remaining)}
          {fact(t('Illustrative cash after obligations, buffer and priced costs','Arka ilustruese pas detyrimeve, rezervës dhe kostove me çmim'),scenario.illustrative_cash_after_buffer_and_priced_costs)}
        </dl>
        {scenario.unpriced_count > 0 && <strong>{scenario.unpriced_count} {t('unpriced · subtotal is incomplete','pa çmim · nëntotali është i paplotë')}</strong>}
        {scenario.priced_costs_exceed_envelope && <strong>{t('The subtotal already exceeds the planning budget.','Nëntotali tashmë e tejkalon buxhetin e planifikuar.')}</strong>}
        <ul>{scenario.lines.map(line => <li key={line.product.id}>{line.product.name} · {line.quantity == null
          ? t('Review separately','Shqyrto veçmas')
          : line.quantity + ' ' + line.unit + ' · ' + (line.cost == null ? t('Unpriced','Pa çmim') : money(line.cost) + ' · ' + sourceLabels[line.cost_source])}</li>)}</ul>
      </article>)}</div>
      <p>{t('Freight, duty and tax are unknown unless you enter them as a planning assumption. Recorded cash may include restricted funds. Expected collections are excluded.','Transporti, dogana dhe tatimi mbeten të panjohura përveçse kur i vendos si supozim planifikimi. Gjendja e regjistruar mund të përfshijë para të kufizuara. Arkëtimet e pritshme nuk përfshihen.')}</p>
      {result.financial.evidence_readiness?.status === 'blocked' && <p role="status">{t('Finance needs to reconcile the records before confirming total obligations. The figures below may be incomplete.','Financat duhet t’i rakordojnë regjistrimet para se të konfirmojnë detyrimet totale. Shifrat më poshtë mund të jenë të paplota.')}</p>}
      <details><summary>{t('Cash, supplier bills and purchase orders','Arka, faturat e furnitorëve dhe porositë e blerjes')}</summary>
        <dl className="purchase-facts">
          {fact(t('Recorded cash and bank','Arka dhe banka sipas regjistrit'),result.financial.recorded_cash)}
          {fact(t('Posted unpaid supplier bills','Faturat e postuara e të papaguara'),result.financial.posted_supplier_bills)}
          {fact(t('Of those bills, linked to purchase orders','Prej tyre, të lidhura me porosi blerjeje'),result.financial.linked_supplier_bills)}
          {fact(t('Bills due within 30 days or overdue','Fatura me afat brenda 30 ditëve ose të vonuara'),result.financial.supplier_bills_due_30_days_or_overdue)}
          {result.financial.partial_po_source_subtotal == null
            ? fact(t('Supported unbilled purchase order subtotal','Nëntotali i mbështetur i porosive të pafaturuara'),result.financial.known_unbilled_po_exposure)
            : fact(t('Subtotal from available order records · incomplete','Nëntotali nga porositë e regjistruara · i paplotë'),result.financial.partial_po_source_subtotal)}
          {fact(t('Combined known obligations','Detyrimet e njohura së bashku'),result.financial.combined_known_obligations)}
        </dl>
        {result.financial.combined_known_obligations == null && result.financial.evidence_readiness?.status !== 'blocked' && <p>{t('A combined amount is unavailable. Finance must review currency or overlapping orders and bills.','Shuma e përbashkët nuk është në dispozicion. Financat duhet të shqyrtojnë valutën ose mbivendosjen e porosive me faturat.')}</p>}
        <CommitmentEvidence evidence={result.financial.commitment_evidence} language={language} money={money}/>
      </details>
      <Link id="portfolio-next-step" to={result.url} state={canManage ? {purchaseDraft:{currency:result.base_currency,notes:t('From Purchasing Decisions. Review suppliers, delivery, pricing and approvals before proceeding.','Nga vendimet për blerje. Shqyrto furnitorët, dorëzimin, çmimet dhe miratimet para vazhdimit.'),
        items:result.scenarios[1].lines.filter(l => l.quantity != null).map(l => ({product_id:l.product.id,description:l.product.name,unit:l.unit,quantity:l.quantity,estimated_unit_price:''}))}} : undefined}>{t('Open procurement review','Hap shqyrtimin e blerjeve')} →</Link>
    </>}
  </section>
}

function CommitmentEvidence({ evidence, language, money }) {
  const t = (en,sq) => language === 'sq' ? sq : en
  const reasons = {
    unbilled:t('Recorded order without a posted matching bill','Porosi e regjistruar pa faturë përkatëse të postuar'),
    fully_billed:t('Fully represented in posted bills','E mbuluar plotësisht në faturat e postuara'),
    part_billed:t('Partially billed · remaining materials counted separately','Pjesërisht e faturuar · materiali i mbetur llogaritet veçmas'),
    missing_po_fx:t('PO lacks a dated exchange rate','Porosisë i mungon kursi valutor me datë'),
    invoice_scope_or_currency:t('Invoice scope or currency differs','Fusha ose valuta e faturës ndryshon'),
    invoice_match_unverified:t('Invoice match needs verification','Përputhja e faturës kërkon verifikim'),
    credited_bill:t('A supplier credit needs review','Një kreditim i furnitorit kërkon shqyrtim'),
    invoice_material_variance:t('Billed materials differ from the order','Materialet e faturuara ndryshojnë nga porosia'),
    part_billed_with_po_payments:t('Partial bill and PO payments may overlap','Fatura e pjesshme dhe pagesat e porosisë mund të mbivendosen'),
    unlinked_bill_same_supplier:t('Same supplier has an unpaid bill without a PO link','I njëjti furnitor ka faturë të papaguar pa lidhje me porosi'),
  }
  if (!evidence) return null
  return <details className="purchase-evidence"><summary>{t('Review the order and bill evidence','Shqyrto dëshmitë e porosive dhe faturave')}</summary>
    <p>{t('A fully billed order adds no unbilled commitment. The payable balance already reflects applied payments.','Porosia e faturuar plotësisht nuk shton detyrim të pafaturuar. Saldoja e faturave tashmë përfshin pagesat e aplikuara.')}</p>
    {evidence.review.map(order => <article className="purchase-option" key={order.order_id}>
      <h4><Link to={order.url}>{order.reference}</Link></h4>
      <p>{reasons[order.reason]}</p>
      {order.possible_unbilled_base != null && <p>{t('Possible unpaid order amount: ','Shuma e mundshme e papaguar e porosisë: ')}{money(order.possible_unbilled_base)}</p>}
      {order.bills.length > 0 && <p>{order.bills.map(b=>b.reference).join(', ')}</p>}
      {order.potentially_related_bills?.length > 0 && <><ul>{order.potentially_related_bills.map(b => <li key={b.document_number}>{b.document_number} · {money(b.base_outstanding)}</li>)}</ul>
        <p>{t('Compare the supplier invoice, order and applied payments. The shared supplier alone does not establish a link. Until Finance confirms the relationship, the combined obligation and available funds remain unknown.','Krahaso faturën e furnitorit, porosinë dhe pagesat e aplikuara. Furnitori i njëjtë nuk provon lidhjen. Derisa Financat ta verifikojnë lidhjen, detyrimi i përbashkët dhe fondet në dispozicion mbeten të panjohura.')}</p></>}
    </article>)}
    {evidence.partial?.length > 0 && <details><summary>{t('Recorded orders requiring financial review','Porosi të regjistruara që kërkojnë shqyrtim financiar')}</summary>
      <ul>{evidence.partial.map(order => <li key={order.order_id}><Link to={order.url}>{order.reference}</Link> · {t('Remaining from recorded order total and payments','Mbetja sipas totalit dhe pagesave të regjistruara në porosi')}: {order.recorded_order_residual} {order.currency || t('currency unknown','valuta e panjohur')}</li>)}</ul>
      <p>{t('These order balances may overlap with missing or unmatched purchasing records. Finance must review them before confirming what the company owes.','Këto mbetje mund të mbivendosen me regjistrime të munguara ose të papërputhura të blerjeve. Financat duhet t’i shqyrtojnë para se të konfirmojnë detyrimet e kompanisë.')}</p>
    </details>}
    <details><summary>{t('Reviewed orders','Porositë e shqyrtuara')} · {evidence.resolved.length}</summary><ul>{evidence.resolved.map(order => <li key={order.order_id}>
      <Link to={order.url}>{order.reference}</Link> · {reasons[order.reason]}
      {order.bills.length > 0 && <span> · {order.bills.map(b=>b.reference).join(', ')}</span>}
      {order.potentially_related_bills?.length > 0 && <span> · {t('Check unlinked bill: ','Kontrollo faturën pa lidhje: ')}{order.potentially_related_bills.map(b=>b.document_number).join(', ')}</span>}
    </li>)}</ul></details>
    {(evidence.review_truncated || evidence.resolved_truncated) && <p>{t('Showing a limited number of records. Open purchase orders for the full list.','Po shfaqet një numër i kufizuar regjistrimesh. Hap porositë e blerjes për listën e plotë.')}</p>}
  </details>
}
