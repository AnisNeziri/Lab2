import { useState } from "react";
import { Link } from "react-router-dom";
import * as api from "../../api/accounting";
import WorkspaceModal from "../WorkspaceModal";
import { Amount, Field, MoneyTable, QueryState, errorMessage, useFinance, useFinanceQuery } from "./FinanceShared";

const emptyAdjustment = () => ({ direction: "add", amount: "", reason: "", journal_entry_id: "" });

export default function FinanceCorporateTaxReview({ period, onClose }) {
  const { t, can, run, busy: saving } = useFinance();
  const previous = useFinanceQuery(() => api.getTaxReview(period.id), [period.id]);
  const [rate, setRate] = useState("");
  const [reference, setReference] = useState("");
  const [lines, setLines] = useState([]);
  const [calculation, setCalculation] = useState(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const canApprove = period.status === "open" && can("accounting.periods.close") && can("accounting.journal.post");
  const change = (index, key, value) => {
    setCalculation(null);
    setLines(current => current.map((row, i) => i === index ? { ...row, [key]: value } : row));
  };
  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError("");
    const payload = { rate_percent: rate, policy_reference: reference, adjustments: lines.map(row => ({ ...row, journal_entry_id: Number(row.journal_entry_id) || null })) };
    try {
      if (calculation && canApprove) {
        await run(async () => {
          try {
            await api.approveTaxReview(period.id, { ...payload, fingerprint: calculation.fingerprint });
            onClose();
          } catch (err) {
            setError(errorMessage(err));
            throw err;
          }
        });
      } else {
        setCalculation(await api.previewTaxReview(period.id, payload));
      }
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };
  const totals = [
    ["profit_before_tax", t("Posted profit before tax", "Fitimi i postuar para tatimit")],
    ["additions", t("Additions", "Shtesat")], ["deductions", t("Deductions", "Zbritjet")],
    ["taxable_profit", t("Taxable profit", "Fitimi i tatueshëm")],
    ["current_tax", t("Current tax estimate", "Tatimi aktual i llogaritur")],
  ];
  return <WorkspaceModal title={t("Corporate income tax worksheet", "Fleta e tatimit në të ardhurat e korporatave")} onClose={onClose} wide>
    <p>{t("Use this internal worksheet for an approved actual-income regime. Enter separately supported tax adjustments, including the difference between book and tax depreciation.", "Përdore këtë fletë të brendshme për regjimin e miratuar sipas të ardhurave reale. Shëno korrigjimet e dokumentuara, duke përfshirë dallimin mes zhvlerësimit kontabël dhe atij tatimor.")}</p>
    <QueryState query={previous}>{saved => saved?.snapshot && <p>
      {t("Latest approved current tax", "Tatimi aktual i miratuar së fundi")} <Amount value={saved.snapshot.current_tax} currency="EUR" />
      {" "}{saved.journal_entry_id && <Link to={`/accounting?tab=journal&journal=${saved.journal_entry_id}`}>{t("Open journal", "Hap ditarin")}</Link>}
      {period.status === "open" && <button type="button" className="secondary" onClick={() => {
        setRate(saved.snapshot.rate_percent); setReference(saved.snapshot.policy_reference); setLines(saved.snapshot.adjustments); setCalculation(null);
      }}>{t("Load approved inputs", "Ngarko të dhënat e miratuara")}</button>}
    </p>}</QueryState>
    {error && <p className="fc-error" role="alert">{error}</p>}
    <form className="fc-form" onSubmit={submit}>
      <div className="fc-form-grid">
        <Field label={t("Approved rate (%)", "Norma e miratuar (%)")} required type="number" step="0.0001" min="0" max="100" value={rate} onChange={e => { setCalculation(null); setRate(e.target.value); }} />
        <Field label={t("Regime and approval reference", "Regjimi dhe referenca e miratimit")} required minLength={5} maxLength={500} value={reference} onChange={e => { setCalculation(null); setReference(e.target.value); }} />
      </div>
      {lines.map((line, index) => <fieldset className="fc-form-grid" key={index}>
        <legend>{t("Tax adjustment", "Korrigjim tatimor")} {index + 1}</legend>
        <Field label={t("Treatment", "Trajtimi")}><select value={line.direction} onChange={e => change(index, "direction", e.target.value)}>
          <option value="add">{t("Add to profit", "Shto në fitim")}</option>
          <option value="deduct">{t("Deduct from profit", "Zbrit nga fitimi")}</option>
        </select></Field>
        <Field label={t("EUR amount", "Shuma në EUR")} required type="number" step="0.01" min="0.01" value={line.amount} onChange={e => change(index, "amount", e.target.value)} />
        <Field label={t("Evidence or reason", "Dëshmia ose arsyeja")} required minLength={5} maxLength={500} value={line.reason} onChange={e => change(index, "reason", e.target.value)} />
        <button type="button" className="secondary" onClick={() => { setCalculation(null); setLines(current => current.filter((_, i) => i !== index)); }}>{t("Remove", "Hiq")}</button>
      </fieldset>)}
      <button type="button" className="secondary" onClick={() => { setCalculation(null); setLines(current => [...current, emptyAdjustment()]); }}>{t("Add an adjustment", "Shto një korrigjim")}</button>
      {calculation && <MoneyTable label={t("Tax calculation", "Llogaritja tatimore")}><tbody>{totals.map(([key, label]) => <tr key={key}><th scope="row">{label}</th><td><Amount value={calculation[key]} currency="EUR" /></td></tr>)}</tbody></MoneyTable>}
      <div className="fc-toolbar">
        <button disabled={busy || saving || (calculation && !canApprove)}>{calculation ? t("Approve and post current tax", "Mirato dhe posto tatimin aktual") : t("Calculate for review", "Llogarit për shqyrtim")}</button>
      </div>
    </form>
  </WorkspaceModal>;
}
