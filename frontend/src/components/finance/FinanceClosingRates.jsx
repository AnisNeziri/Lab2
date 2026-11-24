import { useState } from "react";
import { Link } from "react-router-dom";
import * as api from "../../api/accounting";
import WorkspaceModal from "../WorkspaceModal";
import { Amount, Empty, Field, MoneyTable, QueryState, errorMessage, useFinance, useFinanceQuery } from "./FinanceShared";

export default function FinanceClosingRates({ period, onClose }) {
  const { t, can, run } = useFinance();
  const source = useFinanceQuery(() => api.previewClosingRates(period.id), [period.id]);
  const [rates, setRates] = useState({});
  const [preview, setPreview] = useState(null);
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const date = source.data?.date;
  const savedRates = Object.fromEntries(Object.entries(source.data?.rates || {}).map(([code, rate]) => [code, {
    exchange_rate: rate.rate, exchange_rate_date: rate.date, exchange_rate_source: rate.source,
  }]));
  const activeRates = { ...savedRates, ...rates };
  const canApprove = can("accounting.periods.close") && can("accounting.journal.post") && period.status === "open";
  const changeRate = (code, field, value) => {
    setPreview(null);
    setRates(current => ({ ...current, [code]: { exchange_rate_date: date, ...savedRates[code], ...current[code], [field]: value } }));
  };
  const submit = async (event, approving) => {
    event.preventDefault();
    setBusy(true);
    setError("");
    try {
      if (approving) {
        await run(async () => {
          try {
            await api.approveClosingRates(period.id, { rates: activeRates, fingerprint: preview.fingerprint, reason });
            onClose();
          } catch (err) {
            setError(errorMessage(err));
            throw err;
          }
        });
      } else {
        setPreview(await api.previewClosingRates(period.id, activeRates));
      }
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };
  return <WorkspaceModal title={t("Closing currency review", "Shqyrtimi i valutave në mbyllje")} onClose={onClose}>
    {error && <p className="fc-error" role="alert">{error}</p>}
    <QueryState query={source}>{data => {
      const codes = [...new Set(data.items.map(position => position.currency))];
      const result = preview || data;
      return <>
        {data.last_approval?.journal_entry_id && <p><Link to={`/accounting?tab=journal&journal=${data.last_approval.journal_entry_id}`}>{t("Latest approved closing journal", "Ditari i fundit i miratuar për mbyllje")}</Link></p>}
        {!codes.length && !data.issues.length && <Empty>{t("There are no foreign receivables or payables for this date.", "Në këtë datë nuk ka të arkëtueshme apo detyrime në valuta të huaja.")}</Empty>}
        {result.issues.map(issue => <p key={issue} className="fc-caption">{issue}</p>)}
        {codes.length > 0 && <form className="fc-form fc-closing-rate-form" onSubmit={e => submit(e, canApprove && !!preview && !preview.issues.length)}>
          <p>{t("Enter the approved EUR rate and evidence for each currency. The adjustment reverses on the first day of the next period.", "Shëno kursin e miratuar në EUR dhe dëshminë për çdo valutë. Korrigjimi anulohet ditën e parë të periudhës tjetër.")}</p>
          {codes.map(code => <div className="fc-form-grid" key={code}>
            <Field label={t(`EUR per 1 ${code}`, `EUR për 1 ${code}`)} type="number" min=".00000001" step=".00000001" required value={activeRates[code]?.exchange_rate ?? ""} onChange={e => changeRate(code, "exchange_rate", e.target.value)} />
            <Field label={t(`${code} rate date`, `Data e kursit ${code}`)} type="date" max={date} required value={activeRates[code]?.exchange_rate_date ?? date} onChange={e => changeRate(code, "exchange_rate_date", e.target.value)} />
            <Field label={t(`${code} rate evidence`, `Dëshmia e kursit ${code}`)} required maxLength={255} value={activeRates[code]?.exchange_rate_source ?? ""} onChange={e => changeRate(code, "exchange_rate_source", e.target.value)} />
          </div>)}
          <MoneyTable label={t("Foreign positions", "Gjendjet në valuta të huaja")}>
            <thead><tr><th>{t("Source", "Burimi")}</th><th>{t("Open amount", "Shuma e hapur")}</th><th>{t("Recorded EUR", "EUR të regjistruara")}</th><th>{t("Closing EUR", "EUR në mbyllje")}</th></tr></thead>
            <tbody>{result.items.map(row => <tr key={`${row.side}-${row.id}`}>
              <td><Link to={row.side === "ar" ? `/invoices?invoice=${row.id}` : `/finance?expense=${row.id}`}>{row.reference}</Link></td>
              <td><Amount value={row.nominal} currency={row.currency} /></td><td><Amount value={row.carrying} currency="EUR" /></td><td><Amount value={row.closing} currency="EUR" /></td>
            </tr>)}</tbody>
          </MoneyTable>
          {preview && !preview.issues.length ? <>
            <Field label={t("Review reference or reason", "Referenca e shqyrtimit ose arsyeja")} required minLength={5} maxLength={1000} value={reason} onChange={e => setReason(e.target.value)} />
            {canApprove &&
              <button disabled={busy}>{t("Approve and post revaluation", "Mirato dhe posto rivlerësimin")}</button>}
          </> : <button disabled={busy}>{t("Preview closing adjustment", "Shiko korrigjimin e mbylljes")}</button>}
        </form>}
      </>;
    }}</QueryState>
  </WorkspaceModal>;
}
