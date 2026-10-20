import { useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useAuthStore } from '../store/authStore';
import { workforce, payroll } from '../api/workforce';
import EntityDocuments from '../components/EntityDocuments';
import { Field, Form, Modal, Pager, Payslip, Status, useWorkforceText } from './WorkforceShared';
import { money } from './workforcePresentation';
import { useSessionState } from '../hooks/useSessionState';
export default function WorkforceCenter({
  self = false
}) {
  const t = useWorkforceText(),
    permissions = useAuthStore(s => s.permissions),
    can = p => permissions.includes(p);
  const [params, setParams] = useSearchParams(),
    id = params.get('employee'),
    tab = params.get('tab') || 'profile';
  const [list, setList] = useState(null),
    [profile, setProfile] = useState(null),
    [records, setRecords] = useState(null),
    [options, setOptions] = useState(null),
    [dialog, setDialog] = useState(null),
    [slip, setSlip] = useState(null);
  const [error, setError] = useState(''),
    [busy, setBusy] = useState(false),
    [tick, setTick] = useState(0),
    [q, setQ] = useSessionState('workforce.search', ''),
    [page, setPage] = useSessionState('workforce.page', 1),
    [recordPage, setRecordPage] = useState(1),
    [kind, setKind] = useState(''),
    [recordStatus, setRecordStatus] = useState('requested');
  const advanceKey = useRef(null),
    employee = profile?.employee,
    compensation = self || can('employees.compensation');
  async function act(fn) {
    setBusy(true);
    setError('');
    try {
      await fn();
      setTick(x => x + 1);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    let alive = true;
    setError('');
    if (self) workforce('/self').then(d => alive && setProfile(d)).catch(e => alive && setError(e.message));else Promise.all([workforce('/employees?' + new URLSearchParams({
      q,
      page
    })), workforce('/options')]).then(([l, o]) => {
      if (alive) {
        setList(l);
        setOptions(o);
      }
    }).catch(e => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [self, q, page, tick]);
  useEffect(() => {
    let alive = true;
    if (!self) {
      setProfile(null);
      if (id) workforce('/employees/' + id).then(d => alive && setProfile(d)).catch(e => alive && setError(e.message));
    }
    return () => {
      alive = false;
    };
  }, [id, tick, self]);
  useEffect(() => {
    let alive = true;
    if (tab === 'records' && (self || can('workforce.manage'))) workforce((self ? '/self' : '') + '/records?' + new URLSearchParams({
      page: recordPage,
      kind,
      status: recordStatus,
      ...(id ? {
        employee_id: id
      } : {})
    })).then(d => alive && setRecords(d)).catch(e => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [tab, recordPage, kind, recordStatus, id, tick, self]);
  const change = values => setParams({
      ...Object.fromEntries(params),
      ...values
    }),
    latest = profile?.terms?.at(-1);
  const openRecord = () => setDialog({
      type: 'record'
    }),
    openAdvance = () => {
      advanceKey.current = crypto.randomUUID();
      setDialog({
        type: 'advance'
      });
    };
  return <main className="wf-workspace"><header className="wf-heading"><div><span className="wf-eyebrow">AIMS · {t('Workforce core', 'Personeli')}</span><h1>{self ? t('My workforce', 'Hapësira ime e punës') : t('Employees & Employment', 'Punonjësit dhe punësimi')}</h1><p>{t('People, effective employment terms and approved workforce records.', 'Personeli, kushtet historike dhe regjistrimet e miratuara.')}</p></div>{can('payroll.view') && <Link to="/payroll">{t('Payroll Control Center', 'Qendra e kontrollit të pagave')}</Link>}{!self && can('employees.manage') && <button disabled={busy} onClick={() => setDialog({
        type: 'employee'
      })}>{t('Add employee', 'Shto punonjës')}</button>}</header>
    {error && <p role="alert" className="wf-error">{error}</p>}
    {!self && <section className="wf-panel"><div className="wf-toolbar"><Field label={t('Search name, number, department or position', 'Kërko emrin, numrin, departamentin ose pozitën')} value={q} onChange={e => {
          setQ(e.target.value);
          setPage(1);
        }} /></div><div className="wf-table-wrap"><table><thead><tr><th>{t('Employee number', 'Numri i punonjësit')}</th><th>{t('Name', 'Emri')}</th><th>{t('Department', 'Departamenti')}</th><th>{t('Position', 'Pozita')}</th><th>{t('Status', 'Statusi')}</th><th /></tr></thead><tbody>{list?.data.map(e => <tr key={e.id}><td>{e.employee_number}</td><th>{e.name}</th><td>{e.department || '—'}</td><td>{e.position || '—'}</td><td><Status value={e.status} /></td><td><button onClick={() => setParams({
                  employee: e.id
                })}>{t('Open employee', 'Hap profilin')}</button></td></tr>)}</tbody></table></div>{list?.data.length === 0 && <p className="wf-empty">{t('Employees can be registered without creating an AIMS login.', 'Punonjësit regjistrohen edhe pa llogari hyrëse në AIMS.')}</p>}<Pager page={list} change={setPage} /></section>}
    {(employee || tab === 'records') && <nav className="wf-tabs">{['profile', 'employment', 'records', 'payroll', 'documents', 'activity'].filter(key => key !== 'employment' || !!employee).filter(key => key !== 'documents' || !self && can('employees.documents')).filter(key => key !== 'payroll' || self || can('payroll.view')).filter(key => key !== 'records' || self || can('workforce.manage')).map(key => <button key={key} className={tab === key ? 'active' : ''} onClick={() => change({
        tab: key
      })}>{{
          profile: t('Profile', 'Profili'),
          employment: t('Employment', 'Punësimi'),
          records: t('Schedule, attendance & leave', 'Orari, prania dhe pushimi'),
          payroll: t('Payroll history', 'Historiku i pagave'),
          documents: t('Documents', 'Dokumentet'),
          activity: t('Activity', 'Aktiviteti')
        }[key]}</button>)}</nav>}
    {employee && tab === 'profile' && <section className="wf-panel"><div className="wf-toolbar"><h2>{employee.name} · {employee.employee_number}</h2><Status value={employee.status} />{!self && can('employees.manage') && <button onClick={() => setDialog({
          type: 'employee',
          employee
        })}>{t('Edit profile / lifecycle', 'Ndrysho profilin / statusin')}</button>}</div><dl className="wf-details-grid">{[[t('Email', 'Email'), employee.email], [t('Phone', 'Telefoni'), employee.phone], [t('Address', 'Adresa'), employee.address], [t('Department', 'Departamenti'), employee.department], [t('Position', 'Pozita'), employee.position], [t('Start / termination', 'Fillimi / përfundimi'), employee.start_date.slice(0, 10) + ' / ' + (employee.termination_date?.slice(0, 10) || '—')], [t('AIMS login', 'Hyrja në AIMS'), employee.user_id ? 'Linked user #' + employee.user_id : t('Employee only', 'Vetëm punonjës')], [t('Payment method', 'Mënyra e pagesës'), employee.payment_method]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || '—'}</dd></div>)}</dl><h3>{t('Annual leave balance', 'Bilanci i pushimit vjetor')} · {profile.leave_balance.year}</h3>{profile.leave_balance.configured ? <div className="wf-metrics">{['entitled', 'used', 'planned', 'remaining'].map(key => <div key={key}><small>{{
              entitled: t('Entitled', 'E drejta'),
              used: t('Used', 'Përdorur'),
              planned: t('Planned', 'Planifikuar'),
              remaining: t('Remaining', 'Mbetja')
            }[key]}</small><strong>{profile.leave_balance[key]}</strong></div>)}</div> : <p className="wf-note">{t('Annual leave entitlement has not been configured. No statutory allowance is assumed.', 'E drejta e pushimit vjetor nuk është konfiguruar. Nuk supozohet një numër ligjor ditësh.')}</p>}{compensation && <p>{t('Employee advance outstanding', 'Paradhënia e pashlyer')}: <strong>{money(profile.advance_balance)}</strong> {!self && can('payroll.pay') && <button onClick={openAdvance}>{t('Record approved advance', 'Regjistro paradhënien e miratuar')}</button>}</p>}</section>}
    {employee && !self && tab === 'profile' && compensation && <details className="wf-panel"><summary>{t('Confidential payment details', 'Detajet konfidenciale të pagesës')} · {profile.payment_details?.bank_account_masked || '—'}</summary><Form title={t('Replace reviewed payment details', 'Ndrysho detajet e shqyrtuara')} disabled={busy} label={t('Save confidential details', 'Ruaj detajet konfidenciale')} submit={v => act(() => workforce('/employees/' + employee.id + '/private', Object.fromEntries(Object.entries(v).filter(([key, value]) => key === 'reason' || value !== '')), 'PUT'))}><Field label={t('New bank account', 'Llogaria e re bankare')} name="bank_account" autoComplete="off" /><Field label={t('Personal identifier', 'Numri personal')} name="personal_number" autoComplete="off" /><Field label={t('Reason for change', 'Arsyeja e ndryshimit')} name="reason" required minLength={3} /></Form><p className="wf-note">{t('Stored values are encrypted and masked. Changing these details invalidates unposted payroll approval; posted payroll keeps its reviewed private snapshot.', 'Vlerat ruhen të enkriptuara dhe të maskuara. Ndryshimi kërkon shqyrtim të ri të pagave të papostuara.')}</p></details>}
    {employee && tab === 'employment' && <section className="wf-panel"><div className="wf-toolbar"><h2>{t('Approved contract & compensation history', 'Historiku i kontratës dhe pagës')}</h2>{!self && compensation && <button onClick={() => setDialog({
          type: 'terms'
        })}>{t('Add effective terms revision', 'Shto versionin e kushteve')}</button>}</div><p className="wf-note">{t('Approved versions are never overwritten. Each later version explicitly supersedes its predecessor from its effective date.', 'Versionet e miratuara ruhen. Versioni i ri zëvendëson të mëparshmin nga data e përcaktuar.')}</p><div className="wf-table-wrap"><table><thead><tr><th>{t('Version / supersedes', 'Versioni / zëvendëson')}</th><th>{t('Effective from / end', 'Nga data / deri')}</th><th>{t('Contract / position', 'Kontrata / pozita')}</th>{compensation && <><th>{t('Basis', 'Baza')}</th><th className="wf-money">{t('Rate', 'Shuma')}</th><th>{t('Employer / pension', 'Punëdhënësi / pensioni')}</th></>}</tr></thead><tbody>{profile.terms.map(term => <tr key={term.id}><td>#{term.id}{term.supersedes_id ? ' → #' + term.supersedes_id : ''}</td><td>{term.effective_from.slice(0, 10)} / {term.effective_to?.slice(0, 10) || '—'}</td><td>{term.contract_type} · {term.position}</td>{compensation && <><td>{term.pay_basis}</td><td className="wf-money">{money(term.amount)}</td><td>{term.employer_classification} · {term.employee_pension_rate}% / {term.employer_pension_rate}%</td></>}</tr>)}</tbody></table></div>{latest?.schedule && <details open><summary>{t('Assigned work schedule', 'Orari i caktuar')}: {latest.schedule.name}</summary><table><tbody>{Object.entries(latest.schedule.week).map(([day, slot]) => <tr key={day}><th>{['', 'Monday / Hënë', 'Tuesday / Martë', 'Wednesday / Mërkurë', 'Thursday / Enjte', 'Friday / Premte', 'Saturday / Shtunë', 'Sunday / Diel'][day]}</th><td>{slot.start}–{slot.end}</td><td>{slot.paid_hours} h</td></tr>)}</tbody></table></details>}{!self && can('workforce.manage') && <button onClick={() => setDialog({
        type: 'schedule'
      })}>{t('Create reusable schedule', 'Krijo orar të ripërdorshëm')}</button>}</section>}
    {tab === 'records' && (self || can('workforce.manage')) && <section className="wf-panel"><div className="wf-toolbar"><h2>{t('Workforce requests & approved records', 'Kërkesat dhe regjistrimet')}</h2><button disabled={busy} onClick={openRecord}>{self ? t('Request leave', 'Kërko pushim') : t('Add workforce record', 'Shto regjistrim')}</button></div><div className="wf-toolbar"><Field label={t('Kind', 'Lloji')}><select value={kind} onChange={e => {
            setKind(e.target.value);
            setRecordPage(1);
          }}><option value="">All / Të gjitha</option>{['attendance', 'leave', 'overtime', 'bonus', 'deduction', 'advance_repayment', 'entitlement', 'shift'].map(k => <option key={k} value={k}>{k.replaceAll('_', ' ')}</option>)}</select></Field><Field label={t('Status', 'Statusi')}><select value={recordStatus} onChange={e => {
            setRecordStatus(e.target.value);
            setRecordPage(1);
          }}>{['requested', 'approved', 'rejected', 'cancelled', 'taken', ''].map(s => <option key={s} value={s}>{s || 'All / Të gjitha'}</option>)}</select></Field></div><div className="wf-table-wrap"><table><thead><tr><th>{t('Employee', 'Punonjësi')}</th><th>{t('Kind / dates', 'Lloji / datat')}</th><th>{t('Units / status', 'Njësitë / statusi')}</th>{(self || can('payroll.view')) && <th className="wf-money">{t('Approved pay effect', 'Efekti në pagë')}</th>}<th>{t('Reason', 'Arsyeja')}</th><th /></tr></thead><tbody>{records?.data.map(r => <tr key={r.id}><th>{r.employee.name}</th><td>{r.kind.replaceAll('_', ' ')}<br />{r.starts_on.slice(0, 10)} → {r.ends_on.slice(0, 10)}</td><td>{r.units} · <Status value={r.status} /></td>{(self || can('payroll.view')) && <td className="wf-money">{money(r.amount)}</td>}<td style={{
                whiteSpace: 'normal',
                maxWidth: 260
              }}>{r.reason}</td><td>{r.status === 'requested' && (self || can('workforce.approve')) && <>{!self && <><button onClick={() => setDialog({
                      type: 'decision',
                      record: r,
                      status: 'approved'
                    })}>{t('Approve', 'Mirato')}</button><button onClick={() => setDialog({
                      type: 'decision',
                      record: r,
                      status: 'rejected'
                    })}>{t('Reject', 'Refuzo')}</button></>}<button onClick={() => setDialog({
                    type: 'decision',
                    record: r,
                    status: 'cancelled'
                  })}>{t('Cancel request', 'Anulo kërkesën')}</button></>}{!self && can('workforce.approve') && r.kind === 'leave' && r.status === 'approved' && <><button onClick={() => setDialog({
                    type: 'decision',
                    record: r,
                    status: 'taken'
                  })}>{t('Mark taken', 'Shëno si përdorur')}</button><button onClick={() => setDialog({
                    type: 'decision',
                    record: r,
                    status: 'cancelled'
                  })}>{t('Cancel leave', 'Anulo pushimin')}</button></>}</td></tr>)}</tbody></table></div><Pager page={records} change={setRecordPage} /></section>}
    {employee && tab === 'payroll' && <section className="wf-panel"><h2>{t('Posted payroll history', 'Historiku i pagave të postuara')}</h2><table><thead><tr><th>{t('Period', 'Periudha')}</th><th>{t('Status', 'Statusi')}</th><th className="wf-money">{t('Gross', 'Bruto')}</th><th className="wf-money">{t('Net', 'Neto')}</th><th /></tr></thead><tbody>{profile.payroll.map(l => <tr key={l.id}><th>{self ? l.run.period.name : <Link to={'/payroll?run=' + l.run.id}>{l.run.period.name}</Link>}</th><td><Status value={l.run.status} /></td><td className="wf-money">{money(l.gross)}</td><td className="wf-money">{money(l.net)}</td><td><button disabled={busy} onClick={() => act(async () => setSlip(await payroll('/payslips/' + l.id)))}>{t('Payslip', 'Fletëpagesa')}</button></td></tr>)}</tbody></table></section>}
    {employee && !self && tab === 'documents' && can('employees.documents') && <section className="wf-panel"><p className="wf-note">{t('Contracts and HR evidence must be confidential or restricted. Compensation documents should link to the exact employment terms version.', 'Kontratat dhe dokumentet e personelit duhet të jenë konfidenciale ose të kufizuara.')}</p><EntityDocuments entityType="employee" entityId={employee.id} />{compensation && latest && <EntityDocuments entityType="employment-term" entityId={latest.id} compact />}</section>}
    {employee && tab === 'activity' && <section className="wf-panel"><h2>{t('Employment activity', 'Aktiviteti i punësimit')}</h2><table><tbody>{profile.activity.map(a => <tr key={a.id}><th>{a.created_at}</th><td>{a.description}</td><td>{a.new_value?.reason || '—'}</td><td>#{a.user_id}</td></tr>)}</tbody></table></section>}
    {dialog?.type === 'employee' && <Modal busy={busy} title={dialog.employee ? t('Edit employee', 'Ndrysho punonjësin') : t('Add employee', 'Shto punonjës')} close={() => setDialog(null)}><Form title={t('Employee profile — login is optional', 'Profili — hyrja në AIMS është opsionale')} disabled={busy} label={t('Save employee', 'Ruaj punonjësin')} submit={v => act(async () => {
        const data = {
          ...v,
          user_id: v.user_id ? Number(v.user_id) : null,
          manager_id: v.manager_id ? Number(v.manager_id) : null,
          termination_date: v.termination_date || null,
          revoke_access: v.revoke_access === 'on'
        };
        const e = await workforce('/employees' + (dialog.employee ? '/' + dialog.employee.id : ''), data, dialog.employee ? 'PUT' : 'POST');
        setDialog(null);
        setParams({
          employee: e.id
        });
      })}>
      <Field label={t('Employee number', 'Numri i punonjësit')} name="employee_number" required defaultValue={dialog.employee?.employee_number} /><Field label={t('Full name', 'Emri i plotë')} name="name" required defaultValue={dialog.employee?.name} /><Field label="Email" name="email" type="email" defaultValue={dialog.employee?.email || ''} /><Field label={t('Phone', 'Telefoni')} name="phone" defaultValue={dialog.employee?.phone || ''} /><Field label={t('Address', 'Adresa')} name="address" defaultValue={dialog.employee?.address || ''} /><Field label={t('Department', 'Departamenti')} name="department" defaultValue={dialog.employee?.department || ''} /><Field label={t('Position', 'Pozita')} name="position" defaultValue={dialog.employee?.position || ''} /><Field label={t('Start date', 'Data e fillimit')} name="start_date" type="date" required defaultValue={dialog.employee?.start_date.slice(0, 10) || new Date().toISOString().slice(0, 10)} /><Field label={t('Termination date', 'Data e përfundimit')} name="termination_date" type="date" defaultValue={dialog.employee?.termination_date?.slice(0, 10) || ''} /><Field label={t('Status', 'Statusi')}><select name="status" defaultValue={dialog.employee?.status || 'draft'}>{['draft', 'active', 'on_leave', 'suspended', 'terminated', 'archived'].map(s => <option key={s} value={s}>{s}</option>)}</select></Field><Field label={t('Payment method', 'Mënyra e pagesës')}><select name="payment_method" defaultValue={dialog.employee?.payment_method || 'bank'}><option value="bank">Bank / Bankë</option><option value="cash">Cash / Para të gatshme</option></select></Field><Field label={t('Optional linked user', 'Përdoruesi i lidhur')}><select name="user_id" defaultValue={dialog.employee?.user_id || ''}><option value="">{t('No AIMS login', 'Pa hyrje në AIMS')}</option>{options?.users.map(u => <option key={u.id} value={u.id}>{u.name} · {u.email}</option>)}</select></Field><Field label={t('Manager', 'Menaxheri')}><select name="manager_id" defaultValue={dialog.employee?.manager_id || ''}><option value="">—</option>{options?.employees.filter(e => e.id !== dialog.employee?.id).map(e => <option key={e.id} value={e.id}>{e.name}</option>)}</select></Field>{!dialog.employee && can('employees.compensation') && <><Field label={t('Bank account — confidential', 'Llogaria bankare — konfidenciale')} name="bank_account" /><Field label={t('Personal identifier — confidential', 'Numri personal — konfidencial')} name="personal_number" /></>}{dialog.employee && <Field label={t('Lifecycle reason', 'Arsyeja e ndryshimit')} name="lifecycle_reason" minLength={3} />} {dialog.employee && can('users.manage') && <label><input type="checkbox" name="revoke_access" />{t('Revoke linked AIMS access on termination', 'Çaktivizo hyrjen në AIMS pas përfundimit')}</label>}
    </Form></Modal>}
    {dialog?.type === 'terms' && employee && <Modal busy={busy} title={t('Approve effective employment terms', 'Mirato kushtet sipas datës')} close={() => setDialog(null)}><Form title={employee.name} disabled={busy} label={t('Approve new terms version', 'Mirato versionin e ri')} submit={v => act(async () => {
        await workforce('/employees/' + employee.id + '/terms', {
          ...v,
          currency: 'EUR',
          proration_policy: 'calendar_days',
          effective_to: v.effective_to || null,
          probation_until: v.probation_until || null,
          work_schedule_id: v.work_schedule_id ? Number(v.work_schedule_id) : null,
          ...(latest ? {
            supersedes_id: latest.id
          } : {})
        }, 'POST');
        setDialog(null);
      })}><Field label={t('Effective from', 'Nga data')} name="effective_from" type="date" required defaultValue={latest ? new Date().toISOString().slice(0, 10) : employee.start_date.slice(0, 10)} /><Field label={t('Optional end', 'Deri më (opsionale)')} name="effective_to" type="date" /><Field label={t('Contract type', 'Lloji i kontratës')} name="contract_type" required defaultValue="permanent" /><Field label={t('Position', 'Pozita')} name="position" required defaultValue={latest?.position || employee.position || ''} /><Field label={t('Department', 'Departamenti')} name="department" defaultValue={latest?.department || employee.department || ''} /><Field label={t('Probation until', 'Periudha provuese deri')} name="probation_until" type="date" /><Field label={t('Pay basis', 'Baza e pagës')}><select name="pay_basis" defaultValue={latest?.pay_basis || 'monthly'}>{['monthly', 'hourly', 'daily'].map(b => <option key={b} value={b}>{b}</option>)}</select></Field><Field label={t('Gross salary / rate EUR', 'Paga bruto / norma EUR')} name="amount" type="number" step="0.01" min="0.01" required defaultValue={latest?.amount} /><Field label={t('Work schedule', 'Orari i punës')}><select name="work_schedule_id" defaultValue={latest?.work_schedule_id || ''}><option value="">—</option>{options?.schedules.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field><Field label={t('Employer classification', 'Klasifikimi i punëdhënësit')}><select name="employer_classification" defaultValue={latest?.employer_classification || 'primary'}><option value="primary">Primary / Primar</option><option value="secondary">Secondary / Sekondar</option></select></Field><Field label={t('Pension treatment', 'Trajtimi i pensionit')}><select name="pension_treatment" defaultValue={latest?.pension_treatment || 'resident_contributor'}><option value="resident_contributor">Resident contributor</option><option value="foreign_temporary_excluded">Temporary foreign non-resident — documented exclusion</option></select></Field><Field label={t('Employee pension %', 'Pensioni i punonjësit %')} name="employee_pension_rate" type="number" min="0" max="15" step="0.001" defaultValue={latest?.employee_pension_rate || 5} required /><Field label={t('Employer pension %', 'Pensioni i punëdhënësit %')} name="employer_pension_rate" type="number" min="0" max="15" step="0.001" defaultValue={latest?.employer_pension_rate || 5} required /><Field label={t('Pension exclusion basis, if applicable', 'Baza e përjashtimit nga pensioni')} name="pension_basis" /><Field label={t('Approval reason', 'Arsyeja e miratimit')} name="reason" required minLength={3} /><Field label={t('Notes', 'Shënime')} name="notes" /></Form><p className="wf-note">{t('Explicit company policy: partial monthly salary uses calendar-day proration. This is not a claim about statutory entitlement. Overtime premiums and leave entitlements need separate approved policy.', 'Politika e kompanisë: paga e pjesshme mujore bazohet në ditë kalendarike. Shtesat dhe pushimet kërkojnë politikë të miratuar.')}</p></Modal>}
    {dialog?.type === 'record' && <Modal busy={busy} title={self ? t('Request leave', 'Kërko pushim') : t('Add workforce record', 'Shto regjistrim')} close={() => setDialog(null)}><Form title={t('Human approval required', 'Kërkohet miratim njerëzor')} disabled={busy} label={t('Submit for approval', 'Dërgo për miratim')} submit={v => act(async () => {
        const details = {
          source: 'manual',
          leave_type: v.leave_type,
          attendance_status: v.attendance_status,
          pay_policy_basis: v.pay_policy_basis,
          policy_basis: v.policy_basis,
          ...(v.shift_start ? {
            start: v.shift_start
          } : {}),
          ...(v.shift_end ? {
            end: v.shift_end
          } : {})
        };
        await workforce((self ? '/self' : '') + '/records', {
          employee_id: Number(v.employee_id),
          kind: v.kind,
          starts_on: v.starts_on,
          ends_on: v.ends_on,
          units: v.units,
          amount: v.amount,
          reason: v.reason,
          details
        }, 'POST');
        setDialog(null);
      })}><Field label={t('Employee', 'Punonjësi')}><select name="employee_id" required defaultValue={employee?.id || ''}>{self ? <option value={employee.id}>{employee.name}</option> : <><option value="">—</option>{options?.employees.map(e => <option key={e.id} value={e.id}>{e.employee_number} · {e.name}</option>)}</>}</select></Field><Field label={t('Record kind', 'Lloji i regjistrimit')}><select name="kind">{(self ? ['leave'] : ['leave', 'attendance', 'shift', 'entitlement', ...(can('payroll.manage') ? ['overtime', 'bonus', 'deduction', 'advance_repayment'] : [])]).map(k => <option key={k} value={k}>{k.replaceAll('_', ' ')}</option>)}</select></Field><Field label={t('Start date', 'Data e fillimit')} name="starts_on" type="date" required defaultValue={new Date().toISOString().slice(0, 10)} /><Field label={t('End date', 'Data e mbarimit')} name="ends_on" type="date" required defaultValue={new Date().toISOString().slice(0, 10)} /><Field label={t('Units — leave days / attendance hours', 'Njësi — ditë pushimi / orë pranie')} name="units" type="number" min="0" step="0.25" required defaultValue="1" /><Field label={t('Explicit pay effect EUR — overtime / bonus / unpaid leave', 'Efekti në pagë EUR — shtesa / pushim pa pagesë')} name="amount" type="number" min="0" step="0.01" required defaultValue="0" readOnly={self} /><Field label={t('Leave type', 'Lloji i pushimit')}><select name="leave_type"><option value="annual">Annual / Vjetor</option><option value="sick">Sick / Mjekësor</option><option value="unpaid">Unpaid / Pa pagesë</option><option value="other">Other / Tjetër</option></select></Field><Field label={t('Attendance state', 'Gjendja e pranisë')}><select name="attendance_status">{['present', 'scheduled', 'late', 'absent', 'leave', 'sick', 'other'].map(s => <option key={s} value={s}>{s}</option>)}</select></Field><Field label={t('Pay policy / unpaid leave basis', 'Baza e politikës së pagesës')} name="pay_policy_basis" /><Field label={t('Entitlement policy basis', 'Baza e të drejtës së pushimit')} name="policy_basis" /><Field label={t('Shift start', 'Fillimi i ndërrimit')} name="shift_start" type="time" /><Field label={t('Shift end', 'Fundi i ndërrimit')} name="shift_end" type="time" /><Field label={t('Reason', 'Arsyeja')} name="reason" required minLength={3} /></Form><p className="wf-note">{t('Amounts apply only after explicit approval. Unpaid leave reduces gross earnings; ordinary deductions and advance repayments reduce net salary. No overtime legal premium is assumed.', 'Shumat zbatohen vetëm pas miratimit. Pushimi pa pagesë ul bruto; zbritjet dhe kthimet e paradhënies ulin neto.')}</p></Modal>}
    {dialog?.type === 'decision' && <Modal busy={busy} title={t('Review workforce decision', 'Shqyrto vendimin')} close={() => setDialog(null)}><p>{dialog.record.employee.name} · {dialog.record.kind} → {dialog.status}</p><Form title={t('Decision reason', 'Arsyeja e vendimit')} disabled={busy} label={t('Confirm decision', 'Konfirmo vendimin')} submit={v => act(async () => {
        await workforce((self ? '/self' : '') + '/records/' + dialog.record.id + '/decision', {
          ...v,
          status: dialog.status
        }, 'POST');
        setDialog(null);
      })}><Field label={t('Reason', 'Arsyeja')} name="reason" minLength={3} required /></Form></Modal>}
    {dialog?.type === 'advance' && <Modal busy={busy} title={t('Record approved employee advance', 'Regjistro paradhënien e miratuar')} close={() => setDialog(null)}><p className="wf-note">{t('Employee advance asset → Cash/Bank. Repayments remain linked to posted payroll and never disappear from the advance balance.', 'Aktivi i paradhënies → Arka/Banka. Kthimet lidhen me pagat e postuara.')}</p><Form title={employee.name} disabled={busy} label={t('Record advance payment', 'Regjistro pagesën e paradhënies')} submit={v => act(async () => {
        await workforce('/employees/' + employee.id + '/advances', {
          ...v,
          idempotency_key: advanceKey.current
        }, 'POST');
        setDialog(null);
      })}><Field label={t('Payment account', 'Llogaria e pagesës')}><select name="financial_account_id" required><option value="">—</option>{options?.payment_accounts.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select></Field><Field label={t('Amount', 'Shuma')} name="amount" type="number" min="0.01" step="0.01" required /><Field label={t('Paid on', 'Data e pagesës')} name="paid_on" type="date" required defaultValue={new Date().toISOString().slice(0, 10)} /><Field label={t('Approved reason', 'Arsyeja e miratuar')} name="reason" required minLength={3} /></Form></Modal>}
    {dialog?.type === 'schedule' && <Modal busy={busy} title={t('Create reusable schedule', 'Krijo orar të ripërdorshëm')} close={() => setDialog(null)}><Form title={t('Weekday schedule template', 'Modeli javor i orarit')} disabled={busy} label={t('Create schedule', 'Krijo orarin')} submit={v => act(async () => {
        const week = Object.fromEntries([1, 2, 3, 4, 5, 6, 7].filter(day => v['day_' + day] === 'on').map(day => [day, {
          start: v.start,
          end: v.end,
          paid_hours: v.paid_hours
        }]));
        await workforce('/schedules', {
          name: v.name,
          week
        }, 'POST');
        setDialog(null);
      })}><Field label={t('Schedule name', 'Emri i orarit')} name="name" required /><Field label={t('Start time', 'Ora e fillimit')} name="start" type="time" defaultValue="08:00" required /><Field label={t('End time', 'Ora e përfundimit')} name="end" type="time" defaultValue="16:00" required /><Field label={t('Paid hours per scheduled day', 'Orë të paguara në ditë')} name="paid_hours" type="number" min="0.25" max="24" step="0.25" defaultValue="8" required />{['Monday / Hënë', 'Tuesday / Martë', 'Wednesday / Mërkurë', 'Thursday / Enjte', 'Friday / Premte', 'Saturday / Shtunë', 'Sunday / Diel'].map((day, i) => <label key={day}><input type="checkbox" name={'day_' + (i + 1)} defaultChecked={i < 5} />{day}</label>)}</Form><p className="wf-note">{t('Schedules are versioned by creating a new template. Night shifts may cross midnight; approved attendance is authoritative for hourly/daily earnings.', 'Orari ndryshohet me model të ri. Prania e miratuar është burim për pagesën me orë/ditë.')}</p></Modal>}
    {slip && <Payslip data={slip} close={() => setSlip(null)} />}
  </main>;
}
