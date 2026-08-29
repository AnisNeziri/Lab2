import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useNavigate } from "react-router-dom";
import {
  AlertTriangle,
  BadgeEuro,
  BanknoteArrowDown,
  BanknoteArrowUp,
  BookOpenCheck,
  CalendarClock,
  ChevronLeft,
  ChevronRight,
  CircleDollarSign,
  Download,
  FileSpreadsheet,
  Landmark,
  LoaderCircle,
  Pencil,
  Plus,
  ReceiptText,
  RefreshCw,
  Search,
  Trash2,
  TrendingUp,
  Upload,
  WalletCards,
  X,
} from "lucide-react";
import {
  createExpense,
  deleteExpense,
  downloadExpenseProof,
  downloadVatBooks,
  getCashFlow,
  getExpenses,
  getFinanceOverview,
  getReceivablesAging,
  getVatBooks,
  postExpense,
  recordExpensePayment,
  reverseExpense,
  reverseExpensePayment,
  updateExpense,
  uploadExpenseProof,
} from "../api/finance";
import { getFinancialAccounts } from "../api/advancedOperations";
import { useTranslation } from "../hooks/useTranslation";
import { useAuthStore } from "../store/authStore";
import "./FinanceCenter.css";

const todayIso = () => {
  const date = new Date();
  date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
  return date.toISOString().slice(0, 10);
};

const iso = (date) => {
  const copy = new Date(date);
  copy.setMinutes(copy.getMinutes() - copy.getTimezoneOffset());
  return copy.toISOString().slice(0, 10);
};

function periodFor(preset) {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth();
  if (preset === "previous_month") {
    return { date_from: iso(new Date(year, month - 1, 1)), date_to: iso(new Date(year, month, 0)) };
  }
  if (preset === "quarter") {
    const quarterMonth = Math.floor(month / 3) * 3;
    return { date_from: iso(new Date(year, quarterMonth, 1)), date_to: iso(new Date(year, quarterMonth + 3, 0)) };
  }
  if (preset === "year") {
    return { date_from: `${year}-01-01`, date_to: todayIso() };
  }
  return { date_from: iso(new Date(year, month, 1)), date_to: iso(new Date(year, month + 1, 0)) };
}

const firstValue = (source, keys, fallback = null) => {
  for (const key of keys) {
    if (source?.[key] !== undefined && source?.[key] !== null) return source[key];
  }
  return fallback;
};

const asRows = (payload, keys = []) => {
  if (Array.isArray(payload)) return payload;
  for (const key of keys) if (Array.isArray(payload?.[key])) return payload[key];
  if (Array.isArray(payload?.data)) return payload.data;
  return [];
};

function errorMessage(error, fallback) {
  const validation = error?.errors ? Object.values(error.errors).flat().filter(Boolean) : [];
  return validation.length ? validation.join(" ") : (error?.message || fallback);
}

function MetricCard({ icon: Icon, label, value, tone = "blue", hint }) {
  return (
    <article className={`finance-metric tone-${tone}`}>
      <span className="finance-metric-icon"><Icon size={20} /></span>
      <div><span>{label}</span><strong>{value}</strong>{hint ? <small>{hint}</small> : null}</div>
    </article>
  );
}

function EmptyState({ icon: Icon = ReceiptText, title, text, action }) {
  return (
    <div className="finance-empty">
      <Icon size={34} />
      <strong>{title}</strong>
      <p>{text}</p>
      {action}
    </div>
  );
}

function LoadingState({ label }) {
  return (
    <div className="finance-loading" role="status">
      <LoaderCircle className="is-spinning" size={24} />
      <span>{label}</span>
    </div>
  );
}

function useModalLifecycle(open, busy, onClose, dialogRef, initialRef) {
  const busyRef = useRef(busy);
  const restoreRef = useRef(null);
  useEffect(() => { busyRef.current = busy; }, [busy]);
  useEffect(() => {
    if (!open) return undefined;
    restoreRef.current = document.activeElement;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.setTimeout(() => (initialRef?.current || dialogRef.current)?.focus?.(), 0);
    const keydown = (event) => {
      if (event.key === "Escape" && !busyRef.current) onClose();
      if (event.key !== "Tab" || !dialogRef.current) return;
      const focusable = [...dialogRef.current.querySelectorAll("button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex='-1'])")];
      if (!focusable.length) return;
      const first = focusable[0]; const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener("keydown", keydown);
    return () => { document.body.style.overflow = previous; document.removeEventListener("keydown", keydown); restoreRef.current?.focus?.(); };
  }, [open, onClose, dialogRef, initialRef]);
}

function DataTable({ columns, rows, rowKey = "id", emptyTitle, emptyText }) {
  if (!rows.length) return <EmptyState title={emptyTitle} text={emptyText} />;
  return (
    <div className="finance-table-wrap">
      <table className="finance-table">
        <thead><tr>{columns.map((column) => <th scope="col" key={column.key}>{column.label}</th>)}</tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={row[rowKey] ?? `${rowKey}-${index}`}>
              {columns.map((column) => <td key={column.key}>{column.render ? column.render(row) : (row[column.key] ?? "—")}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const emptyExpense = () => ({
  invoice_date: todayIso(),
  received_date: todayIso(),
  supply_date: todayIso(),
  due_date: "",
  vendor_name: "",
  vendor_business_number: "",
  vendor_fiscal_number: "",
  vendor_vat_number: "",
  document_type: "purchase_invoice",
  document_number: "",
  original_document_number: "",
  description: "",
  source_type: "domestic",
  asset_treatment: "ordinary",
  category: "inventory",
  business_purpose: "",
  vat_treatment: "standard",
  vat_rate: "18",
  input_vat_eligible: true,
  deductible_vat_amount: "",
  tax_legal_reference: "",
  currency: "EUR",
  exchange_rate: "1",
  exchange_rate_date: todayIso(),
  exchange_rate_source: "",
  net_amount: "",
  notes: "",
  attachment: null,
});

function expenseToForm(expense) {
  return {
    ...emptyExpense(),
    ...expense,
    invoice_date: String(expense.invoice_date || expense.expense_date || expense.date || todayIso()).slice(0, 10),
    received_date: String(expense.received_date || expense.invoice_date || expense.expense_date || todayIso()).slice(0, 10),
    supply_date: String(expense.supply_date || expense.invoice_date || expense.expense_date || todayIso()).slice(0, 10),
    due_date: String(expense.due_date || "").slice(0, 10),
    net_amount: String(expense.net_amount ?? ""),
    input_vat_eligible: Boolean(expense.input_vat_eligible),
    deductible_vat_amount: String(expense.deductible_vat_amount ?? ""),
    attachment: null,
  };
}

function ExpenseModal({ open, expense, onClose, onSaved, t }) {
  const [form, setForm] = useState(emptyExpense);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const dialogRef = useRef(null);
  const restoreFocusRef = useRef(null);
  const busyRef = useRef(false);

  useEffect(() => { busyRef.current = busy; }, [busy]);

  useEffect(() => {
    if (!open) return;
    setForm(expense ? expenseToForm(expense) : emptyExpense());
    setError("");
  }, [open, expense]);

  useEffect(() => {
    if (!open) return undefined;
    restoreFocusRef.current = document.activeElement;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    window.setTimeout(() => dialogRef.current?.querySelector("input, select, textarea, button")?.focus(), 0);
    const keydown = (event) => {
      if (event.key === "Escape" && !busyRef.current) onClose();
      if (event.key !== "Tab" || !dialogRef.current) return;
      const focusable = [...dialogRef.current.querySelectorAll("button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex]:not([tabindex='-1'])")];
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    document.addEventListener("keydown", keydown);
    return () => {
      document.body.style.overflow = previous;
      document.removeEventListener("keydown", keydown);
      restoreFocusRef.current?.focus?.();
    };
  }, [open, onClose]);

  if (!open) return null;
  const change = (field, value) => setForm((current) => ({ ...current, [field]: value }));
  const treatmentHasVat = ["standard", "reduced", "reverse_charge", "import_vat"].includes(form.vat_treatment);
  const effectiveVatRate = form.vat_treatment === "standard"
    ? 18
    : form.vat_treatment === "reduced"
      ? 8
      : treatmentHasVat
        ? (Number(form.vat_rate) === 8 ? 8 : 18)
        : 0;
  const netAmount = Number(form.net_amount || 0);
  const calculatedVat = Math.round((netAmount * effectiveVatRate / 100 + Number.EPSILON) * 100) / 100;
  const selfAssessedVat = form.vat_treatment === "reverse_charge" ? calculatedVat : 0;
  const vatAmount = form.vat_treatment === "reverse_charge" ? 0 : calculatedVat;
  const claimableVat = form.vat_treatment === "reverse_charge" ? selfAssessedVat : vatAmount;
  const grossAmount = Math.round((netAmount + vatAmount + Number.EPSILON) * 100) / 100;
  const exchangeRate = form.currency === "EUR" ? 1 : Number(form.exchange_rate || 0);
  const grossEur = Math.round((grossAmount * exchangeRate + Number.EPSILON) * 100) / 100;
  const deductibleVat = form.input_vat_eligible
    ? Math.min(claimableVat, Math.max(0, Number(form.deductible_vat_amount === "" ? claimableVat : form.deductible_vat_amount)))
    : 0;

  const submit = async (event) => {
    event.preventDefault();
    if (!form.invoice_date || !form.vendor_name.trim() || !form.description.trim() || !(netAmount > 0)) {
      setError(t("finance.expenseRequired"));
      return;
    }
    setBusy(true);
    setError("");
    try {
      const { attachment, ...fields } = form;
      const payload = {
        ...fields,
        vat_rate: effectiveVatRate,
        net_amount: netAmount,
        vat_amount: vatAmount,
        self_assessed_vat_amount: selfAssessedVat,
        input_vat_eligible: Boolean(form.input_vat_eligible),
        deductible_vat_amount: deductibleVat,
        exchange_rate: Number(form.exchange_rate || 1),
      };
      const result = expense?.id ? await updateExpense(expense.id, payload) : await createExpense(payload);
      const saved = result?.expense || result?.data || result;
      let proofWarning = "";
      if (attachment instanceof File && saved?.id) {
        try {
          await uploadExpenseProof(saved.id, attachment);
        } catch {
          proofWarning = t("finance.proofUploadPartial");
        }
      }
      onSaved(saved, proofWarning);
    } catch (apiError) {
      setError(errorMessage(apiError, t("finance.expenseSaveError")));
    } finally {
      setBusy(false);
    }
  };

  return createPortal(
    <div className="finance-modal-layer" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onClose(); }}>
      <section ref={dialogRef} className="finance-modal" role="dialog" aria-modal="true" aria-labelledby="finance-expense-title" tabIndex="-1">
        <header>
          <div><span className="finance-eyebrow">{t("finance.expenseBook")}</span><h2 id="finance-expense-title">{expense ? t("finance.editExpense") : t("finance.newExpense")}</h2><p>{t("finance.expenseHint")}</p></div>
          <button type="button" className="icon-button" onClick={onClose} disabled={busy} aria-label={t("common.cancel")}><X size={20} /></button>
        </header>
        <form onSubmit={submit}>
          {error ? <div className="finance-alert is-error" role="alert"><AlertTriangle size={16} />{error}</div> : null}
          <fieldset>
            <legend>{t("finance.documentDetails")}</legend>
            <div className="finance-form-grid">
              <label><span>{t("finance.expenseDate")} *</span><input type="date" required value={form.invoice_date} onChange={(event) => { const value = event.target.value; setForm((current) => ({ ...current, invoice_date: value, received_date: current.received_date < value ? value : current.received_date, due_date: current.due_date && current.due_date < value ? value : current.due_date })); }} /></label>
              <label><span>{t("finance.receivedDate")} *</span><input type="date" required min={form.invoice_date} value={form.received_date} onChange={(event) => change("received_date", event.target.value)} /></label>
              <label><span>{t("finance.supplyDate")}</span><input type="date" value={form.supply_date} onChange={(event) => change("supply_date", event.target.value)} /></label>
              <label><span>{t("finance.dueDate")}</span><input type="date" min={form.invoice_date} value={form.due_date} onChange={(event) => change("due_date", event.target.value)} /></label>
              <label><span>{t("finance.supplier")} *</span><input required value={form.vendor_name} onChange={(event) => change("vendor_name", event.target.value)} /></label>
              <label><span>{t("finance.documentType")}</span><select value={form.document_type} onChange={(event) => change("document_type", event.target.value)}><option value="purchase_invoice">{t("finance.documentType.purchase_invoice")}</option><option value="fiscal_receipt">{t("finance.documentType.fiscal_receipt")}</option><option value="credit_note">{t("finance.documentType.credit_note")}</option><option value="customs_document">{t("finance.documentType.customs_document")}</option><option value="other">{t("finance.other")}</option></select></label>
              <label><span>{t("finance.invoiceNumber")} *</span><input required value={form.document_number} onChange={(event) => change("document_number", event.target.value)} /></label>
              <label><span>{t("finance.description")} *</span><input required value={form.description} onChange={(event) => change("description", event.target.value)} /></label>
              <label><span>{t("finance.netAmount")} *</span><input type="number" min="0.01" step="0.01" required value={form.net_amount} onChange={(event) => change("net_amount", event.target.value)} /></label>
              <label><span>{t("finance.correctionReference")}</span><input value={form.original_document_number} onChange={(event) => change("original_document_number", event.target.value)} /></label>
            </div>
            <dl className="finance-amount-preview"><div><dt>{t("finance.netAmount")}</dt><dd>{netAmount.toFixed(2)} {form.currency}</dd></div><div><dt>{t("finance.supplierVat")}</dt><dd>{vatAmount.toFixed(2)} {form.currency}</dd></div>{form.vat_treatment === "reverse_charge" ? <div className="is-self-assessed"><dt>{t("finance.selfAssessedVat")}</dt><dd>{selfAssessedVat.toFixed(2)} {form.currency}</dd></div> : null}<div><dt>{t("finance.deductibleInputVat")}</dt><dd>{deductibleVat.toFixed(2)} {form.currency}</dd></div><div><dt>{t("finance.supplierPayable")}</dt><dd>{grossAmount.toFixed(2)} {form.currency}{form.currency !== "EUR" && exchangeRate > 0 ? <small> ≈ {grossEur.toFixed(2)} EUR</small> : null}</dd></div></dl>
          </fieldset>
          <details className="finance-advanced">
            <summary>{t("finance.advancedClassification")}</summary>
            <p>{t("finance.advancedClassificationHint")}</p>
            <fieldset>
              <legend>{t("finance.supplierIdentifiers")}</legend>
              <p className="finance-fieldset-hint">{t("finance.supplierIdentifiersHint")}</p>
              <div className="finance-form-grid three">
                <label><span>{t("finance.businessNumber")}</span><input value={form.vendor_business_number} onChange={(event) => change("vendor_business_number", event.target.value)} /></label>
                <label><span>{t("finance.fiscalNumber")}</span><input value={form.vendor_fiscal_number} onChange={(event) => change("vendor_fiscal_number", event.target.value)} /></label>
                <label><span>{t("finance.vatNumber")}</span><input value={form.vendor_vat_number} onChange={(event) => change("vendor_vat_number", event.target.value)} /></label>
              </div>
            </fieldset>
            <fieldset>
              <legend>{t("finance.purchaseBookClassification")}</legend>
              <p className="finance-fieldset-hint">{t("finance.purchaseBookHint")}</p>
              <div className="finance-form-grid three">
                <label><span>{t("finance.origin")}</span><select value={form.source_type} onChange={(event) => change("source_type", event.target.value)}><option value="domestic">{t("finance.domestic")}</option><option value="import">{t("finance.import")}</option></select></label>
                <label><span>{t("finance.purchaseType")}</span><select value={form.asset_treatment} onChange={(event) => change("asset_treatment", event.target.value)}><option value="ordinary">{t("finance.ordinary")}</option><option value="investment">{t("finance.investment")}</option></select></label>
                <label><span>{t("finance.category")}</span><select value={form.category} onChange={(event) => change("category", event.target.value)}>{["inventory", "rent", "utilities", "transport", "salaries", "professional_services", "marketing", "advertising", "representation", "bank_fees", "insurance", "vehicle_fuel", "fines_penalties", "donations", "capital_asset", "stock_loss", "maintenance", "taxes_fees", "travel", "office", "other"].map((value) => <option key={value} value={value}>{t(`finance.category.${value}`)}</option>)}</select></label>
                {form.category !== "inventory" ? <label><span>{t("finance.businessPurpose")} *</span><input required value={form.business_purpose} onChange={(event) => change("business_purpose", event.target.value)} /></label> : null}
                <label><span>{t("finance.taxTreatment")}</span><select value={form.vat_treatment} onChange={(event) => change("vat_treatment", event.target.value)}><option value="standard">{t("finance.vatStandard")}</option><option value="reduced">{t("finance.vatReduced")}</option><option value="exempt">{t("finance.vatExempt")}</option><option value="reverse_charge">{t("finance.reverseCharge")}</option><option value="non_vat">{t("finance.nonVat")}</option><option value="import_vat">{t("finance.importVat")}</option></select></label>
                <label><span>{t("finance.vatRate")}</span><select value={String(effectiveVatRate)} disabled={["standard", "reduced", "exempt", "non_vat"].includes(form.vat_treatment)} onChange={(event) => change("vat_rate", event.target.value)}>{treatmentHasVat ? <><option value="18">18%</option><option value="8">8%</option></> : <option value="0">0%</option>}</select></label>
                <label className="finance-checkbox-field"><input type="checkbox" checked={form.input_vat_eligible} onChange={(event) => change("input_vat_eligible", event.target.checked)} /><span>{t("finance.vatEligible")}</span></label>
                {form.input_vat_eligible ? <label><span>{t("finance.deductibleVatAmount")}</span><input type="number" min="0" max={claimableVat} step="0.01" value={form.deductible_vat_amount} placeholder={claimableVat.toFixed(2)} onChange={(event) => change("deductible_vat_amount", event.target.value)} /></label> : null}
                <label><span>{t("finance.currency")}</span><select value={form.currency} onChange={(event) => change("currency", event.target.value)}><option value="EUR">EUR</option><option value="USD">USD</option><option value="GBP">GBP</option><option value="CHF">CHF</option></select></label>
                {form.currency !== "EUR" ? <><label><span>{t("finance.exchangeRate")}</span><input type="number" min="0.000001" step="0.000001" required value={form.exchange_rate} onChange={(event) => change("exchange_rate", event.target.value)} /></label><label><span>{t("finance.exchangeRateDate")}</span><input type="date" required value={form.exchange_rate_date} onChange={(event) => change("exchange_rate_date", event.target.value)} /></label><label><span>{t("finance.exchangeRateSource")}</span><input required value={form.exchange_rate_source} onChange={(event) => change("exchange_rate_source", event.target.value)} /></label></> : null}
                {!["standard", "reduced"].includes(form.vat_treatment) ? <label><span>{t("finance.legalReference")}</span><input value={form.tax_legal_reference} onChange={(event) => change("tax_legal_reference", event.target.value)} /></label> : null}
              </div>
            </fieldset>
          </details>
          <fieldset>
            <legend>{t("finance.supportingProofAndNotes")}</legend>
            <div className="finance-form-grid">
              <label className="finance-file-field"><span>{t("finance.supportingDocument")}</span><span className="finance-file-control"><Upload size={17} />{form.attachment?.name || t("finance.chooseProof")}<input type="file" accept="application/pdf,image/png,image/jpeg" onChange={(event) => { const file = event.target.files?.[0] || null; if (file && file.size > 10 * 1024 * 1024) { setError(t("finance.proofTooLarge")); event.target.value = ""; return; } change("attachment", file); }} /></span><small>{t("finance.proofHint")}</small></label>
              <label><span>{t("finance.notes")}</span><textarea rows="3" value={form.notes} onChange={(event) => change("notes", event.target.value)} /></label>
            </div>
          </fieldset>
          <footer><button type="button" className="secondary" onClick={onClose} disabled={busy}>{t("common.cancel")}</button><button type="submit" disabled={busy}>{busy ? <LoaderCircle className="is-spinning" size={16} /> : null}{expense ? t("finance.saveChanges") : t("finance.saveExpense")}</button></footer>
        </form>
      </section>
    </div>,
    document.body,
  );
}

const requestId = () => globalThis.crypto?.randomUUID?.() || `expense-payment-${Date.now()}-${Math.random().toString(16).slice(2)}`;

function ExpensePaymentModal({ expense, open, onClose, onSaved, formatMoney, t }) {
  const [form, setForm] = useState({ amount: "", payment_date: todayIso(), payment_method: "bank_transfer", financial_account_id: "", reference_number: "", note: "", exchange_rate: "", exchange_rate_date: todayIso(), exchange_rate_source: "", idempotency_key: requestId() });
  const [financialAccounts, setFinancialAccounts] = useState([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const inputRef = useRef(null);
  const dialogRef = useRef(null);
  useModalLifecycle(open, busy, onClose, dialogRef, inputRef);
  useEffect(() => {
    if (!open || !expense) return;
    setForm({ amount: String(expense.remaining_amount || ""), payment_date: todayIso(), payment_method: "bank_transfer", financial_account_id: "", reference_number: "", note: "", exchange_rate: "", exchange_rate_date: todayIso(), exchange_rate_source: "", idempotency_key: requestId() });
    setError("");
    window.setTimeout(() => inputRef.current?.focus(), 0);
  }, [open, expense]);
  useEffect(() => {
    if (!open) return undefined;
    let active = true;
    getFinancialAccounts()
      .then((response) => {
        if (!active) return;
        const accounts = response?.data || response;
        setFinancialAccounts(Array.isArray(accounts) ? accounts.filter((account) => account.is_active !== false) : []);
      })
      .catch(() => { if (active) setFinancialAccounts([]); });
    return () => { active = false; };
  }, [open]);
  if (!open || !expense) return null;
  const submit = async (event) => {
    event.preventDefault();
    if (!(Number(form.amount) > 0) || Number(form.amount) > Number(expense.remaining_amount || 0)) { setError(t("finance.paymentAmountError")); return; }
    if (expense.currency !== "EUR" && (!(Number(form.exchange_rate) > 0) || !form.exchange_rate_date || !form.exchange_rate_source.trim())) { setError(t("finance.paymentExchangeRequired")); return; }
    setBusy(true); setError("");
    try {
      const payload = {
        ...form,
        amount: Number(form.amount),
        financial_account_id: form.financial_account_id ? Number(form.financial_account_id) : undefined,
      };
      if (expense.currency === "EUR") {
        delete payload.exchange_rate;
        delete payload.exchange_rate_date;
        delete payload.exchange_rate_source;
      } else {
        payload.exchange_rate = Number(form.exchange_rate);
      }
      await recordExpensePayment(expense.id, payload);
      onSaved();
    }
    catch (apiError) { setError(errorMessage(apiError, t("finance.paymentSaveError"))); }
    finally { setBusy(false); }
  };
  const convertedPayment = expense.currency !== "EUR" && Number(form.exchange_rate) > 0
    ? Number(form.amount || 0) * Number(form.exchange_rate)
    : null;
  return createPortal(<div className="finance-modal-layer" role="presentation"><section ref={dialogRef} tabIndex="-1" className="finance-modal finance-action-modal" role="dialog" aria-modal="true" aria-labelledby="expense-payment-title"><header><div><span className="finance-eyebrow">{expense.document_number}</span><h2 id="expense-payment-title">{t("finance.recordPayment")}</h2><p>{t("finance.remainingToPay", { amount: formatMoney(expense.remaining_amount, expense.currency) })}</p></div><button type="button" className="icon-button" onClick={onClose} disabled={busy} aria-label={t("common.cancel")}><X size={20} /></button></header><form onSubmit={submit}>{error ? <div className="finance-alert is-error" role="alert"><AlertTriangle size={16} />{error}</div> : null}<div className="finance-form-grid"><label><span>{t("finance.amount")} ({expense.currency || "EUR"}) *</span><input ref={inputRef} type="number" min="0.01" max={expense.remaining_amount} step="0.01" required value={form.amount} onChange={(event) => setForm((current) => ({ ...current, amount: event.target.value }))} /></label><label><span>{t("finance.paymentDate")} *</span><input type="date" required value={form.payment_date} onChange={(event) => setForm((current) => ({ ...current, payment_date: event.target.value, exchange_rate_date: event.target.value }))} /></label><label><span>{t("finance.paymentMethod")}</span><select value={form.payment_method} onChange={(event) => setForm((current) => ({ ...current, payment_method: event.target.value }))}><option value="bank_transfer">{t("finance.bankTransfer")}</option><option value="cash">{t("finance.cash")}</option><option value="card">{t("finance.card")}</option><option value="cheque">{t("finance.cheque")}</option><option value="other">{t("finance.other")}</option></select></label><label><span>{t("payments.moneyAccount")}</span><select value={form.financial_account_id} onChange={(event) => setForm((current) => ({ ...current, financial_account_id: event.target.value }))}><option value="">{t("payments.noMoneyAccount")}</option>{financialAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} · {account.type === "cashbox" ? t("finance.cash") : t("finance.bankTransfer")} ({account.currency || "EUR"})</option>)}</select></label><label><span>{t("finance.reference")}</span><input value={form.reference_number} onChange={(event) => setForm((current) => ({ ...current, reference_number: event.target.value }))} /></label>{expense.currency !== "EUR" ? <><label><span>{t("finance.paymentExchangeRate")} *</span><input type="number" min="0.000001" step="0.000001" required value={form.exchange_rate} onChange={(event) => setForm((current) => ({ ...current, exchange_rate: event.target.value }))} /></label><label><span>{t("finance.exchangeRateDate")} *</span><input type="date" required value={form.exchange_rate_date} onChange={(event) => setForm((current) => ({ ...current, exchange_rate_date: event.target.value }))} /></label><label><span>{t("finance.exchangeRateSource")} *</span><input required value={form.exchange_rate_source} onChange={(event) => setForm((current) => ({ ...current, exchange_rate_source: event.target.value }))} /></label><div className="finance-payment-conversion"><span>{t("finance.paymentEurValue")}</span><strong>{formatMoney(convertedPayment, "EUR")}</strong></div></> : null}<label className="finance-form-wide"><span>{t("finance.notes")}</span><textarea rows="3" value={form.note} onChange={(event) => setForm((current) => ({ ...current, note: event.target.value }))} /></label></div><footer><button type="button" className="secondary" onClick={onClose} disabled={busy}>{t("common.cancel")}</button><button type="submit" disabled={busy}>{busy ? <LoaderCircle className="is-spinning" size={16} /> : null}{t("finance.savePayment")}</button></footer></form></section></div>, document.body);
}

function ExpensePaymentsModal({ expense, open, onClose, onChanged, formatMoney, date, t }) {
  const [selected, setSelected] = useState(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const dialogRef = useRef(null);
  const reasonRef = useRef(null);
  useModalLifecycle(open, busy, onClose, dialogRef, null);
  useEffect(() => { if (open) { setSelected(null); setReason(""); setError(""); } }, [open, expense]);
  if (!open || !expense) return null;
  const payments = Array.isArray(expense.payments) ? expense.payments : [];
  const paymentMethodLabel = (method) => t(method === "bank_transfer" ? "finance.bankTransfer" : `finance.${method || "other"}`);
  const submitReversal = async (event) => {
    event.preventDefault();
    if (!selected || reason.trim().length < 3) { setError(t("finance.reasonRequired")); return; }
    setBusy(true); setError("");
    try {
      await reverseExpensePayment(selected.id, reason.trim());
      onChanged();
    } catch (apiError) {
      setError(errorMessage(apiError, t("finance.paymentReverseError")));
    } finally { setBusy(false); }
  };
  return createPortal(
    <div className="finance-modal-layer" role="presentation">
      <section ref={dialogRef} tabIndex="-1" className="finance-modal finance-action-modal finance-payments-modal" role="dialog" aria-modal="true" aria-labelledby="expense-payments-title">
        <header><div><span className="finance-eyebrow">{expense.document_number}</span><h2 id="expense-payments-title">{t("finance.paymentHistory")}</h2><p>{expense.vendor_name}</p></div><button type="button" className="icon-button" onClick={onClose} disabled={busy} aria-label={t("common.cancel")}><X size={20} /></button></header>
        {error ? <div className="finance-alert is-error" role="alert"><AlertTriangle size={16} />{error}</div> : null}
        {payments.length ? <div className="finance-payment-list">{payments.map((payment) => <article key={payment.id} className={payment.status === "reversed" ? "is-reversed" : ""}><div><strong>{formatMoney(payment.amount, expense.currency)}</strong><span>{date(payment.payment_date)} · {paymentMethodLabel(payment.payment_method)}</span>{payment.reference_number ? <small>{payment.reference_number}</small> : null}</div><div><span className={`finance-status is-${payment.status}`}>{t(`finance.paymentRecord.${payment.status}`)}</span>{payment.status === "completed" ? <button type="button" className="secondary danger" disabled={busy} onClick={() => { setSelected(payment); setReason(""); setError(""); window.setTimeout(() => reasonRef.current?.focus(), 0); }}><RefreshCw size={14} />{t("finance.reversePayment")}</button> : <small>{payment.reversal_reason}</small>}</div></article>)}</div> : <EmptyState title={t("finance.noPayments")} text={t("finance.noPaymentsHint")} />}
        {selected ? <form className="finance-payment-reversal" onSubmit={submitReversal}><label><span>{t("finance.reversalReason")} *</span><textarea ref={reasonRef} rows="3" required minLength="3" value={reason} onChange={(event) => setReason(event.target.value)} /></label><footer><button type="button" className="secondary" disabled={busy} onClick={() => setSelected(null)}>{t("common.cancel")}</button><button type="submit" className="danger" disabled={busy}>{busy ? <LoaderCircle className="is-spinning" size={16} /> : null}{t("finance.confirmReversePayment")}</button></footer></form> : null}
      </section>
    </div>, document.body,
  );
}

function ExpenseReasonModal({ action, expense, open, onClose, onConfirm, t }) {
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const inputRef = useRef(null);
  const dialogRef = useRef(null);
  useModalLifecycle(open, busy, onClose, dialogRef, inputRef);
  useEffect(() => { if (open) { setReason(""); setError(""); window.setTimeout(() => inputRef.current?.focus(), 0); } }, [open]);
  if (!open || !expense) return null;
  const submit = async (event) => {
    event.preventDefault();
    if (reason.trim().length < 3) { setError(t("finance.reasonRequired")); return; }
    setBusy(true); setError("");
    try { await onConfirm(reason.trim()); }
    catch (apiError) { setError(errorMessage(apiError, t("finance.actionError"))); }
    finally { setBusy(false); }
  };
  return createPortal(<div className="finance-modal-layer" role="presentation"><section ref={dialogRef} tabIndex="-1" className="finance-modal finance-action-modal" role="dialog" aria-modal="true" aria-labelledby="expense-reason-title"><header><div><span className="finance-eyebrow">{expense.document_number}</span><h2 id="expense-reason-title">{action === "reverse" ? t("finance.reverseExpense") : t("finance.postExpense")}</h2><p>{action === "reverse" ? t("finance.reverseHint") : t("finance.postHint")}</p></div><button type="button" className="icon-button" onClick={onClose} disabled={busy} aria-label={t("common.cancel")}><X size={20} /></button></header><form onSubmit={submit}>{error ? <div className="finance-alert is-error" role="alert"><AlertTriangle size={16} />{error}</div> : null}<label className="finance-reason-field"><span>{t("finance.reason")} *</span><textarea ref={inputRef} rows="4" required minLength="3" value={reason} onChange={(event) => setReason(event.target.value)} /></label><footer><button type="button" className="secondary" onClick={onClose} disabled={busy}>{t("common.cancel")}</button><button type="submit" className={action === "reverse" ? "danger" : ""} disabled={busy}>{busy ? <LoaderCircle className="is-spinning" size={16} /> : null}{action === "reverse" ? t("finance.confirmReverse") : t("finance.confirmPost")}</button></footer></form></section></div>, document.body);
}

export default function FinanceCenter() {
  const { t, language } = useTranslation();
  const navigate = useNavigate();
  const permissions = useAuthStore((state) => state.permissions);
  const canManageExpenses = permissions.includes("expenses.manage");
  const canExportFinance = permissions.includes("finance.export");
  const canManageInvoices = permissions.includes("invoices.manage");
  const [tab, setTab] = useState("overview");
  const [preset, setPreset] = useState("this_month");
  const [period, setPeriod] = useState(() => periodFor("this_month"));
  const [data, setData] = useState({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [reloadKey, setReloadKey] = useState(0);
  const [expensePage, setExpensePage] = useState(1);
  const [expenseSearch, setExpenseSearch] = useState("");
  const [appliedSearch, setAppliedSearch] = useState("");
  const [expenseStatus, setExpenseStatus] = useState("");
  const [expenseCategory, setExpenseCategory] = useState("");
  const [expensePaymentStatus, setExpensePaymentStatus] = useState("");
  const [expenseModal, setExpenseModal] = useState({ open: false, expense: null });
  const [paymentExpense, setPaymentExpense] = useState(null);
  const [paymentHistoryExpense, setPaymentHistoryExpense] = useState(null);
  const [reversalExpense, setReversalExpense] = useState(null);
  const [actionBusy, setActionBusy] = useState("");
  const requestRef = useRef(0);

  const money = useCallback((value) => {
    if (value === null || value === undefined || value === "") return "—";
    return new Intl.NumberFormat(language === "sq" ? "sq-AL" : "en-IE", { style: "currency", currency: "EUR" }).format(Number(value));
  }, [language]);
  const formatMoney = useCallback((value, currency = "EUR") => {
    if (value === null || value === undefined || value === "") return "—";
    return new Intl.NumberFormat(language === "sq" ? "sq-AL" : "en-IE", { style: "currency", currency: currency || "EUR" }).format(Number(value));
  }, [language]);
  const date = useCallback((value) => value ? new Intl.DateTimeFormat(language === "sq" ? "sq-AL" : "en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(`${String(value).slice(0, 10)}T12:00:00`)) : "—", [language]);

  const params = useMemo(() => ({ ...period, ...(tab === "expenses" ? { page: expensePage, per_page: 20, search: appliedSearch, status: expenseStatus, category: expenseCategory, payment_status: expensePaymentStatus } : {}) }), [period, tab, expensePage, appliedSearch, expenseStatus, expenseCategory, expensePaymentStatus]);

  const load = useCallback(async () => {
    const requestId = ++requestRef.current;
    setLoading(true);
    setError("");
    setData((current) => ({ ...current, [tab]: null }));
    try {
      const loader = {
        overview: getFinanceOverview,
        expenses: getExpenses,
        vat: getVatBooks,
        cashflow: getCashFlow,
        receivables: getReceivablesAging,
      }[tab];
      const result = await loader(params);
      if (requestId !== requestRef.current) return;
      setData((current) => ({ ...current, [tab]: result }));
    } catch (apiError) {
      if (requestId === requestRef.current) {
        setData((current) => ({ ...current, [tab]: null }));
        setError(errorMessage(apiError, t("finance.loadError")));
      }
    } finally {
      if (requestId === requestRef.current) setLoading(false);
    }
  }, [params, tab, t]);

  useEffect(() => { load(); }, [load, reloadKey]);
  useEffect(() => {
    const refresh = () => setReloadKey((value) => value + 1);
    window.addEventListener("database-refresh", refresh);
    return () => window.removeEventListener("database-refresh", refresh);
  }, []);

  const choosePreset = (value) => {
    setPreset(value);
    if (value !== "custom") setPeriod(periodFor(value));
    setExpensePage(1);
  };

  const removeExpense = async (expense) => {
    if (!window.confirm(t("finance.deleteExpenseConfirm", { name: expense.description || expense.document_number || expense.id }))) return;
    setActionBusy(`delete-${expense.id}`);
    setError("");
    try {
      await deleteExpense(expense.id);
      setMessage(t("finance.expenseDeleted"));
      if (expenseRows.length === 1 && expensePage > 1) setExpensePage((value) => value - 1);
      else setReloadKey((value) => value + 1);
    } catch (apiError) {
      setError(errorMessage(apiError, t("finance.expenseDeleteError")));
    } finally {
      setActionBusy("");
    }
  };

  const handlePostExpense = async (expense) => {
    if (!window.confirm(t("finance.postConfirm"))) return;
    setActionBusy(`post-${expense.id}`); setError("");
    try { await postExpense(expense.id); setMessage(t("finance.expensePosted")); setReloadKey((value) => value + 1); }
    catch (apiError) { setError(errorMessage(apiError, t("finance.actionError"))); }
    finally { setActionBusy(""); }
  };

  const confirmExpenseReversal = async (reason) => {
    if (!reversalExpense) return;
    await reverseExpense(reversalExpense.id, reason);
    setReversalExpense(null);
    setMessage(t("finance.expenseReversed"));
    setReloadKey((value) => value + 1);
  };

  const proof = async (expense) => {
    setActionBusy(`proof-${expense.id}`);
    setError("");
    try { await downloadExpenseProof(expense); }
    catch (apiError) { setError(errorMessage(apiError, t("finance.proofError"))); }
    finally { setActionBusy(""); }
  };

  const exportVat = async () => {
    setActionBusy("vat-export");
    setError("");
    try { await downloadVatBooks(period); }
    catch (apiError) { setError(errorMessage(apiError, t("finance.vatExportError"))); }
    finally { setActionBusy(""); }
  };

  const overview = data.overview?.summary || data.overview || {};
  const expensesPayload = data.expenses || {};
  const expenseRows = asRows(expensesPayload, ["expenses"]);
  const pagination = expensesPayload.meta || expensesPayload.pagination || expensesPayload;
  const cash = data.cashflow || {};
  const cashSummary = cash.summary || cash;
  const cashRows = asRows(cash, ["periods", "timeline", "rows"]);
  const vat = data.vat || {};
  const vatSummary = vat.summary || vat;
  const salesBook = asRows(vat, ["sales_book", "sales"]);
  const purchaseBook = asRows(vat, ["purchase_book", "purchases"]);
  const vatBuckets = asRows(vat, ["buckets", "vat_buckets"]);
  const aging = data.receivables || {};
  const agingSummary = aging.summary || aging;
  const agingBuckets = aging.aging_buckets || aging.buckets || {};
  const agingRows = asRows(aging, ["receivables", "invoices", "customers"]);

  const tabs = [
    ["overview", t("finance.tabOverview"), Landmark],
    ["expenses", t("finance.tabExpenses"), ReceiptText],
    ["vat", t("finance.tabVatBooks"), BookOpenCheck],
    ["cashflow", t("finance.tabCashFlow"), TrendingUp],
    ["receivables", t("finance.tabReceivables"), WalletCards],
  ];

  const renderOverview = () => {
    const reconciledSales = firstValue(overview, ["reconciled_sales_income", "reconciled_sales_total"]);
    const invoiceOnlySales = firstValue(overview, ["invoice_sales", "invoiced_sales"]);
    const metrics = [
      [BanknoteArrowUp, reconciledSales != null ? t("finance.salesIncome") : t("finance.invoicedSales"), reconciledSales ?? invoiceOnlySales, "green", reconciledSales != null ? t("finance.sourceReconciledSales") : t("finance.sourceInvoices")],
      [BanknoteArrowDown, t("finance.expenses"), firstValue(overview, ["total_expenses", "expenses_total", "cash_out"]), "red", t("finance.sourceExpenseBook")],
      [CircleDollarSign, t("finance.netCashFlow"), firstValue(overview, ["net_cash_flow", "net_flow"]), "blue", t("finance.sourceCompletedPayments")],
      [WalletCards, t("finance.openReceivables"), firstValue(overview, ["receivables_total", "outstanding_receivables", "total_outstanding"]), "amber", t("finance.sourceIssuedInvoices")],
      [BadgeEuro, t("finance.vatPosition"), firstValue(overview, ["vat_payable", "net_vat", "vat_position"]), "violet", t("finance.sourceVatBooks")],
    ];
    const rawWarnings = asRows(data.overview, ["data_quality_warnings", "warnings"]);
    const qualityWarnings = rawWarnings.length ? rawWarnings : [
      overview.missing_tax_profile ? { type: "tax_profile", message: t("finance.warningTaxProfile") } : null,
      Number(overview.unclassified_expenses_count || 0) > 0 ? { type: "classification", message: t("finance.warningUnclassified", { count: overview.unclassified_expenses_count }) } : null,
      Number(overview.expenses_missing_proof_count || 0) > 0 ? { type: "proof", message: t("finance.warningProof", { count: overview.expenses_missing_proof_count }) } : null,
      Number(overview.overdue_invoices_count || 0) > 0 ? { type: "overdue", message: t("finance.warningOverdue", { count: overview.overdue_invoices_count }) } : null,
    ].filter(Boolean);
    return (
      <>
        <div className="finance-metrics">{metrics.map(([Icon, label, value, tone, hint]) => <MetricCard key={label} icon={Icon} label={label} value={money(value)} tone={tone} hint={hint} />)}</div>
        {qualityWarnings.length ? (
          <section className="finance-quality-panel" aria-label={t("finance.dataQualityTitle")}>
            <strong><AlertTriangle size={17} />{t("finance.dataQualityTitle")}</strong>
            <div>{qualityWarnings.map((warning, index) => <span key={warning.code || warning.type || index}>{warning.message || warning.label || String(warning)}</span>)}</div>
          </section>
        ) : null}
        <div className="finance-overview-grid finance-overview-single">
          <section className="card finance-panel">
            <header><div><h2>{t("finance.periodBreakdown")}</h2><p>{t("finance.periodBreakdownHint")}</p></div></header>
            <dl className="finance-summary-list">
              <div><dt>{t("finance.invoicedSales")}</dt><dd>{money(firstValue(overview, ["invoice_sales", "invoiced_sales"]))}</dd></div>
              <div><dt>{t("finance.dailySales")}</dt><dd>{money(firstValue(overview, ["daily_sales", "daily_sales_total"]))}</dd></div>
              <div><dt>{t("finance.paymentsReceived")}</dt><dd>{money(firstValue(overview, ["payments_received", "received_payments"]))}</dd></div>
              <div><dt>{t("finance.overdueReceivables")}</dt><dd>{money(firstValue(overview, ["overdue_receivables", "overdue_total"]))}</dd></div>
            </dl>
          </section>
        </div>
      </>
    );
  };

  const renderExpenses = () => (
    <section className="card finance-panel">
      <header className="finance-panel-actions">
        <div><h2>{t("finance.expenseBook")}</h2><p>{t("finance.expenseBookHint")}</p></div>
        {canManageExpenses ? <button type="button" onClick={() => setExpenseModal({ open: true, expense: null })}><Plus size={16} />{t("finance.newExpense")}</button> : null}
      </header>
      <form className="finance-search-row" onSubmit={(event) => { event.preventDefault(); setExpensePage(1); setAppliedSearch(expenseSearch.trim()); }}>
        <label><Search size={17} /><input type="search" value={expenseSearch} onChange={(event) => setExpenseSearch(event.target.value)} placeholder={t("finance.searchExpenses")} /></label>
        <select aria-label={t("finance.status")} value={expenseStatus} onChange={(event) => { setExpenseStatus(event.target.value); setExpensePage(1); }}><option value="">{t("finance.allStatuses")}</option><option value="draft">{t("finance.status.draft")}</option><option value="posted">{t("finance.status.posted")}</option><option value="reversed">{t("finance.status.reversed")}</option></select>
        <select aria-label={t("finance.category")} value={expenseCategory} onChange={(event) => { setExpenseCategory(event.target.value); setExpensePage(1); }}><option value="">{t("finance.allCategories")}</option>{["inventory", "rent", "utilities", "transport", "salaries", "professional_services", "marketing", "advertising", "representation", "bank_fees", "insurance", "vehicle_fuel", "fines_penalties", "donations", "capital_asset", "stock_loss", "maintenance", "taxes_fees", "travel", "office", "other"].map((value) => <option key={value} value={value}>{t(`finance.category.${value}`)}</option>)}</select>
        <select aria-label={t("finance.paymentStatus")} value={expensePaymentStatus} onChange={(event) => { setExpensePaymentStatus(event.target.value); setExpensePage(1); }}><option value="">{t("finance.allPayments")}</option><option value="unpaid">{t("finance.payment.unpaid")}</option><option value="partially_paid">{t("finance.payment.partially_paid")}</option><option value="paid">{t("finance.payment.paid")}</option></select>
        <button type="submit" className="secondary">{t("finance.search")}</button>
      </form>
      <DataTable
        rows={expenseRows}
        emptyTitle={t("finance.noExpenses")}
        emptyText={t("finance.noExpensesHint")}
        columns={[
          { key: "received_date", label: t("finance.receivedDate"), render: (row) => <><span>{date(row.received_date)}</span><small>{t("finance.invoiceDateShort")}: {date(row.invoice_date)}</small></> },
          { key: "vendor_name", label: t("finance.supplier"), render: (row) => <><strong>{row.vendor_name || "—"}</strong><small>{row.document_number || ""}</small></> },
          { key: "description", label: t("finance.description") },
          { key: "classification", label: t("finance.classification"), render: (row) => <span className="finance-classification">{t(`finance.${row.source_type || "domestic"}`)} · {t(`finance.${row.asset_treatment || "ordinary"}`)}<small>{t(`finance.category.${row.category || "other"}`)}</small></span> },
          { key: "vat", label: t("finance.totalInputVat"), render: (row) => <><span>{t("finance.supplierVat")}: {money(row.vat_amount_eur ?? row.vat_amount)}</span>{Number(row.self_assessed_vat_amount_eur ?? row.self_assessed_vat_amount ?? 0) > 0 ? <small>{t("finance.selfAssessedVat")}: {money(row.self_assessed_vat_amount_eur ?? row.self_assessed_vat_amount)}</small> : null}<small>{t("finance.deductibleShort")}: {money(row.deductible_vat_amount_eur ?? row.deductible_vat_amount)}</small></> },
          { key: "amount", label: t("finance.amount"), render: (row) => <><strong>{money(row.gross_amount_eur ?? row.gross_amount)}</strong>{row.currency && row.currency !== "EUR" ? <small>{Number(row.gross_amount || 0).toFixed(2)} {row.currency} · {t("finance.rate")} {row.exchange_rate}</small> : null}</> },
          { key: "status", label: t("finance.status"), render: (row) => <><span className={`finance-status is-${row.status}`}>{t(`finance.status.${row.status}`)}</span><small>{t(`finance.payment.${row.payment_status || "unpaid"}`)}</small>{row.status === "posted" ? <small>{t("finance.lockedPeriod", { period: row.vat_period || "—" })}</small> : null}</> },
          { key: "actions", label: t("finance.actions"), render: (row) => {
            const canEdit = row.can_edit ?? row.status === "draft";
            const canDelete = row.can_delete ?? row.status === "draft";
            const canPost = row.can_post ?? row.status === "draft";
            const canReverse = row.can_reverse ?? row.status === "posted";
            const canPay = row.can_pay ?? (row.status === "posted" && row.document_type !== "credit_note" && Number(row.remaining_amount || 0) > 0);
            return <div className="finance-row-actions">
              {row.has_attachment ? <button type="button" className="secondary" disabled={Boolean(actionBusy)} onClick={() => proof(row)}>{actionBusy === `proof-${row.id}` ? <LoaderCircle className="is-spinning" size={14} /> : <Download size={14} />}{t("finance.proof")}</button> : null}
              {canManageExpenses && canEdit ? <button type="button" className="secondary" disabled={Boolean(actionBusy)} onClick={() => setExpenseModal({ open: true, expense: row })}><Pencil size={14} />{t("finance.edit")}</button> : null}
              {canManageExpenses && canPost ? <button type="button" disabled={Boolean(actionBusy)} onClick={() => handlePostExpense(row)}>{actionBusy === `post-${row.id}` ? <LoaderCircle className="is-spinning" size={14} /> : <BookOpenCheck size={14} />}{t("finance.post")}</button> : null}
              {canManageExpenses && canPay ? <button type="button" className="secondary" disabled={Boolean(actionBusy)} onClick={() => setPaymentExpense(row)}><BanknoteArrowDown size={14} />{t("finance.pay")}</button> : null}
              {Array.isArray(row.payments) && row.payments.length ? <button type="button" className="secondary" disabled={Boolean(actionBusy)} onClick={() => setPaymentHistoryExpense(row)}><WalletCards size={14} />{t("finance.payments")}</button> : null}
              {canManageExpenses && canReverse ? <button type="button" className="secondary" disabled={Boolean(actionBusy)} onClick={() => setReversalExpense(row)}><RefreshCw size={14} />{t("finance.reverse")}</button> : null}
              {canManageExpenses && canDelete ? <button type="button" className="danger secondary-danger" disabled={Boolean(actionBusy)} onClick={() => removeExpense(row)}>{actionBusy === `delete-${row.id}` ? <LoaderCircle className="is-spinning" size={14} /> : <Trash2 size={14} />}{t("finance.delete")}</button> : null}
            </div>;
          } },
        ]}
      />
      {Number(pagination.last_page || 1) > 1 ? <div className="finance-pagination"><button type="button" className="secondary" disabled={expensePage <= 1 || loading} onClick={() => setExpensePage((value) => value - 1)}><ChevronLeft size={16} />{t("common.previous")}</button><span>{t("common.page")} {pagination.current_page || expensePage} / {pagination.last_page}</span><button type="button" className="secondary" disabled={expensePage >= pagination.last_page || loading} onClick={() => setExpensePage((value) => value + 1)}>{t("common.next")}<ChevronRight size={16} /></button></div> : null}
    </section>
  );

  const renderVat = () => (
    <>
      <div className="finance-metrics three"><MetricCard icon={BanknoteArrowUp} label={t("finance.outputVat")} value={money(firstValue(vatSummary, ["output_vat", "sales_vat"]))} tone="green" /><MetricCard icon={BanknoteArrowDown} label={t("finance.deductibleInputVat")} value={money(firstValue(vatSummary, ["input_vat", "deductible_input_vat"]))} tone="blue" /><MetricCard icon={BadgeEuro} label={t("finance.netVatPosition")} value={money(firstValue(vatSummary, ["net_vat", "vat_payable", "net_position"]))} tone="violet" /></div>
      <section className="finance-preparation-note" role="note"><AlertTriangle size={18} /><div><strong>{t("finance.ediPreparationTitle")}</strong><p>{t("finance.ediPreparationBody")}</p></div>{canExportFinance ? <button type="button" className="secondary" onClick={exportVat} disabled={Boolean(actionBusy)}>{actionBusy === "vat-export" ? <LoaderCircle className="is-spinning" size={16} /> : <FileSpreadsheet size={16} />}{t("finance.exportVatBooks")}</button> : null}</section>
      {vatBuckets.length ? <section className="card finance-panel"><header><div><h2>{t("finance.officialBuckets")}</h2><p>{t("finance.officialBucketsHint")}</p></div></header><div className="finance-bucket-grid">{vatBuckets.map((bucket, index) => <article key={bucket.code || bucket.label || index}><span>{bucket.code}</span><strong>{bucket.label || bucket.name}</strong><b>{money(firstValue(bucket, ["amount", "taxable_amount", "value"]))}</b></article>)}</div></section> : null}
      <div className="finance-two-column">
        <section className="card finance-panel"><header><div><h2>{t("finance.salesBook")}</h2><p>{t("finance.salesBookHint")}</p></div></header><DataTable rows={salesBook} emptyTitle={t("finance.noSalesBookRows")} emptyText={t("finance.noPeriodData")} columns={[{ key: "date", label: t("finance.date"), render: (row) => date(row.date || row.issued_at) }, { key: "document", label: t("finance.document"), render: (row) => row.invoice_number || row.document_number }, { key: "party", label: t("finance.customer"), render: (row) => row.customer_name || row.buyer_name || "—" }, { key: "taxable", label: t("finance.taxable"), render: (row) => money(firstValue(row, ["taxable_amount", "taxable_total"])) }, { key: "vat", label: t("finance.vat"), render: (row) => money(firstValue(row, ["vat_amount", "vat_total"])) }]} /></section>
        <section className="card finance-panel"><header><div><h2>{t("finance.purchaseBook")}</h2><p>{t("finance.purchaseBookHintShort")}</p></div></header><DataTable rows={purchaseBook} emptyTitle={t("finance.noPurchaseBookRows")} emptyText={t("finance.noPeriodData")} columns={[{ key: "date", label: t("finance.date"), render: (row) => date(row.date || row.invoice_date) }, { key: "document", label: t("finance.document"), render: (row) => row.document_number }, { key: "party", label: t("finance.supplier"), render: (row) => row.supplier_name || row.vendor_name || "—" }, { key: "taxable", label: t("finance.taxable"), render: (row) => money(firstValue(row, ["taxable_amount", "net_amount_eur", "net_amount"])) }, { key: "vat", label: t("finance.supplierVat"), render: (row) => money(firstValue(row, ["vat_amount_eur", "vat_amount"])) }, { key: "self_assessed_vat", label: t("finance.selfAssessedVat"), render: (row) => money(firstValue(row, ["self_assessed_vat_amount_eur", "self_assessed_vat_amount"])) }, { key: "deductible_vat", label: t("finance.deductibleInputVat"), render: (row) => money(firstValue(row, ["deductible_vat_amount_eur", "deductible_vat_amount"])) }]} /></section>
      </div>
    </>
  );

  const renderCashFlow = () => (
    <>
      <div className="finance-metrics three"><MetricCard icon={BanknoteArrowUp} label={t("finance.cashIn")} value={money(firstValue(cashSummary, ["cash_in", "inflows", "total_in"]))} tone="green" /><MetricCard icon={BanknoteArrowDown} label={t("finance.cashOut")} value={money(firstValue(cashSummary, ["cash_out", "outflows", "total_out"]))} tone="red" /><MetricCard icon={TrendingUp} label={t("finance.netCashFlow")} value={money(firstValue(cashSummary, ["net_cash_flow", "net_flow"]))} tone="blue" /></div>
      <section className="card finance-panel"><header><div><h2>{t("finance.cashFlowTimeline")}</h2><p>{t("finance.cashFlowHint")}</p></div></header><DataTable rows={cashRows} emptyTitle={t("finance.noCashFlow") } emptyText={t("finance.noPeriodData")} columns={[{ key: "period", label: t("finance.period"), render: (row) => row.label || date(row.date || row.period) }, { key: "cash_in", label: t("finance.cashIn"), render: (row) => <span className="finance-positive">{money(firstValue(row, ["cash_in", "inflow"]))}</span> }, { key: "cash_out", label: t("finance.cashOut"), render: (row) => <span className="finance-negative">{money(firstValue(row, ["cash_out", "outflow"]))}</span> }, { key: "net", label: t("finance.netCashFlow"), render: (row) => <strong>{money(firstValue(row, ["net", "net_cash_flow"]))}</strong> }]} /></section>
    </>
  );

  const renderReceivables = () => {
    const bucketKeys = [["current", t("finance.agingCurrent")], ["days_1_30", t("finance.aging1to30")], ["days_31_60", t("finance.aging31to60")], ["days_61_90", t("finance.aging61to90")], ["days_90_plus", t("finance.agingOver90")]];
    return (
      <>
        <div className="finance-metrics three"><MetricCard icon={WalletCards} label={t("finance.totalOutstanding")} value={money(firstValue(agingSummary, ["total_outstanding", "receivables_total"]))} tone="amber" /><MetricCard icon={CalendarClock} label={t("finance.overdueReceivables")} value={money(firstValue(agingSummary, ["overdue_total", "total_overdue"]))} tone="red" /><MetricCard icon={ReceiptText} label={t("finance.openDocuments")} value={firstValue(agingSummary, ["open_count", "invoice_count"], "—")} tone="blue" /></div>
        <section className="finance-aging-grid">{bucketKeys.map(([key, label]) => <article key={key}><span>{label}</span><strong>{money(firstValue(agingBuckets, [key, key.replace("days_", "")]))}</strong></article>)}</section>
        <section className="card finance-panel"><header><div><h2>{t("finance.receivablesDetail")}</h2><p>{t("finance.receivablesHint")}</p></div></header><DataTable rows={agingRows} emptyTitle={t("finance.noReceivables")} emptyText={t("finance.noReceivablesHint")} columns={[{ key: "customer", label: t("finance.customer"), render: (row) => <><strong>{row.customer_name || row.buyer_name || row.name || "—"}</strong><small>{row.invoice_number || ""}</small></> }, { key: "issued_at", label: t("finance.issued"), render: (row) => date(row.issued_at || row.invoice_date) }, { key: "due_at", label: t("finance.dueDate"), render: (row) => date(row.due_at || row.due_date) }, { key: "days_overdue", label: t("finance.daysOverdue"), render: (row) => Number(row.days_overdue || 0) }, { key: "outstanding", label: t("finance.outstanding"), render: (row) => <strong>{money(firstValue(row, ["outstanding", "remaining_balance", "balance"]))}</strong> }]} /></section>
      </>
    );
  };

  return (
    <main className="finance-page page-stack">
      <section className="finance-hero">
        <div className="finance-heading"><span className="finance-hero-icon"><Landmark size={24} /></span><div><span className="finance-eyebrow">{t("finance.workspace")}</span><h1>{t("finance.title")}</h1><p>{t("finance.subtitle")}</p></div></div>
        <button type="button" className="secondary" onClick={() => setReloadKey((value) => value + 1)} disabled={loading}><RefreshCw className={loading ? "is-spinning" : ""} size={17} />{t("finance.refresh")}</button>
      </section>
      <section className="finance-quick-actions" aria-label={t("finance.quickActions")}>
        <div><strong>{t("finance.quickActions")}</strong><span>{t("finance.quickActionsHint")}</span></div>
        {canManageInvoices ? <button type="button" onClick={() => navigate("/invoices?new=1")}><ReceiptText size={17} /><span>{t("finance.createInvoice")}</span><small>{t("finance.createInvoiceHint")}</small></button> : null}
        {canManageExpenses ? <button type="button" onClick={() => { setTab("expenses"); setExpenseModal({ open: true, expense: null }); }}><Plus size={17} /><span>{t("finance.newExpense")}</span><small>{t("finance.newExpenseQuickHint")}</small></button> : null}
        <button type="button" onClick={() => setTab("vat")}><BookOpenCheck size={17} /><span>{t("finance.reviewVat")}</span><small>{t("finance.reviewVatHint")}</small></button>
      </section>
      <nav className="finance-tabs" role="tablist" aria-label={t("finance.title")}>{tabs.map(([id, label, Icon]) => <button type="button" role="tab" aria-selected={tab === id} key={id} className={tab === id ? "active" : ""} onClick={() => setTab(id)}><Icon size={17} />{label}</button>)}</nav>
      <section className="finance-period-bar">
        <div className="finance-presets" aria-label={t("finance.periodPreset")}>{["this_month", "previous_month", "quarter", "year", "custom"].map((value) => <button type="button" key={value} className={preset === value ? "active" : ""} onClick={() => choosePreset(value)}>{t(`finance.preset.${value}`)}</button>)}</div>
        <div className="finance-date-range"><label><span>{t("finance.from")}</span><input type="date" value={period.date_from} onChange={(event) => { const value = event.target.value; setPreset("custom"); setExpensePage(1); setPeriod((current) => ({ date_from: value, date_to: current.date_to && current.date_to < value ? value : current.date_to })); }} /></label><span>—</span><label><span>{t("finance.to")}</span><input type="date" min={period.date_from} value={period.date_to} onChange={(event) => { setPreset("custom"); setExpensePage(1); setPeriod((current) => ({ ...current, date_to: event.target.value })); }} /></label></div>
      </section>
      <section className="finance-global-disclaimer" role="note"><BookOpenCheck size={18} /><p><strong>{t("finance.preparationOnly")}</strong> {t("finance.preparationDisclaimer")}</p></section>
      {error ? <div className="finance-alert is-error" role="alert"><AlertTriangle size={17} />{error}<button type="button" onClick={() => setError("")} aria-label={t("common.cancel")}><X size={15} /></button></div> : null}
      {message ? <div className="finance-alert is-success" role="status"><BookOpenCheck size={17} />{message}<button type="button" onClick={() => setMessage("")} aria-label={t("common.cancel")}><X size={15} /></button></div> : null}
      <div className="finance-content" aria-live="polite">{loading ? <LoadingState label={t("common.loading")} /> : tab === "overview" ? renderOverview() : tab === "expenses" ? renderExpenses() : tab === "vat" ? renderVat() : tab === "cashflow" ? renderCashFlow() : renderReceivables()}</div>
      <ExpenseModal open={expenseModal.open} expense={expenseModal.expense} onClose={() => setExpenseModal({ open: false, expense: null })} onSaved={(_saved, proofWarning) => { setExpenseModal({ open: false, expense: null }); setMessage(proofWarning ? `${t("finance.expenseSaved")} ${proofWarning}` : t("finance.expenseSaved")); setReloadKey((value) => value + 1); }} t={t} />
      <ExpensePaymentModal expense={paymentExpense} open={Boolean(paymentExpense)} onClose={() => setPaymentExpense(null)} onSaved={() => { setPaymentExpense(null); setMessage(t("finance.paymentSaved")); setReloadKey((value) => value + 1); }} formatMoney={formatMoney} t={t} />
      <ExpensePaymentsModal expense={paymentHistoryExpense} open={Boolean(paymentHistoryExpense)} onClose={() => setPaymentHistoryExpense(null)} onChanged={() => { setPaymentHistoryExpense(null); setMessage(t("finance.paymentReversed")); setReloadKey((value) => value + 1); }} formatMoney={formatMoney} date={date} t={t} />
      <ExpenseReasonModal action="reverse" expense={reversalExpense} open={Boolean(reversalExpense)} onClose={() => setReversalExpense(null)} onConfirm={confirmExpenseReversal} t={t} />
    </main>
  );
}
