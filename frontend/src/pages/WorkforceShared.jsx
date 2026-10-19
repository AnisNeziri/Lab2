import { cloneElement, useId, useState } from 'react';
import { useTranslation } from '../hooks/useTranslation';
import { money, payrollLabels } from './workforcePresentation';
import './Workforce.css';
import WorkspaceModal from '../components/WorkspaceModal';
import { businessStatus } from '../utils/businessStatus';
export function useWorkforceText() {
  const {
    language
  } = useTranslation();
  return (en, sq) => language === 'sq' ? sq : en;
}
export function Field({
  label,
  children,
  ...props
}) {
  const id = useId();
  return <div className="wf-field"><label htmlFor={id}>{label}</label>{children ? cloneElement(children, {
      id
    }) : <input id={id} {...props} />}</div>;
}
export function Status({
  value
}) {
  const { language } = useTranslation();
  return <span className={'wf-status wf-' + value}>{businessStatus(value, language)}</span>;
}
export function Modal({
  title,
  close,
  busy = false,
  children
}) {
  return <WorkspaceModal title={title} onClose={close} busy={busy} protectForms closeOnBackdrop className="wf-dialog">{children}</WorkspaceModal>;
}
export function Form({
  submit,
  children,
  title,
  label = 'Save',
  disabled = false
}) {
  return <form className="wf-form" onSubmit={e => {
    e.preventDefault();
    const form = e.currentTarget;
    submit(Object.fromEntries(new FormData(form)));
  }} aria-busy={disabled}><h3>{title}</h3><fieldset className="wf-fields" disabled={disabled}>{children}</fieldset><div className="workspace-sticky-actions"><button disabled={disabled} type="submit">{label}</button></div></form>;
}
export function Pager({
  page,
  change
}) {
  return page && page.last_page > 1 ? <div className="wf-toolbar"><button disabled={page.current_page === 1} onClick={() => change(page.current_page - 1)}>←</button><span>{page.current_page} / {page.last_page}</span><button disabled={page.current_page === page.last_page} onClick={() => change(page.current_page + 1)}>→</button></div> : null;
}
export function Payslip({
  data,
  close
}) {
  const {language}=useTranslation();
  const [lang, setLang] = useState(language),
    t = (en, sq) => lang === 'sq' ? sq : en,
    l = data.line,
    e = l.snapshot.employee,
    components = l.snapshot.components;
  return <Modal title={t('Confidential payslip', 'Fletëpagesë konfidenciale')} close={close}><div className="wf-toolbar wf-no-print"><select aria-label="Payslip language" value={lang} onChange={e => setLang(e.target.value)}><option value="en">English</option><option value="sq">Shqip</option></select><button onClick={() => window.print()}>{t('Print / save PDF', 'Shtyp / ruaj PDF')}</button></div><article className="wf-payslip"><header><div><small>AIMS · {t('CONFIDENTIAL', 'KONFIDENCIALE')}</small><h2>{data.company.name}</h2><p>{l.run.period.name} · {t('Payslip', 'Fletëpagesë')} PAY-{l.payroll_run_id}-{l.id}</p></div><Status value={l.run.status} /></header><h3>{e.name}</h3><p>{e.employee_number} · {e.position} · {e.department}</p><table><tbody><tr><th>{t('Base earnings', 'Paga bazë')}</th><td>{money(components.base)}</td></tr><tr><th>{t('Bonus / overtime', 'Bonus / punë shtesë')}</th><td>{money(Number(components.bonus) + Number(components.overtime))}</td></tr><tr><th>{t('Unpaid leave reduction', 'Zbritja për pushim pa pagesë')}</th><td>−{money(components.unpaid_leave_reduction)}</td></tr>{['gross', 'employee_pension', 'taxable', 'tax', 'deductions', 'advance_repayment', 'net', 'employer_pension', 'employer_cost'].map(key => <tr key={key} className={key === 'net' ? 'wf-total' : ''}><th>{t(...payrollLabels[key])}</th><td>{['employee_pension', 'tax', 'deductions', 'advance_repayment'].includes(key) ? '−' : ''}{money(l[key])}</td></tr>)}<tr><th>{t('Paid', 'Paguar')}</th><td>{money(data.paid)}</td></tr><tr><th>{t('Remaining', 'Mbetja')}</th><td>{money(data.remaining)}</td></tr></tbody></table><p>{t('Rule version', 'Versioni i rregullave')}: {l.snapshot.rule.version}</p><small>{t('Employer pension is an additional company cost. This payslip is not an EDI declaration.', 'Pensioni i punëdhënësit është kosto shtesë e kompanisë. Kjo fletëpagesë nuk është deklaratë EDI.')}</small></article></Modal>;
}
