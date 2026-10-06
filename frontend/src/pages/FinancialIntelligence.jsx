import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { apiRequest } from '../api/client';
import { useTranslation } from '../hooks/useTranslation';
import { useAuthStore } from '../store/authStore';
import { financialTabs, financialCopy, financialMoney, evidenceLabel, scenarioDifference, financialAssumption } from './financialIntelligencePresentation';
import './FinancialIntelligence.css';
export default function FinancialIntelligence() {
  const {
      language
    } = useTranslation(),
    t = (en, sq) => language === 'sq' ? sq : en,
    label = key => financialCopy[key]?.[language === 'sq' ? 1 : 0] || key;
  const permissions = useAuthStore(s => s.permissions),
    money = (v, c) => financialMoney(v, c, language);
  const [tab, setTab] = useState('overview'),
    [horizon, setHorizon] = useState('30'),
    [data, setData] = useState(null),
    [error, setError] = useState(''),
    [busy, setBusy] = useState(false),
    [revision, setRevision] = useState(0);
  const [scenario, setScenario] = useState({
      purchase_amount: '',
      currency: 'EUR',
      collection_delay_days: '',
      supplier_delay_days: '',
      arrival_delay_days: '',
      product_id: '',
      supplier_id: '',
      base_quantity: '',
      split_quantity: '',
      split_delay_days: '',
      demand_multiplier: '',
      purchase_date: new Date().toLocaleDateString('en-CA')
    }),
    [result, setResult] = useState(null);
  const [term, setTerm] = useState({
      key: '',
      reference: '',
      basis: 'arrival',
      shipment_id: '',
      offset_days: '0'
    }),
    [notice, setNotice] = useState('');
  const [modelReason, setModelReason] = useState(''),
    pending = useRef(false);
  useEffect(() => {
    let active = true;
    apiRequest(`/financial-intelligence?horizon=${horizon}`).then(r => {
      if (active) {
        setData(r);
        setError('');
        if (r.evidence) setScenario(v => ({
          ...v,
          currency: r.evidence.base_currency
        }));
      }
    }).catch(e => {
      if (active) setError(e.message);
    });
    return () => {
      active = false;
    };
  }, [horizon, revision]);
  useEffect(()=>{setResult(null)},[scenario,horizon]);
  const action = async fn => {
    if (pending.current) return;
    pending.current = true;
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await fn();
    } catch (e) {
      setError(e.message);
    } finally {
      pending.current = false;
      setBusy(false);
    }
  };
  const e = data?.evidence,
    currencies = data?.forecast?.currencies || {},
    ready = data?.state === 'ready';
  const table = (headers, rows) => <div className="fi-table-scroll"><table><thead><tr>{headers.map(h => <th key={h}>{h}</th>)}</tr></thead><tbody>{rows.length ? rows : <tr><td colSpan={headers.length}>{t('No recorded evidence in this view.', 'Nuk ka të dhëna të regjistruara në këtë pamje.')}</td></tr>}</tbody></table></div>;
  const timeline = forecasts => Object.entries(forecasts || {}).map(([currency, f]) => <section className="fi-panel" key={currency}><h2>{currency} · {t('Expected cash movements', 'Lëvizjet e pritshme të parasë')}</h2><p>{t('Opening recorded cash', 'Paraja fillestare e regjistruar')}: {money(f.opening_recorded_cash, currency)} · {t('Expected closing cash', 'Paraja e pritshme në fund')}: {money(f.expected_closing_cash, currency)}</p>{table([t('Date', 'Data'), t('Sources', 'Burimet'), t('Inflow', 'Hyrje'), t('Outflow', 'Dalje'), t('Expected cash', 'Paraja e pritshme')], f.timeline.map(row => <tr className={row.pressure ? 'fi-pressure' : ''} key={row.date}><td>{row.date}{row.pressure && <small>{t('Projected pressure', 'Presion i parashikuar')}</small>}</td><td>{row.events.map(x => <div key={x.key}><Link to={x.url || '/financial-intelligence'}>{x.reference}</Link><small>{evidenceLabel(x.evidence_type, language)}</small></div>)}</td><td>{money(row.inflows, currency)}</td><td>{money(row.outflows, currency)}</td><td>{money(row.expected_cash, currency)}</td></tr>))}<small>{t('Expected amounts are not guaranteed. No calibrated uncertainty range is available.', 'Shumat e pritshme nuk janë të garantuara. Nuk ka interval pasigurie të kalibruar.')}</small></section>);
  return <main className="financial-intelligence"><div className="fi-heading"><div><p className="fi-eyebrow">{t('CASH & WORKING CAPITAL', 'PARATË DHE KAPITALI QARKULLUES')}</p><h1>{t('Financial Intelligence', 'Inteligjenca financiare')}</h1><p>{t('Understand cash timing before committing to a purchase.', 'Kupto afatet e parasë para se të vendosësh për një blerje.')}</p></div><button disabled={busy} onClick={() => action(async () => {
        await apiRequest('/financial-intelligence/refresh', {
          method: 'POST'
        });
        setRevision(v => v + 1);
        setNotice(t('Financial evidence updated.', 'Të dhënat financiare u përditësuan.'));
      })}>{busy ? t('Working…', 'Duke punuar…') : t('Refresh evidence', 'Përditëso të dhënat')}</button></div>
 <p className="fi-notice">{t('Advisory only. No payments, journals, orders or balances are changed.', 'Vetëm këshilluese. Nuk ndryshohen pagesat, regjistrimet kontabël, porositë ose bilancet.')}</p>
 {error && <p role="alert" className="fi-error">{error}</p>}{notice && <p role="status">{notice}</p>}
 <nav className="fi-tabs" aria-label={t('Financial Intelligence views', 'Pamjet e inteligjencës financiare')}>{financialTabs.map(key => <button key={key} aria-current={tab === key ? 'page' : undefined} onClick={() => setTab(key)}>{label(key)}</button>)}</nav>
 {!data && !error && <p role="status">{t('Loading frozen evidence…', 'Duke ngarkuar të dhënat e ruajtura…')}</p>}
 {data?.state === 'not_calculated' && <section className="fi-panel"><h2>{t('Start with your recorded data', 'Fillo me të dhënat e regjistruara')}</h2><p>{t('No forecast yet. Use Refresh evidence; future visits read the saved result without recalculating the whole company.', 'Ende nuk ka parashikim. Përdor Përditëso të dhënat; vizitat e tjera lexojnë rezultatin e ruajtur pa rillogaritur kompaninë.')}</p></section>}
 {ready && <><div className="fi-context"><span>{t('Evidence as of', 'Të dhënat më')} {data.as_of} · {label(e.confidence) !== e.confidence ? label(e.confidence) : t('Confidence', 'Besueshmëria') + ': ' + e.confidence}</span>{data.stale && <span>{t('Evidence may be stale — refresh before deciding.', 'Të dhënat mund të jenë të vjetra — përditësoji para vendimit.')}</span>}<label>{t('Horizon', 'Periudha')} <select value={horizon} onChange={v => setHorizon(v.target.value)}>{[7, 30, 60, 90].map(n => <option key={n} value={n}>{n} {t('days', 'ditë')}</option>)}</select></label></div>
 {tab === 'overview' && <><div className="fi-grid">{Object.entries(currencies).map(([c, f]) => <section key={c} className="fi-panel"><h2>{c}</h2><dl><dt>{t('Recorded cash', 'Paraja e regjistruar')}</dt><dd>{money(f.opening_recorded_cash, c)}</dd><dt>{t('Expected inflows', 'Hyrjet e pritshme')}</dt><dd>{money(f.inflows, c)}</dd><dt>{t('Expected outflows', 'Daljet e pritshme')}</dt><dd>{money(f.outflows, c)}</dd><dt>{t('Expected closing cash', 'Paraja e pritshme në fund')}</dt><dd>{money(f.expected_closing_cash, c)}</dd></dl></section>)}<section className="fi-panel"><h2>{label('inventory')}</h2><strong className="fi-value">{money(e.inventory.total, e.inventory.currency)}</strong><p>{t('Existing recorded inventory valuation, not cash available to spend.', 'Vlerësimi ekzistues i inventarit, jo para në dispozicion për shpenzim.')}</p><button onClick={() => setTab('inventory')}>{t('Explore capital', 'Shiko kapitalin')}</button></section></div><section className="fi-panel"><h2>{t('What needs attention?', 'Çfarë kërkon vëmendje?')}</h2>{!e.cash.complete && <p className="fi-warning">{label('cash_incomplete')}</p>}{Object.entries(currencies).flatMap(([c, f]) => f.pressure.map(p => <p key={c + p.date}>{p.date} · {c} · {t('Projected cash pressure', 'Presion i parashikuar i parasë')} · {money(p.expected_cash, c)}</p>))}<p>{t('Partial working-capital view. DSO, DIO, DPO and cash conversion cycle are unavailable without compatible average balances, credit sales, COGS and current-account classification.', 'Pamje e pjesshme e kapitalit qarkullues. DSO, DIO, DPO dhe cikli i parasë nuk llogariten pa bilance mesatare, shitje me kredi, kosto të shitjes dhe klasifikim të përshtatshëm.')}</p><button onClick={() => setTab('health')}>{t('Review missing information', 'Rishiko të dhënat që mungojnë')}</button></section></>}
 {tab === 'cash' && <>{timeline(currencies)}<section className="fi-panel"><h2>{t('Recorded accounts', 'Llogaritë e regjistruara')}</h2>{e.cash.accounts.map(a => <p key={a.id}><Link to={a.url}>{a.name}</Link> · {money(a.balance, a.currency)}</p>)}</section></>}
 {tab === 'receivables' && <section className="fi-panel"><h2>{label(tab)}</h2><p>{t('Only outstanding amounts are forecast. Customer advances have already been received and are never future cash inflows.', 'Parashikohen vetëm shumat e papaguara. Paradhëniet janë arkëtuar tashmë dhe nuk janë hyrje të ardhshme.')}</p>{table([t('Customer / source', 'Klienti / burimi'), t('Outstanding', 'E papaguar'), t('Due', 'Afati'), t('Expected payment', 'Pagesa e pritshme'), t('Risk / history', 'Rreziku / historia')], e.receivables.map(r => <tr key={r.key}><td><Link to={r.url}>{r.customer || r.reference}</Link><small>{r.reference} · {label(r.source_type)}</small></td><td>{money(r.amount, r.currency)}</td><td>{r.due_date || '—'}</td><td>{r.expected_date || t('Unknown — no reliable future date', 'E panjohur — pa datë të besueshme')}<small>{evidenceLabel(r.evidence_type, language)}</small></td><td><strong>{label(r.risk)}</strong><small>{label(r.segment)} · {r.samples} {t('completed payments', 'pagesa të përfunduara')}</small></td></tr>))}<details><summary>{t('Customer advances — separate from debt', 'Paradhëniet e klientëve — veçmas borxhit')}</summary>{e.customers.filter(c => Number(c.advance) > 0).map(c => <p key={c.id}>{c.name}: {money(c.advance, c.currency)}</p>)}</details></section>}
 {tab === 'commitments' && <><section className="fi-panel"><h2>{label(tab)}</h2><p>{t('Supplier documents take precedence over the invoiced part of each PO. Allocated deposits and partial payments reduce the balance once.', 'Dokumentet e furnitorit kanë përparësi për pjesën e faturuar të porosisë. Paradhëniet dhe pagesat e pjesshme ulin bilancin vetëm një herë.')}</p>{table([t('Reference / supplier', 'Referenca / furnitori'), t('Remaining', 'E mbetur'), t('Expected date', 'Data e pritshme'), t('Evidence', 'Burimi')], e.commitments.map(r => <tr key={r.key}><td><Link to={r.url}>{r.reference}</Link><small>{r.supplier}</small></td><td>{money(r.amount, r.currency)}</td><td>{r.expected_date || '—'}<small>{r.payment_basis === 'arrival' ? t('Documented arrival-dependent payment', 'Pagesë e dokumentuar sipas mbërritjes') : t('Recorded due date', 'Afati i regjistruar')}</small></td><td>{label(r.source_type)}<small>{evidenceLabel(r.evidence_type, language)}</small></td></tr>))}</section><section className="fi-panel"><h2>{t('Potential purchases — excluded from cash forecast', 'Blerje të mundshme — të përjashtuara nga parashikimi')}</h2>{e.potential.map(r => <p key={r.stage + r.id}><Link to={r.url}>{r.reference}</Link> · {money(r.amount, r.currency)} · {label(r.stage)}</p>)}</section></>}
 {tab === 'inventory' && <><section className="fi-panel"><h2>{label(tab)} · {money(e.inventory.total, e.inventory.currency)}</h2><p>{t('Slow-moving and excess inventory remain assets. These indicators do not post write-downs.', 'Inventari i ngadalshëm dhe i tepërt mbetet aktiv. Këta tregues nuk regjistrojnë zhvlerësim.')}</p>{table([t('Product', 'Produkti'), t('Value', 'Vlera'), t('Excess capital', 'Kapitali i tepërt'), t('Slow-moving capital', 'Kapitali i ngadalshëm')], e.inventory.products.map(p => <tr key={p.id}><td><Link to={p.url}>{p.name}</Link><small>{p.quantity} {p.unit}</small>{p.planned_purchase_despite_excess && <small>{t('Review replenishment despite excess', 'Rishiko rifurnizimin pavarësisht tepricës')}</small>}</td><td>{money(p.value, e.inventory.currency)}</td><td>{money(p.excess_value, e.inventory.currency)}</td><td>{money(p.slow_moving_value, e.inventory.currency)}</td></tr>))}</section><div className="fi-grid">{[['categories', t('By category', 'Sipas kategorisë')], ['warehouses', t('By warehouse', 'Sipas depos')]].map(([key, title]) => <section className="fi-panel" key={key}><h2>{title}</h2>{Object.entries(e.inventory[key]).map(([name, amount]) => <p key={name}>{name}: {money(amount, e.inventory.currency)}</p>)}{key === 'warehouses' && <small>{t('Warehouse quantity × existing weighted-average cost. Unlocated stock is not assigned to a warehouse.', 'Sasia e depos × kostoja mesatare ekzistuese. Stoku pa vendndodhje nuk i caktohet një depoje.')}</small>}</section>)}</div><details className="fi-panel"><summary>{t('Landed-cost context', 'Konteksti i kostos së importit')}</summary><p>{t('Allocated costs are valuation, not additional cash payments. Draft costs are recorded estimates, not verified supplier payables.', 'Kostot e shpërndara janë vlerësim, jo pagesa shtesë. Kostot draft janë vlerësime, jo detyrime të verifikuara.')}</p>{e.landed_costs.map(r => <p key={r.id}>{r.reference} · {money(r.amount, r.currency)} · {r.classification === 'allocated_accounting_cost' ? t('Allocated accounting cost', 'Kosto kontabël e shpërndarë') : t('Recorded estimate', 'Vlerësim i regjistruar')}</p>)}</details></>}
 {tab === 'scenarios' && <><form className="fi-panel fi-form" onSubmit={event => {
          event.preventDefault();
          action(async () => {
            const input = Object.fromEntries(Object.entries(scenario).filter(([, v]) => v !== ''));
            setResult(await apiRequest('/financial-intelligence/scenarios', {
              method: 'POST',
              body: JSON.stringify({
                ...input,
                horizon: Number(horizon)
              })
            }));
          });
        }}><h2>{t('What if…?', 'Po sikur…?')}</h2><p>{t('Compare an incremental purchase or timing changes. Nothing is saved to accounting. Unknown future sales are not invented.', 'Krahaso një blerje shtesë ose ndryshime afatesh. Asgjë nuk ruhet në kontabilitet. Nuk shpiken shitje të ardhshme.')}</p><div className="fi-form-grid">{[['purchase_amount', t('Additional purchase amount', 'Shuma e blerjes shtesë')], ['purchase_date', t('Purchase date', 'Data e blerjes')], ['currency', t('Currency', 'Monedha')], ['collection_delay_days', t('Collection delay (days, negative = earlier)', 'Vonesa e arkëtimeve (ditë, negative = më herët)')], ['supplier_delay_days', t('Supplier payment delay (days)', 'Vonesa e pagesës së furnitorit (ditë)')], ['arrival_delay_days', t('Arrival delay (documented terms only)', 'Vonesa e mbërritjes (vetëm afate të dokumentuara)')]].map(([key, title]) => <label key={key}>{title}<input value={scenario[key]} type={key.endsWith('date') ? 'date' : key === 'currency' ? 'text' : 'number'} step={key === 'purchase_amount' ? '.01' : '1'} required={key === 'currency'} onChange={v => setScenario(s => ({
                ...s,
                [key]: v.target.value
              }))} /></label>)}</div><InventoryScenarioInputs t={t} options={e.scenario_options} scenario={scenario} setScenario={setScenario}/><button disabled={busy} type="submit">{t('Compare cash impact', 'Krahaso ndikimin në para')}</button></form>{result && <section aria-live="polite"><div className="fi-panel"><h2>{t('Scenario comparison — not a commitment', 'Krahasimi i skenarit — jo detyrim')}</h2><p>{t('Additional purchase', 'Blerja shtesë')}: {money(result.purchase_commitment, result.currency)} · {t('Cash movement difference', 'Ndryshimi i lëvizjes së parasë')}: {money(scenarioDifference(result, result.currency), result.currency)}</p>{result.inventory && <p>{result.inventory.coverage_supported?t('Stockout after purchase','Mungesa pas blerjes')+': '+(result.inventory.scenario_stockout_date||t('None in supported horizon','Asnjë në periudhën e mbuluar')):t('Inventory coverage cannot be forecast reliably with this history.','Mbulimi i inventarit nuk mund të parashikohet në mënyrë të besueshme me këtë histori.')} · {t('Safety stock', 'Stoku i sigurisë')}: {result.inventory.safety_stock} · {t('Projected excess', 'Teprica e parashikuar')}: {result.inventory.excess_quantity ?? '—'}</p>}<p>{t('Lower cash outlay does not mean a safer inventory decision. Both hypothetical receipt dates are included for split-purchase coverage; review stockout exposure and freight economics.','Shpenzimi më i ulët nuk do të thotë inventar më i sigurt. Për blerjen e ndarë përfshihen të dyja datat e mbërritjes; rishiko mungesën e stokut dhe kostot e transportit.')}</p><details><summary>{t('Explicit assumptions', 'Supozimet e qarta')}</summary>{result.assumptions.map(a => <p key={a}>{financialAssumption(a, language)}</p>)}</details></div>{timeline(result.scenario.currencies)}</section>}</>}
 {tab === 'health' && <><section className="fi-panel"><h2>{t('Missing information', 'Të dhënat që mungojnë')}</h2>{e.health.length ? e.health.map((r, i) => <p key={r.code + i}>{label(r.code)} {r.count && `(${r.count})`} <Link to={r.url}>{t('Open source', 'Hap burimin')} →</Link></p>) : <p>{t('No detected source warnings. Forecasts remain advisory.', 'Nuk ka paralajmërime. Parashikimet mbeten këshilluese.')}</p>}</section><section className="fi-panel"><h2>{t('Local model and completed evidence', 'Modeli lokal dhe të dhënat e përfunduara')}</h2><p>{t('Champion', 'Modeli kryesor')}: {data.model.champion === 'due_date_baseline' ? t('Recorded due-date baseline', 'Afati i regjistruar i pagesës') : t('Customer historical median delay', 'Vonesa mediane historike e klientit')}</p><p>{t('Completed independent payment observations', 'Pagesa të pavarura të përfunduara')}: {data.model.completed_observations} / {data.model.minimum_completed}</p><p>{t('Timing error (days)', 'Gabimi i afatit (ditë)')}: {t('Due date', 'Afati')} {data.model.baseline_mae_days ?? '—'} · {t('Historical median', 'Mediana historike')} {data.model.challenger_mae_days ?? '—'}</p><p>{t('No production accuracy claim. Only frozen predictions and completed cash-payment labels are evaluated. Missing windows are not scored. Sparse customers retain the due-date baseline.', 'Nuk pretendohet saktësi prodhimi. Vlerësohen vetëm parashikimet e ruajtura dhe pagesat e përfunduara. Periudhat që mungojnë nuk vlerësohen. Klientët me pak histori përdorin afatin bazë.')}</p>{permissions.includes('analytics.ml_datasets') && <div className="fi-inline"><input aria-label={t('Model selection reason', 'Arsyeja e zgjedhjes së modelit')} placeholder={t('Reason for promotion / rollback', 'Arsyeja e promovimit / rikthimit')} value={modelReason} onChange={v => setModelReason(v.target.value)} /><button disabled={busy || modelReason.length < 5 || !data.model.eligible_for_promotion} onClick={() => action(async () => {
              await apiRequest('/financial-intelligence/model', {
                method: 'POST',
                body: JSON.stringify({
                  model: 'historical_median',
                  reason: modelReason
                })
              });
              setRevision(v => v + 1);
            })}>{t('Promote median', 'Promovo medianën')}</button><button disabled={busy || modelReason.length < 5 || data.model.champion === 'due_date_baseline'} onClick={() => action(async () => {
              await apiRequest('/financial-intelligence/model', {
                method: 'POST',
                body: JSON.stringify({
                  model: 'due_date_baseline',
                  reason: modelReason
                })
              });
              setRevision(v => v + 1);
            })}>{t('Return to baseline', 'Kthehu te baza')}</button></div>}</section>{permissions.includes('financial_accounts.manage') && <details className="fi-panel"><summary>{t('Document cash coverage and actual payment terms', 'Dokumento mbulimin e parasë dhe afatet reale')}</summary><p>{t('These settings describe evidence only. They never change contract dates or money. Only confirm complete coverage after checking every company account.', 'Këto cilësime përshkruajnë vetëm të dhënat. Nuk ndryshojnë kontrata ose para. Konfirmo mbulimin vetëm pas kontrollit të çdo llogarie.')}</p><button disabled={busy} onClick={() => action(async () => {
            await apiRequest('/financial-intelligence/policy', {
              method: 'PUT',
              body: JSON.stringify({
                cash_coverage_confirmed: !e.cash.complete
              })
            });
            setNotice(t('Coverage saved. Refresh evidence to use it.', 'Mbulimi u ruajt. Përditëso të dhënat për ta përdorur.'));
          })}>{e.cash.complete ? t('Mark cash coverage incomplete', 'Shëno mbulimin jo të plotë') : t('Confirm all cash accounts are recorded', 'Konfirmo të gjitha llogaritë e parasë')}</button><form className="fi-form" onSubmit={event => {
            event.preventDefault();
            action(async () => {
              await apiRequest('/financial-intelligence/policy', {
                method: 'PUT',
                body: JSON.stringify({
                  terms: {
                    [term.key]: {
                      basis: term.basis,
                      reference: term.reference,
                      ...(term.basis === 'arrival' ? {
                        shipment_id: Number(term.shipment_id)
                      } : {}),
                      offset_days: Number(term.offset_days)
                    }
                  }
                })
              });
              setNotice(t('Documented term saved. Refresh evidence.', 'Afati i dokumentuar u ruajt. Përditëso të dhënat.'));
            });
          }}><div className="fi-form-grid"><label>{t('Obligation', 'Detyrimi')}<select required value={term.key} onChange={v => setTerm(s => ({
                  ...s,
                  key: v.target.value
                }))}><option value="">{t('Select obligation', 'Zgjidh detyrimin')}</option>{e.commitments.map(r => <option key={r.key} value={r.key}>{r.reference} — {money(r.amount, r.currency)}</option>)}</select></label><label>{t('Contract / document reference', 'Referenca e kontratës / dokumentit')}<input required minLength={3} value={term.reference} onChange={v => setTerm(s => ({
                  ...s,
                  reference: v.target.value
                }))} /></label><label>{t('Payment basis', 'Baza e pagesës')}<select value={term.basis} onChange={v => setTerm(s => ({
                  ...s,
                  basis: v.target.value
                }))}><option value="arrival">{t('After warehouse arrival', 'Pas mbërritjes në depo')}</option><option value="fixed_date">{t('Recorded fixed due date', 'Afati fiks i regjistruar')}</option></select></label>{term.basis === 'arrival' && <><label>{t('Linked shipment ID', 'ID e dërgesës së lidhur')}<input required type="number" min="1" value={term.shipment_id} onChange={v => setTerm(s => ({
                    ...s,
                    shipment_id: v.target.value
                  }))} /></label><label>{t('Days after arrival', 'Ditë pas mbërritjes')}<input type="number" min="0" max="180" value={term.offset_days} onChange={v => setTerm(s => ({
                    ...s,
                    offset_days: v.target.value
                  }))} /></label></>}</div><button disabled={busy} type="submit">{t('Save documented terms', 'Ruaj afatet e dokumentuara')}</button></form></details>}</>}
 {tab==='overview'&&<div className="fi-grid">{Object.entries(e.totals??{}).map(([currency,totals])=><section className="fi-panel" key={currency}><h2>{t('Recorded financial context','Konteksti financiar i regjistruar')} · {currency}</h2><dl>{[['recorded_receivables',t('Open receivables','Arkëtimet e hapura')],['supplier_payables',t('Supplier documents / expenses due','Dokumentet e furnitorëve / shpenzimet e papaguara')],['uninvoiced_po_commitments',t('Uninvoiced PO commitments','Angazhime të pafaturuara të porosive')],['customer_advances',t('Customer advances already received','Paradhënie të arkëtuara nga klientët')]].map(([key,title])=><div key={key}><dt>{title}</dt><dd>{money(totals[key],currency)}</dd></div>)}</dl></section>)}</div>}
 {tab==='health'&&permissions.includes('financial_accounts.manage')&&<FinancialScenarioPolicy t={t} evidence={e} busy={busy} action={action} saved={()=>{setRevision(v=>v+1);setNotice(t('Forecast settings saved. Refresh evidence to use them.','Cilësimet u ruajtën. Përditëso të dhënat për t’i përdorur.'))}}/>}
 </>}
 </main>;
}

function InventoryScenarioInputs({t,options={products:[],suppliers:[]},scenario,setScenario}){
 const change=(key,value)=>setScenario(s=>({...s,[key]:value}))
 return <details><summary>{t('Product coverage and supplier constraints (optional)','Mbulimi i produktit dhe kufizimet e furnitorit (opsionale)')}</summary><p>{t('Choose a product to reuse inventory planning. Its recorded supplier price replaces the manual amount. Split purchases require documented supplier permission in Data & model health.','Zgjidh një produkt për të përdorur planifikimin e inventarit. Çmimi i regjistruar zëvendëson shumën manuale. Blerja e ndarë kërkon leje të dokumentuar të furnitorit te Të dhënat dhe modeli.')}</p><div className="fi-form-grid">
 <label>{t('Product','Produkti')}<select aria-label={t('Product','Produkti')} value={scenario.product_id} onChange={event=>change('product_id',event.target.value)}><option value="">{t('No product — cash-only scenario','Pa produkt — vetëm skenar parash')}</option>{options.products.map(p=><option key={p.id} value={p.id}>{p.name} · {p.unit}</option>)}</select></label>
 <label>{t('Supplier','Furnitori')}<select aria-label={t('Supplier','Furnitori')} value={scenario.supplier_id} onChange={event=>change('supplier_id',event.target.value)}><option value="">{t('Existing planning default','Zgjedhja ekzistuese e planifikimit')}</option>{options.suppliers.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
 {[['base_quantity',t('Total quantity (inventory unit)','Sasia totale (njësia e inventarit)')],['demand_multiplier',t('Demand multiplier (1.10 = +10%)','Shumëzuesi i kërkesës (1.10 = +10%)')],['split_quantity',t('Second delivery quantity','Sasia e dërgesës së dytë')],['split_delay_days',t('Second delivery delay (days)','Vonesa e dërgesës së dytë (ditë)')]].map(([key,title])=><label key={key}>{title}<input type="number" min="0" step={key==='split_delay_days'?'1':'.001'} value={scenario[key]} onChange={event=>change(key,event.target.value)}/></label>)}
 </div></details>
}

function FinancialScenarioPolicy({t,evidence,busy,action,saved}){
 const [supplier,setSupplier]=useState(''),[reference,setReference]=useState(''),[allowed,setAllowed]=useState('true'),[minimum,setMinimum]=useState('')
 return <details className="fi-panel"><summary>{t('Cash threshold and split-purchase permissions','Kufiri i parasë dhe lejet për blerje të ndarë')}</summary><form className="fi-form" onSubmit={event=>{event.preventDefault();action(async()=>{await apiRequest('/financial-intelligence/policy',{method:'PUT',body:JSON.stringify({minimum_cash:{[evidence.base_currency]:minimum}})});saved()})}}><label>{t('Minimum recorded cash for pressure warning','Paraja minimale për paralajmërim presioni')} ({evidence.base_currency})<input required type="number" min="0" step=".01" value={minimum} onChange={event=>setMinimum(event.target.value)}/></label><button disabled={busy}>{t('Save threshold','Ruaj kufirin')}</button></form>
 <form className="fi-form" onSubmit={event=>{event.preventDefault();action(async()=>{await apiRequest('/financial-intelligence/policy',{method:'PUT',body:JSON.stringify({split_permissions:{[supplier]:{allowed:allowed==='true',reference}}})});saved()})}}><div className="fi-form-grid"><label>{t('Supplier','Furnitori')}<select required value={supplier} onChange={event=>setSupplier(event.target.value)}><option value="">{t('Choose supplier','Zgjidh furnitorin')}</option>{evidence.scenario_options.suppliers.map(s=><option value={s.id} key={s.id}>{s.name}</option>)}</select></label><label>{t('Delivery permission','Leja e dërgesës')}<select value={allowed} onChange={event=>setAllowed(event.target.value)}><option value="true">{t('Split delivery documented as allowed','Dërgesa e ndarë lejohet sipas dokumentit')}</option><option value="false">{t('Split delivery not allowed','Dërgesa e ndarë nuk lejohet')}</option></select></label><label>{t('Contract / agreement reference','Referenca e kontratës / marrëveshjes')}<input required minLength={3} value={reference} onChange={event=>setReference(event.target.value)}/></label></div><button disabled={busy}>{t('Save documented permission','Ruaj lejen e dokumentuar')}</button></form></details>
}
