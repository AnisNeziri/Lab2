import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { payroll, workforce, downloadPayroll } from '../api/workforce';
import EntityDocuments from '../components/EntityDocuments';
import { NextStep, WorkflowSteps } from '../components/WorkflowSummary';
import { payrollNext, payrollStage, payrollFeedback } from './payrollWorkflow';
import { useSessionState } from '../hooks/useSessionState';
import { Field, Form, Modal, Pager, Payslip, Status, useWorkforceText } from './WorkforceShared';
import { money, paymentRemaining, payrollLabels, payrollMoneyKeys, periodDates } from './workforcePresentation';
export default function PayrollCenter() {
  const t = useWorkforceText(),
    permissions = useAuthStore(s => s.permissions),
    can = p => permissions.includes(p);
  const [params, setParams] = useSearchParams(),
    id = params.get('run'),
    configuration = params.get('tab') === 'configuration';
  const [summary, setSummary] = useState(null),
    [runs, setRuns] = useState(null),
    [detail, setDetail] = useState(null),
    [options, setOptions] = useState(null),
    [config, setConfig] = useState(null);
  const [error, setError] = useState(''),
    [busy, setBusy] = useState(false),
    [notice,setNotice]=useState(''),
    [tick, setTick] = useState(0),
    [dialog, setDialog] = useState(null),
    [slip, setSlip] = useState(null);
  const [ack, setAck] = useState(false),
    [dense, setDense] = useSessionState('payroll.dense', true),
    [page, setPage] = useSessionState('payroll.page', 1),
    [employeeFilter, setEmployeeFilter] = useState(''),
    [onlyWarnings, setOnlyWarnings] = useState(false);
  const paymentKey = useRef(null),
    [estimate, setEstimate] = useState(null);
  async function act(fn) {
    setError('');setNotice('');
    setBusy(true);
    try {
      const result=await fn();
      if(payrollFeedback[result?.status])setNotice(result.status);
      setTick(x => x + 1);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    let alive = true;
    Promise.all([payroll('/summary'), payroll('?' + new URLSearchParams({
      page
    })), workforce('/options')]).then(([s, r, o]) => {
      if (alive) {
        setSummary(s);
        setRuns(r);
        setOptions(o);
      }
    }).catch(e => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [tick, page]);
  useEffect(() => {
    let alive = true;
    setDetail(null);
    setAck(false);
    if (id) payroll('/' + id + '?' + new URLSearchParams({
      employee_id: employeeFilter,
      page: params.get('line_page') || 1
    })).then(d => alive && setDetail(d)).catch(e => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [id, tick, employeeFilter, params.get('line_page')]);
  useEffect(() => {
    let alive = true;
    if (configuration && can('payroll.configure')) payroll('/configuration').then(d => alive && setConfig(d)).catch(e => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [configuration, tick]);
  const run = detail?.run,
    change = value => setParams({
      ...Object.fromEntries(params),
      ...value
    }),
    selectedWarnings = new Set((run?.warnings || []).map(w => w.employee_id));
  const openPayment = (kind, line) => {
    paymentKey.current = crypto.randomUUID();
    setDialog({
      type: 'pay',
      kind,
      line
    });
  };
  return <main className={'wf-workspace ' + (dense ? 'wf-compact' : '')}>
    <header className="wf-heading"><div><span className="wf-eyebrow">AIMS · {t('Confidential finance', 'Financat konfidenciale')}</span><h1>{t('Payroll Control Center', 'Qendra e kontrollit të pagave')}</h1><p>{t('Reviewed earnings, statutory obligations and auditable settlement.', 'Të ardhurat e shqyrtuara, detyrimet dhe pagesat e auditueshme.')}</p></div><Link to="/workforce">{t('Employees', 'Punonjësit')}</Link><Link to="/accounting">{t('Accounting', 'Kontabiliteti')}</Link></header>
    <nav className="wf-tabs"><button className={!configuration ? 'active' : ''} onClick={() => setParams(id ? {
        run: id
      } : {})}>{t('Payroll', 'Pagat')}</button>{can('payroll.configure') && <button className={configuration ? 'active' : ''} onClick={() => change({
        tab: 'configuration'
      })}>{t('Configuration', 'Konfigurimi')}</button>}</nav>
    {error && <p role="alert" className="wf-error">{error}</p>}{notice&&<p role="status" className="wf-note">{t(...payrollFeedback[notice])}</p>}
    {configuration ? <>
      <section className="wf-panel"><h2>{t('Company payroll setup', 'Konfigurimi i pagave')}</h2><p className="wf-note">{t('Review dedicated accounts and the verified Kosovo rule before the first run. Initialization preserves existing mappings. New tax legislation requires a reviewed rule-engine update.', 'Shqyrtoni llogaritë e dedikuara dhe rregullat e Kosovës para listës së parë. Inicializimi ruan konfigurimet ekzistuese.')}</p><button disabled={busy} onClick={() => act(() => payroll('/configuration/initialize', {}, 'POST'))}>{t('Initialize reviewed payroll defaults', 'Inicializo konfigurimin bazë të pagave')}</button></section>
      {config && <><Form title={t('Accounting mappings', 'Lidhjet kontabël')} disabled={busy} label={t('Save mappings', 'Ruaj lidhjet')} submit={v => act(() => payroll('/configuration/mappings', {
          mappings: Object.fromEntries(Object.entries(v).map(([k, id]) => [k, Number(id)]))
        }, 'PUT'))}>{config.required_mappings.map(key => <Field key={key} label={key.replaceAll('_', ' ')}><select name={key} defaultValue={config.mappings.find(m => m.mapping_key === 'payroll_' + key)?.accounting_account_id || ''} required><option value="">{t('Select account', 'Zgjidh llogarinë')}</option>{config.accounts.map(a => <option key={a.id} value={a.id}>{a.code} · {a.name}</option>)}</select></Field>)}</Form><section className="wf-panel"><h2>{t('Immutable statutory versions', 'Versionet ligjore të pandryshueshme')}</h2><div className="wf-table-wrap"><table><thead><tr><th>{t('Version', 'Versioni')}</th><th>{t('Effective from', 'Nga data')}</th><th>{t('Sources', 'Burimet')}</th></tr></thead><tbody>{config.rules.map(r => <tr key={r.id}><td>{r.version}</td><td>{r.effective_from.slice(0, 10)}</td><td>{r.sources.map((s, i) => <a key={s} href={s} target="_blank" rel="noreferrer">{i + 1} </a>)}</td></tr>)}</tbody></table></div><p className="wf-note">{t('Primary: 0–250 at 0%, 250–450 at 8%, above 450 at 10% of taxable salary. Secondary: 10%. Resident pension: 5–15% for each party. Temporary foreign non-resident treatment requires documented review.', 'Primar: 0–250 me 0%, 250–450 me 8%, mbi 450 me 10% të pagës së tatueshme. Sekondar: 10%. Kontributet: 5–15% për secilën palë. Përjashtimet kërkojnë shqyrtim.')}</p></section>
      <Form title={t('Net → gross planning', 'Planifikimi neto → bruto')} disabled={busy} label={t('Estimate gross cost', 'Vlerëso koston bruto')} submit={v => act(async () => setEstimate(await payroll('/estimate', {
          ...v,
          pension_treatment: 'resident_contributor'
        }, 'POST')))}><Field label={t('Desired net', 'Neto e dëshiruar')} name="desired_net" type="number" step="0.01" min="0.01" required /><Field label={t('Payroll period start', 'Fillimi i periudhës')} name="period" type="date" defaultValue={new Date().toISOString().slice(0, 7) + '-01'} required /><Field label={t('Employer', 'Punëdhënësi')}><select name="employer_classification"><option value="primary">Primary / Primar</option><option value="secondary">Secondary / Sekondar</option></select></Field><Field label={t('Employee pension %', 'Pensioni i punonjësit %')} name="employee_pension_rate" type="number" min="5" max="15" step="0.001" defaultValue="5" required /><Field label={t('Employer pension %', 'Pensioni i punëdhënësit %')} name="employer_pension_rate" type="number" min="5" max="15" step="0.001" defaultValue="5" required /></Form>{estimate && <p className="wf-note">{t('Planning only', 'Vetëm planifikim')}: {t('Gross', 'Bruto')} {money(estimate.gross)} · {t('Employer cost', 'Kostoja')} {money(estimate.employer_cost)}</p>}</>}
    </> : <>
      {summary && !id && <><section className="wf-metrics"><div><small>{t('Active employees', 'Punonjës aktivë')}</small><strong>{summary.active_employees}</strong></div>{['gross', 'net', 'tax', 'employer_cost'].map(key => <div key={key}><small>{t(...payrollLabels[key])} · {summary.latest_run?.period?.name || '—'}</small><strong>{money(summary.latest_run?.totals?.[key])}</strong></div>)}</section><div className="wf-toolbar"><span className="wf-note">{summary.missing_terms} {t('employees missing current compensation', 'punonjës pa kushte aktuale pagese')} · {summary.pending.reduce((n, r) => n + Number(r.count), 0)} {t('records awaiting review', 'regjistrime në pritje')}</span><Link to="/workforce?tab=records">{t('Review workforce requests', 'Shqyrto kërkesat')}</Link></div></>}
      <details className="wf-panel" open={!id}><summary>{t('Select period / history','Zgjidh periudhën / historinë')}</summary><div className="wf-toolbar"><h2>{t('Payroll periods & history', 'Periudhat dhe historiku')}</h2>{can('payroll.manage') && <button disabled={busy} onClick={() => setDialog({
            type: 'period'
          })}>{t('New payroll period', 'Periudhë e re pagash')}</button>}</div><div className="wf-table-wrap"><table><thead><tr><th>{t('Period', 'Periudha')}</th><th>{t('Revision', 'Revizioni')}</th><th>{t('Status', 'Statusi')}</th><th className="wf-money">{t('Gross', 'Bruto')}</th><th className="wf-money">{t('Net', 'Neto')}</th><th>{t('Action', 'Veprimi')}</th></tr></thead><tbody>{runs?.data.map(r => <tr key={r.id}><td>{r.period.name}</td><td>{r.revision}{r.correction_of_id ? ' · correction' : ''}</td><td><Status value={r.status} /></td><td className="wf-money">{money(r.totals?.gross)}</td><td className="wf-money">{money(r.totals?.net)}</td><td><button onClick={() => {
                    setEmployeeFilter('');
                    setParams({
                      run: r.id
                    });
                  }}>{t('Open payroll', 'Hap listën')}</button></td></tr>)}</tbody></table></div>{runs?.data.length === 0 && <p className="wf-empty">{t('Initialize payroll configuration, add effective employment terms, then create a period.', 'Inicializoni pagat, shtoni kushtet e punësimit dhe krijoni periudhën.')}</p>}<Pager page={runs} change={setPage} /></details>
      {id&&!run&&!error&&<section className="wf-panel" role="status"><p>{t('Loading selected payroll…','Duke ngarkuar listën e zgjedhur…')}</p></section>}
      {run && <section className="wf-panel"><div className="wf-toolbar"><div><h2>{run.period.name} · PAY-{run.id}</h2><Status value={run.status} /></div><label><input type="checkbox" checked={dense} onChange={e => setDense(e.target.checked)} />{t('Dense table', 'Tabelë kompakte')}</label></div><WorkflowSteps steps={[t('Calculate','Llogarit'),t('Review','Shqyrto'),t('Approve','Mirato'),t('Post','Posto'),t('Pay','Paguaj'),t('Close','Mbyll'),t('Complete','Përfunduar')]} current={payrollStage(run.status)} label={t('Payroll progress','Ecuria e pagave')}/>{payrollNext[run.status] && <NextStep title={t(...payrollNext[run.status].slice(0,2))} tone={run.status==='needs_review'?'attention':run.status==='closed'?'complete':'active'} action={run.status==='needs_review'?<Link to="/workforce?tab=records">{t('Review source records','Shqyrto regjistrimet burimore')}</Link>:['posted','paid'].includes(run.status)?<a href="#payroll-obligations">{t('Review payments','Shqyrto pagesat')}</a>:null}>{t(...payrollNext[run.status].slice(2))}</NextStep>}
        <div className="wf-toolbar wf-review-actions"><div className="wf-toolbar">{can('payroll.manage') && ['draft', 'needs_review'].includes(run.status) && <button disabled={busy} onClick={() => act(() => payroll('/' + run.id + '/calculate', {}, 'POST'))}>{run.status === 'approved' ? t('Recalculate & reset approval', 'Rillogarit dhe hiq miratimin') : t('Calculate payroll', 'Llogarit pagat')}</button>}{can('payroll.approve') && run.status === 'calculated' && <button disabled={busy || !!run.warnings?.length && !ack} onClick={() => act(() => payroll('/' + run.id + '/approve', {
              fingerprint: run.fingerprint,
              acknowledge_warnings: ack
            }, 'POST'))}>{t('Approve reviewed payroll', 'Mirato listën e shqyrtuar')}</button>}{can('payroll.post') && run.status === 'approved' && <button disabled={busy} onClick={() => act(() => payroll('/' + run.id + '/post', {
              fingerprint: run.fingerprint
            }, 'POST'))}>{t('Post payroll', 'Posto pagat')}</button>}{can('payroll.approve') && run.status === 'paid' && <button disabled={busy} onClick={() => act(() => payroll('/' + run.id + '/close', {}, 'POST'))}>{t('Close payroll', 'Mbyll listën')}</button>}{can('payroll.reverse') && run.status === 'posted' && !detail.payments.length && <details data-lightweight-menu><summary>{t('More actions','Veprime të tjera')}</summary><div><button onClick={() => setDialog({
              type: 'reverse'
            })}>{t('Review reversal', 'Shqyrto anulimin')}</button></div></details>}{can('payroll.manage') && run.status === 'reversed' && <button disabled={busy} onClick={() => act(async () => {
              const r = await payroll('/' + run.id + '/correction', {}, 'POST');
              setParams({
                run: r.id
              });
            })}>{t('Create corrected draft', 'Krijo draftin korrigjues')}</button>}</div>{can('payroll.manage') && ['calculated','approved'].includes(run.status) && <details data-lightweight-menu><summary>{t('More actions','Veprime të tjera')}</summary><div><button disabled={busy} onClick={() => {if(window.confirm(t('Recalculate this payroll? Any recorded approval will be withdrawn. Review and approve the new calculation before posting.','Të rillogariten pagat? Miratimi ekzistues hiqet. Shqyrto dhe mirato llogaritjen e re para postimit.')))act(() => payroll('/' + run.id + '/calculate', {}, 'POST'))}}>{t('Recalculate & reset approval','Rillogarit dhe hiq miratimin')}</button></div></details>}{run.journal_entry_id && can('accounting.journal.view') && <Link to={'/accounting?tab=journal&journal=' + run.journal_entry_id}>{t('Open posted journal', 'Hap ditarin e postuar')}</Link>}</div>
        {run.warnings?.length > 0 && <details open><summary>{run.warnings.length} {t('review notices', 'njoftime për shqyrtim')}</summary>{run.warnings.map((w, i) => <p key={i} className="wf-attention"><Link to={'/workforce?employee='+w.employee_id}>{options?.employees.find(e=>e.id===w.employee_id)?.name||('#'+w.employee_id)}</Link> · {t(w.message,({pending_inputs:'Regjistrimet e pamiratuara janë përjashtuar. Zgjidhi ose shqyrtoji shprehimisht para miratimit.',changed_salary:'Kompensimi ndryshoi gjatë muajit. Shqyrto llogaritjen sipas datave.',unusual_values:'Bruto tejkalon dyfishin e pagës bazë ose neto është zero. Ky është njoftim për shqyrtim, jo rregull ligjor.'})[w.code]||w.message)}</p>)}{run.status === 'calculated' && <label><input type="checkbox" checked={ack} onChange={e => setAck(e.target.checked)} />{t('I reviewed these notices and the calculated employees.', 'I shqyrtova njoftimet dhe llogaritjet.')}</label>}</details>}
        <div className="wf-toolbar"><Field label={t('Employee filter', 'Filtro punonjësin')}><select value={employeeFilter} onChange={e => {
              setEmployeeFilter(e.target.value);
              change({
                line_page: 1
              });
            }}><option value="">{t('All employees', 'Të gjithë')}</option>{options?.employees.map(e => <option key={e.id} value={e.id}>{e.employee_number} · {e.name}</option>)}</select></Field><label><input type="checkbox" checked={onlyWarnings} onChange={e => setOnlyWarnings(e.target.checked)} />{t('Warnings / changed salaries on this page', 'Njoftimet / pagat e ndryshuara në këtë faqe')}</label></div>
        <p className="wf-scroll-hint">{t('Scroll the table horizontally to see every amount and action.','Lëviz tabelën horizontalisht për të parë çdo shumë dhe veprim.')}</p><div className="wf-table-wrap" tabIndex={0} role="region" aria-label={t('Employee payroll amounts','Shumat e pagave')}><table aria-label="Payroll employees"><thead><tr><th>{t('Employee', 'Punonjësi')}</th>{payrollMoneyKeys.map(key => <th className="wf-money" key={key}>{t(...payrollLabels[key])}</th>)}<th>{t('Actions', 'Veprimet')}</th></tr></thead><tbody>{detail.lines.data.filter(l => !onlyWarnings || selectedWarnings.has(l.employee_id)).map(l => <tr key={l.id}><th><Link to={'/workforce?employee=' + l.employee_id}>{l.snapshot.employee.name}</Link><small className="wf-muted"> · {l.snapshot.employee.employee_number}</small></th>{payrollMoneyKeys.map(key => <td key={key} className="wf-money">{money(l[key])}</td>)}<td><button onClick={() => setDialog({
                    type: 'line',
                    line: l
                  })}>{t('Breakdown', 'Detajet')}</button>{['posted', 'paid', 'closed', 'reversed'].includes(run.status) && <button disabled={busy} onClick={() => act(async () => setSlip(await payroll('/payslips/' + l.id)))}>{t('Payslip', 'Fletëpagesa')}</button>}{can('payroll.pay') && ['posted', 'paid'].includes(run.status) && Number(paymentRemaining(l, detail.payments)) > 0 && <button disabled={busy} onClick={() => openPayment('salary', l)}>{t('Pay employee', 'Paguaj punonjësin')}</button>}</td></tr>)}</tbody>{run.totals && <tfoot><tr className="wf-total"><th>{t('Entire run total', 'Totali i plotë')}</th>{payrollMoneyKeys.map(key => <td className="wf-money" key={key}>{money(run.totals[key])}</td>)}<td /></tr></tfoot>}</table></div><Pager page={detail.lines} change={value => change({
          line_page: value
        })} />
        <h3 id="payroll-obligations">{t('Payroll obligations ↔ General Ledger', 'Detyrimet e pagave ↔ Libri kryesor')}</h3><div className="wf-table-wrap"><table><thead><tr><th>{t('Obligation', 'Detyrimi')}</th>{[t('Accrued','Krijuar'),t('Paid','Paguar'),t('Remaining','Mbetja'),'GL',t('Difference','Diferenca')].map(h => <th className="wf-money" key={h}>{h}</th>)}<th /></tr></thead><tbody>{detail.obligations.rows.map(o => <tr key={o.kind}><th>{payrollLabels[o.kind]?t(...payrollLabels[o.kind]):o.kind}</th>{['accrued', 'paid', 'remaining', 'gl', 'difference'].map(k => <td className="wf-money" key={k}>{money(o[k])}</td>)}<td>{o.kind !== 'salary' && can('payroll.pay') && Number(o.remaining) > 0 && ['posted', 'paid'].includes(run.status) && <button onClick={() => openPayment(o.kind)}>{t('Settle liability', 'Shlyej detyrimin')}</button>}</td></tr>)}</tbody></table></div>
        <p className="wf-note">{['paid','closed'].includes(run.status)?t('Preparation only — verify the actual payment month and official EDI requirements','Vetëm përgatitje — verifiko muajin e pagesës dhe kërkesat zyrtare EDI'):t('Not ready — salaries are not fully settled','Jo gati — pagat nuk janë shlyer plotësisht')} · {t('Nominal WM/CM deadline', 'Afati nominal WM/CM')}: {detail.obligations.nominal_due_date}. {t(detail.obligations.qualification,'Detyrimet e krijuara rakordohen me librin kryesor. Përgatitja WM/CM bazohet në muajin e shqyrtuar të pagesës. Pagat e papaguara nuk paraqiten si deklarim i kryer. Shtyrjet për ditë jopune kërkojnë shqyrtim.')}</p><div className="wf-toolbar">{['posted', 'paid', 'closed'].includes(run.status) && <><button disabled={busy} onClick={() => act(() => downloadPayroll(run.id, 'declaration'))}>{t('Export declaration preparation', 'Eksporto përgatitjen e deklaratës')}</button>{can('payroll.pay') && <button disabled={busy} onClick={() => act(() => downloadPayroll(run.id, 'payments'))}>{t('Export payment preparation', 'Eksporto përgatitjen e pagesave')}</button>}</>}</div>
        <details><summary>{t('Department labor cost', 'Kostoja e punës sipas departamentit')}</summary><table><tbody>{detail.department_costs.map((d, i) => <tr key={i}><th>{d.department || '—'}</th><td className="wf-money">{money(d.gross)}</td><td className="wf-money">{money(d.employer_cost)}</td></tr>)}</tbody></table></details>
        <details><summary>{t('Payment history', 'Historiku i pagesave')}</summary><table><tbody>{detail.payments.map(p => <tr key={p.id}><td>{p.paid_on.slice(0, 10)}</td><td>{payrollLabels[p.kind]?t(...payrollLabels[p.kind]):p.kind}</td><td className="wf-money">{money(p.amount)}</td><td>{can('accounting.journal.view') ? <Link to={'/accounting?tab=journal&journal=' + p.journal_entry_id}>{t('Journal', 'Ditari')} #{p.journal_entry_id}</Link> : 'Posted'}</td></tr>)}</tbody></table></details>
        {can('employees.documents') && <EntityDocuments entityType="payroll-run" entityId={run.id} compact />}
      </section>}
    </>}
    {dialog?.type === 'period' && <Modal busy={busy} title={t('New payroll period', 'Periudhë e re pagash')} close={() => setDialog(null)}><Form title={t('Monthly payroll', 'Pagat mujore')} disabled={busy} label={t('Create payroll period', 'Krijo periudhën')} submit={v => act(async () => {
        const dates = periodDates(v.month);
        const r = await payroll('', {
          name: v.name,
          ...dates,
          pay_date: v.pay_date
        }, 'POST');
        setDialog(null);
        setParams({
          run: r.id
        });
      })}><Field label={t('Month', 'Muaji')} name="month" type="month" required defaultValue={new Date().toISOString().slice(0, 7)} /><Field label={t('Period name', 'Emri i periudhës')} name="name" required defaultValue={new Date().toLocaleDateString('en-GB', {
          month: 'long',
          year: 'numeric'
        })} /><Field label={t('Scheduled pay date', 'Data e planifikuar e pagesës')} name="pay_date" type="date" required defaultValue={periodDates(new Date().toISOString().slice(0, 7)).ends_on} /></Form><p className="wf-note">{t('Complete monthly periods only. Partial/final employment is prorated using explicitly approved calendar-day compensation policy.', 'Vetëm periudha të plota mujore. Punësimi i pjesshëm llogaritet sipas politikës së miratuar.')}</p></Modal>}
    {dialog?.type === 'pay' && run && <Modal busy={busy} title={t('Review payroll settlement', 'Shqyrto pagesën e pagave')} close={() => setDialog(null)}><p>{dialog.line?.snapshot.employee.name || (payrollLabels[dialog.kind]?t(...payrollLabels[dialog.kind]):dialog.kind)} · {t('Payable → Cash/Bank. Salary expense is not recorded again.', 'Detyrimi → Arka/Banka. Shpenzimi i pagës nuk regjistrohet përsëri.')}</p><Form title={t('Explicit payment', 'Pagesë e autorizuar')} disabled={busy} label={t('Record payment', 'Regjistro pagesën')} submit={v => act(async () => {
        await payroll('/' + run.id + '/pay', {
          ...v,
          kind: dialog.kind,
          ...(dialog.line ? {
            payroll_line_id: dialog.line.id
          } : {}),
          idempotency_key: paymentKey.current
        }, 'POST');
        setDialog(null);
      })}><Field label={t('Payment account', 'Llogaria e pagesës')}><select name="financial_account_id" required><option value="">{t('Select account', 'Zgjidh llogarinë')}</option>{options?.payment_accounts.map(a => <option key={a.id} value={a.id}>{a.name} · {a.currency}</option>)}</select></Field><Field label={t('Amount', 'Shuma')} name="amount" type="number" min="0.01" step="0.01" defaultValue={dialog.line ? paymentRemaining(dialog.line, detail.payments) : detail.obligations.rows.find(o => o.kind === dialog.kind).remaining} required /><Field label={t('Payment date', 'Data e pagesës')} name="paid_on" type="date" defaultValue={run.period.pay_date.slice(0, 10)} required /></Form></Modal>}
    {dialog?.type === 'reverse' && <Modal busy={busy} title={t('Reverse unpaid payroll', 'Anulo pagat e papaguara')} close={() => setDialog(null)}><p className="wf-note">{t('The original run and employee breakdown remain. A linked reversal will cancel the accrued obligations. Paid payroll cannot use this reversal.', 'Lista origjinale ruhet. Anulimi i lidhur kthen detyrimet. Pagat e paguara nuk mund të anulohen këtu.')}</p><Form title={'PAY-' + run.id} disabled={busy} label={t('Create linked reversal', 'Krijo anulimin e lidhur')} submit={v => act(async () => {
        await payroll('/' + run.id + '/reverse', v, 'POST');
        setDialog(null);
      })}><Field label={t('Reversal date', 'Data e anulimit')} name="date" type="date" required defaultValue={run.period.ends_on.slice(0, 10)} /><Field label={t('Reason', 'Arsyeja')} name="reason" minLength={3} required /></Form></Modal>}
    {dialog?.type === 'line' && <Modal busy={busy} title={dialog.line.snapshot.employee.name} close={() => setDialog(null)}><p>{dialog.line.snapshot.rule.version}</p><table><tbody>{['gross', 'employee_pension', 'employer_pension', 'taxable', 'tax', 'deductions', 'advance_repayment', 'net', 'employer_cost'].map(key => <tr key={key}><th>{t(...payrollLabels[key])}</th><td>{money(dialog.line[key])}</td></tr>)}</tbody></table><h3>{t('Effective earnings', 'Të ardhurat sipas kushteve')}</h3><table><thead><tr><th>{t('Employment version','Versioni i punësimit')}</th><th>{t('Pay basis','Baza e pagës')}</th><th>{t('Days / units','Ditë / njësi')}</th><th>{t('Amount','Shuma')}</th></tr></thead><tbody>{dialog.line.snapshot.components.parts.map(p => <tr key={p.term_id}><td>#{p.term_id}</td><td>{p.pay_basis} · {money(p.rate)}</td><td>{p.days} / {p.units}</td><td>{money(p.amount)}</td></tr>)}</tbody></table><p className="wf-note">{t('Monthly proration uses approved calendar days. Overtime amounts and unpaid leave reductions follow explicitly approved company policy.', 'Llogaritja mujore përdor ditët kalendarike të miratuara. Puna shtesë dhe pushimi pa pagesë ndjekin politikën e miratuar.')}</p></Modal>}
    {slip && <Payslip data={slip} close={() => setSlip(null)} />}
  </main>;
}
