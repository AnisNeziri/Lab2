import { useCallback, useEffect, useMemo, useState } from "react";
import { BarChart3, Plus, RefreshCw, ShieldAlert, WalletCards } from "lucide-react";
import {
  createCustomer,
  createCustomerDebtEntry,
  deleteCustomerDebtSheet,
  downloadDebtStatement,
  getCustomerDebt,
  getCustomerDebts,
  getCustomerCreditReport,
  getCustomerCreditOverrideStatus,
  recordCustomerDebtPayment,
  requestCustomerCreditOverride,
  updateDebtTransaction,
  updateCustomer,
} from "../api/customerDebts";
import { getFinancialAccounts } from "../api/advancedOperations";
import { useTranslation } from "../hooks/useTranslation";
import { useAuthStore } from "../store/authStore";
import "./CustomerDebts.css";

const euro = (value) =>
  new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(
    Number(value || 0),
  );
const today = () => new Date().toISOString().slice(0, 10);
const dateOnly = (value) => (value ? String(value).slice(0, 10) : "—");
const hasValue = (value) => value !== null && value !== undefined && value !== "";
const percent = (value) => hasValue(value) ? `${Number(value).toFixed(1)}%` : "—";
const firstError = (errors, field) => {
  const value = errors?.[field];
  return Array.isArray(value) ? value[0] : value;
};
const emptyForm = () => ({
  name: "",
  business_name: "",
  phone: "",
  email: "",
  address: "",
  tax_number: "",
  notes: "",
  credit_limit: "",
  payment_terms_days: "",
  credit_status: "normal",
  credit_hold_reason: "",
  amount: "",
  transaction_date: today(),
  due_date: "",
  payment_method: "cash",
  financial_account_id: "",
  reference_number: "",
  note: "",
  opening_balance: false,
  idempotency_key: crypto.randomUUID(),
});

const creditSummary = (customer) => customer?.credit_summary || {
  current_debt: customer?.current_debt || 0,
  advance: customer?.current_credit || 0,
  total_exposure: Number(customer?.current_debt || 0) - Number(customer?.current_credit || 0),
  credit_limit: customer?.credit_limit,
  available_credit: hasValue(customer?.credit_limit)
    ? Number(customer.credit_limit) - Number(customer?.current_debt || 0) + Number(customer?.current_credit || 0)
    : null,
  utilization_percent: null,
  overdue: 0,
  oldest_overdue_date: null,
  aging: {},
};

const creditState = (customer) => {
  const summary = creditSummary(customer);
  const status = String(customer?.credit_status || "normal").toLowerCase();
  if (status === "blocked") return "blocked";
  if (hasValue(summary.credit_limit) && Number(summary.total_exposure) > Number(summary.credit_limit)) {
    return "over_limit";
  }
  if (status === "warning") return "warning";
  return "normal";
};

const reportState = (row) => {
  const state = String(row?.status || row?.credit_status || "normal")
    .trim()
    .toLowerCase()
    .replaceAll(" ", "_");
  return ["normal", "warning", "over_limit", "blocked"].includes(state)
    ? state
    : "normal";
};

export default function CustomerDebts() {
  const { t } = useTranslation();
  const permissions = useAuthStore((state) => state.permissions);
  const canManageCustomers = permissions.includes("customers.manage");
  const canCreateDebt = permissions.includes("debts.create");
  const canRecordPayment = permissions.includes("debts.payments");
  const canAdjustDebt = permissions.includes("debts.adjust");
  const canExportDebt = permissions.includes("debts.export");
  const [rows, setRows] = useState([]);
  const [selected, setSelected] = useState(null);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [sort, setSort] = useState("debt_desc");
  const [minDebt, setMinDebt] = useState("");
  const [maxDebt, setMaxDebt] = useState("");
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
  });
  const [mode, setMode] = useState("");
  const [form, setForm] = useState(emptyForm());
  const [busy, setBusy] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [correction, setCorrection] = useState(null);
  const [financialAccounts, setFinancialAccounts] = useState([]);
  const [creditBlock, setCreditBlock] = useState(null);
  const [overrideReason, setOverrideReason] = useState("");
  const [overrideBusy, setOverrideBusy] = useState(false);
  const [showCreditReport, setShowCreditReport] = useState(false);
  const [reportRows, setReportRows] = useState([]);
  const [reportSummary, setReportSummary] = useState({});
  const [reportFilter, setReportFilter] = useState("all");
  const [reportSort, setReportSort] = useState("exposure_desc");
  const [reportPage, setReportPage] = useState(1);
  const [reportPagination, setReportPagination] = useState({ current_page: 1, last_page: 1 });
  const [reportLoading, setReportLoading] = useState(false);

  const load = useCallback(
    async (requestedPage = page, refreshDetail = true, showLoading = true) => {
      if (showLoading) setLoading(true);
      try {
        const list = await getCustomerDebts({
          search,
          status,
          sort,
          min_debt: minDebt || undefined,
          max_debt: maxDebt || undefined,
          per_page: 20,
          page: requestedPage,
        });
        setRows(list.data || []);
        setPagination({
          current_page: list.current_page || 1,
          last_page: list.last_page || 1,
          total: list.total || 0,
        });
        if (refreshDetail && selected?.id) {
          setSelected(await getCustomerDebt(selected.id));
        }
        setError("");
      } catch (e) {
        setError(e.message || t("debts.loadError"));
      } finally {
        if (showLoading) setLoading(false);
      }
    },
    [page, search, status, sort, minDebt, maxDebt, selected?.id, t],
  );

  useEffect(() => {
    setPage(1);
    load(1);
  }, [search, status, sort, minDebt, maxDebt]);

  useEffect(() => {
    const refresh = () => load(page, true, false);
    window.addEventListener("database-refresh", refresh);
    return () => window.removeEventListener("database-refresh", refresh);
  }, [load, page]);

  useEffect(() => {
    let active = true;
    getFinancialAccounts()
      .then((response) => {
        if (!active) return;
        const accounts = response?.data || response;
        setFinancialAccounts(Array.isArray(accounts) ? accounts.filter((account) => account.is_active !== false) : []);
      })
      .catch(() => { if (active) setFinancialAccounts([]); });
    return () => { active = false; };
  }, []);

  const loadCreditReport = useCallback(async (requestedPage = 1) => {
    setReportLoading(true);
    try {
      const response = await getCustomerCreditReport({
        filter: reportFilter,
        sort: reportSort,
        per_page: 20,
        page: requestedPage,
      });
      setReportRows(response?.data || []);
      setReportSummary(response?.summary || {});
      setReportPagination({
        current_page: response?.current_page || 1,
        last_page: response?.last_page || 1,
        total: response?.total || 0,
      });
      setError("");
    } catch (requestError) {
      setError(requestError.message || t("debts.reportLoadError"));
    } finally {
      setReportLoading(false);
    }
  }, [reportFilter, reportSort, t]);

  useEffect(() => {
    if (!showCreditReport) return;
    setReportPage(1);
    loadCreditReport(1);
  }, [showCreditReport, reportFilter, reportSort, loadCreditReport]);

  const remainingPreview = useMemo(
    () =>
      Math.max(
        0,
        Number(selected?.current_debt || 0) - Number(form.amount || 0),
      ),
    [selected?.current_debt, form.amount],
  );

  const selectedCredit = useMemo(() => creditSummary(selected), [selected]);
  const selectedCreditState = creditState(selected);
  const approval = creditBlock?.approval_request || creditBlock?.approval || null;
  const approvalStatus = String(
    approval?.status || creditBlock?.approval_status || "",
  ).toLowerCase();

  const debtEntryPayload = (values = form) => ({
    amount: Number(values.amount),
    transaction_date: values.transaction_date,
    due_date: values.due_date || null,
    reference_number: values.reference_number || null,
    note: values.note || null,
    opening_balance: Boolean(values.opening_balance),
    idempotency_key: values.idempotency_key,
  });

  const clearCreditBlock = () => {
    setCreditBlock(null);
    setOverrideReason("");
  };

  const setFormField = (field, value) => {
    setForm((current) => ({ ...current, [field]: value }));
    if (mode === "debt" && creditBlock) clearCreditBlock();
  };

  const blockedDetails = (requestError, transaction) => {
    const details = requestError.creditControl || requestError.payload?.credit_control;
    const reason = details?.reason || firstError(requestError.errors, "credit");
    if (!details && !reason && requestError.code !== "CUSTOMER_CREDIT_BLOCKED") return null;
    return {
      ...details,
      reason: reason || requestError.message,
      transaction,
      customer: details?.customer || { id: selected?.id, name: selected?.name },
    };
  };

  const open = async (id) => {
    setLoading(true);
    try {
      setCorrection(null);
      setSelected(await getCustomerDebt(id));
      setMode("");
      clearCreditBlock();
      setError("");
    } catch (e) {
      setError(e.message);
    } finally {
      setLoading(false);
    }
  };

  const begin = (nextMode) => {
    setCorrection(null);
    if (nextMode === "edit" && selected) {
      setForm({ ...emptyForm(), ...selected });
    } else {
      setForm(emptyForm());
    }
    setMode(nextMode);
    clearCreditBlock();
    setMessage("");
    setError("");
  };

  const submit = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    setMessage("");
    let attemptedDebt = null;
    try {
      if (mode === "customer") {
        await createCustomer({
          ...form,
          credit_limit: form.credit_limit === "" ? null : Number(form.credit_limit),
          payment_terms_days: form.payment_terms_days === "" ? null : Number(form.payment_terms_days),
          credit_hold_reason: form.credit_hold_reason || null,
        });
      } else if (mode === "edit") {
        await updateCustomer(selected.id, {
          name: form.name,
          business_name: form.business_name || null,
          phone: form.phone || null,
          email: form.email || null,
          address: form.address || null,
          tax_number: form.tax_number || null,
          notes: form.notes || null,
          credit_limit: form.credit_limit === "" ? null : Number(form.credit_limit),
          payment_terms_days: form.payment_terms_days === "" ? null : Number(form.payment_terms_days),
          credit_status: form.credit_status || "normal",
          credit_hold_reason: form.credit_hold_reason || null,
        });
      } else {
        const payload = {
          amount: Number(form.amount),
          transaction_date: form.transaction_date,
          due_date: mode === "debt" ? form.due_date || null : undefined,
          payment_method: mode === "payment" ? form.payment_method : undefined,
          financial_account_id: mode === "payment" && form.financial_account_id
            ? Number(form.financial_account_id)
            : undefined,
          reference_number: form.reference_number || null,
          note: form.note || null,
          opening_balance: mode === "debt" ? form.opening_balance : undefined,
          idempotency_key: form.idempotency_key,
        };
        if (mode === "debt") {
          attemptedDebt = debtEntryPayload(form);
          await createCustomerDebtEntry(selected.id, attemptedDebt);
        } else {
          await recordCustomerDebtPayment(selected.id, payload);
        }
      }
      setMode("");
      setForm(emptyForm());
      clearCreditBlock();
      setMessage(t("debts.saved"));
      await load(page);
    } catch (e) {
      const blocked = mode === "debt" ? blockedDetails(e, attemptedDebt) : null;
      if (blocked) setCreditBlock(blocked);
      setError(blocked?.reason || (e.errors ? Object.values(e.errors).flat().join(" ") : e.message));
    } finally {
      setBusy(false);
    }
  };

  const requestOverride = async (event) => {
    event.preventDefault();
    const reason = overrideReason.trim();
    if (reason.length < 5) {
      setError(t("debts.overrideReasonLength"));
      return;
    }
    setOverrideBusy(true);
    setError("");
    try {
      const response = await requestCustomerCreditOverride(selected.id, {
        ...creditBlock.transaction,
        reason,
        source_entity: creditBlock.source_entity || "customer_debt_entry",
        source_reference: creditBlock.source_reference || creditBlock.transaction.idempotency_key,
      });
      const nextApproval = response?.approval_request || response?.approval || response;
      setCreditBlock((current) => ({
        ...current,
        approval_request: nextApproval,
        approval_status: nextApproval?.status || "pending",
      }));
      setMessage(t("debts.overrideRequested"));
    } catch (e) {
      setError(e.errors ? Object.values(e.errors).flat().join(" ") : e.message);
    } finally {
      setOverrideBusy(false);
    }
  };

  const retryProtectedTransaction = async () => {
    if (!approval?.id || overrideBusy) return;
    setOverrideBusy(true);
    setError("");
    setMessage("");
    try {
      await createCustomerDebtEntry(selected.id, {
        ...creditBlock.transaction,
        approval_request_id: approval.id,
      });
      setMode("");
      setForm(emptyForm());
      clearCreditBlock();
      setMessage(t("debts.overrideCompleted"));
      await load(page);
    } catch (e) {
      const blocked = blockedDetails(e, creditBlock.transaction);
      if (blocked) {
        setCreditBlock((current) => ({ ...current, ...blocked }));
      }
      setError(blocked?.reason || (e.errors ? Object.values(e.errors).flat().join(" ") : e.message));
    } finally {
      setOverrideBusy(false);
    }
  };

  const refreshOverrideStatus = async () => {
    if (!approval?.id || overrideBusy) return;
    setOverrideBusy(true);
    setError("");
    try {
      const nextApproval = await getCustomerCreditOverrideStatus(selected.id, approval.id);
      setCreditBlock((current) => ({
        ...current,
        approval_request: nextApproval,
        approval_status: nextApproval?.status || current.approval_status,
      }));
      if (nextApproval?.status === "approved") setMessage(t("debts.overrideApproved"));
      if (nextApproval?.status === "rejected") setMessage(t("debts.overrideRejected"));
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setOverrideBusy(false);
    }
  };

  const correct = (transaction) => {
    setMode("");
    setCorrection({
      transaction,
      amount: String(transaction.amount ?? ""),
      transaction_date: dateOnly(transaction.transaction_date),
      due_date: transaction.due_date ? dateOnly(transaction.due_date) : "",
      payment_method: transaction.payment_method || "",
      reference_number: transaction.reference_number || "",
      note: transaction.note || "",
      reason: "",
    });
    setError("");
    setMessage("");
  };

  const submitCorrection = async (event) => {
    event.preventDefault();
    const reason = correction?.reason?.trim() || "";
    if (reason.length < 5) {
      setError(t("debts.correctionReasonLength"));
      return;
    }
    setError("");
    setBusy(true);
    try {
      await updateDebtTransaction(correction.transaction.id, {
        amount: Number(correction.amount),
        transaction_date: correction.transaction_date,
        due_date: correction.due_date || null,
        payment_method: correction.payment_method || null,
        reference_number: correction.reference_number || null,
        note: correction.note || null,
        reason,
      });
      setCorrection(null);
      setMessage(t("debts.corrected"));
      await load(page);
    } catch (e) {
      setError(e.errors ? Object.values(e.errors).flat().join(" ") : e.message);
    } finally {
      setBusy(false);
    }
  };

  const removeSheet = async (customer) => {
    if (!window.confirm(t("debts.deleteConfirm"))) return;
    setBusy(true);
    setError("");
    try {
      await deleteCustomerDebtSheet(customer.id);
      if (selected?.id === customer.id) {
        setSelected(null);
        setCorrection(null);
        setMode("");
      }
      setMessage(t("debts.deleted"));
      await load(page, false);
    } catch (e) {
      setError(e.errors ? Object.values(e.errors).flat().join(" ") : e.message);
    } finally {
      setBusy(false);
    }
  };

  const statusText = (row) => {
    if (Number(row.current_credit) > 0) return t("debts.hasCredit");
    if (Number(row.current_debt) <= 0) return t("debts.noDebt");
    if (row.has_overdue_debt) return t("debts.overdue");
    if (row.last_payment_at) return t("debts.partiallyPaid");
    return t("debts.activeDebt");
  };

  const typeText = (type) => t(`debts.type.${type}`);
  const paymentText = (method) => (method ? t(`debts.method.${method}`) : "—");

  return (
    <main className="page-stack">
      <section className="card">
        <div className="section-header">
          <h2>
            <WalletCards size={20} />
            {t("debts.title")}
          </h2>
          <div className="table-actions">
            <button
              className="secondary"
              onClick={() => {
                setShowCreditReport((current) => !current);
                setSelected(null);
                setMode("");
                clearCreditBlock();
              }}
            >
              <BarChart3 size={16} />
              {showCreditReport ? t("debts.customerList") : t("debts.creditReport")}
            </button>
            {canManageCustomers && !showCreditReport && (
              <button onClick={() => begin("customer")}>
                <Plus size={16} />
                {t("debts.newCustomer")}
              </button>
            )}
          </div>
        </div>
        <p className="page-intro">{t("debts.subtitle")}</p>
        {error && <div className="form-error-banner">{error}</div>}
        {message && <div className="success-banner">{message}</div>}
      </section>

      {mode && (
        <section className="card">
          <h3>
            {mode === "customer"
              ? t("debts.newCustomer")
              : mode === "edit"
                ? t("debts.editCustomer")
                : mode === "payment"
                  ? t("debts.recordPayment")
                  : t("debts.addDebt")}
          </h3>
          <form className="form-grid" onSubmit={submit}>
            {mode === "customer" || mode === "edit" ? (
              <>
                <label>
                  {t("debts.customer")}
                  <input
                    required
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                  />
                </label>
                <label>
                  {t("debts.business")}
                  <input
                    value={form.business_name || ""}
                    onChange={(e) =>
                      setForm({ ...form, business_name: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.phone")}
                  <input
                    value={form.phone || ""}
                    onChange={(e) =>
                      setForm({ ...form, phone: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.email")}
                  <input
                    type="email"
                    value={form.email || ""}
                    onChange={(e) =>
                      setForm({ ...form, email: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.address")}
                  <input
                    value={form.address || ""}
                    onChange={(e) =>
                      setForm({ ...form, address: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.taxNumber")}
                  <input
                    value={form.tax_number || ""}
                    onChange={(e) =>
                      setForm({ ...form, tax_number: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.notes")}
                  <textarea
                    value={form.notes || ""}
                    onChange={(e) =>
                      setForm({ ...form, notes: e.target.value })
                    }
                  />
                </label>
                {canManageCustomers && (
                  <>
                    <label>
                      {t("debts.creditLimit")}
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={form.credit_limit ?? ""}
                        placeholder={t("debts.noCreditLimit")}
                        onChange={(event) => setForm({ ...form, credit_limit: event.target.value })}
                      />
                    </label>
                    <label>
                      {t("debts.paymentTerms")}
                      <input
                        type="number"
                        min="0"
                        max="3650"
                        step="1"
                        value={form.payment_terms_days ?? ""}
                        onChange={(event) => setForm({ ...form, payment_terms_days: event.target.value })}
                      />
                    </label>
                    <label>
                      {t("debts.creditStatus")}
                      <select
                        value={form.credit_status || "normal"}
                        onChange={(event) => setForm({ ...form, credit_status: event.target.value })}
                      >
                        <option value="normal">{t("debts.state.normal")}</option>
                        <option value="warning">{t("debts.state.warning")}</option>
                        <option value="blocked">{t("debts.state.blocked")}</option>
                      </select>
                    </label>
                    <label>
                      {t("debts.creditHoldReason")}
                      <textarea
                        required={form.credit_status === "blocked"}
                        value={form.credit_hold_reason || ""}
                        onChange={(event) => setForm({ ...form, credit_hold_reason: event.target.value })}
                      />
                    </label>
                  </>
                )}
              </>
            ) : (
              <>
                <p>
                  <strong>{selected?.name}</strong> — {t("debts.currentDebt")}:{" "}
                  {euro(selected?.current_debt)}
                </p>
                <label>
                  {mode === "payment"
                    ? t("debts.paymentAmount")
                    : t("debts.amountOwed")}
                  <input
                    required
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={form.amount}
                    onChange={(e) => setFormField("amount", e.target.value)}
                  />
                </label>
                <label>
                  {t("debts.date")}
                  <input
                    required
                    type="date"
                    value={form.transaction_date}
                    onChange={(e) => setFormField("transaction_date", e.target.value)}
                  />
                </label>
                {mode === "debt" && (
                  <>
                    <label>
                      {t("debts.dueDate")}
                      <input
                        type="date"
                        min={form.transaction_date}
                        value={form.due_date}
                        onChange={(e) => setFormField("due_date", e.target.value)}
                      />
                    </label>
                    <label className="debt-opening-balance-field">
                      <input
                        type="checkbox"
                        checked={form.opening_balance}
                        onChange={(e) => setFormField("opening_balance", e.target.checked)}
                      />
                      <span>{t("debts.openingBalance")}</span>
                    </label>
                  </>
                )}
                {mode === "payment" && (
                  <>
                    <label>
                      {t("debts.paymentMethod")}
                      <select
                        value={form.payment_method}
                        onChange={(e) =>
                          setForm({ ...form, payment_method: e.target.value })
                        }
                      >
                        <option value="cash">{t("debts.method.cash")}</option>
                        <option value="bank_transfer">
                          {t("debts.method.bank_transfer")}
                        </option>
                        <option value="card">{t("debts.method.card")}</option>
                        <option value="cheque">
                          {t("debts.method.cheque")}
                        </option>
                        <option value="other">{t("debts.method.other")}</option>
                      </select>
                    </label>
                    <label>
                      {t("payments.moneyAccount")}
                      <select
                        value={form.financial_account_id}
                        onChange={(e) =>
                          setForm({ ...form, financial_account_id: e.target.value })
                        }
                      >
                        <option value="">{t("payments.noMoneyAccount")}</option>
                        {financialAccounts.map((account) => (
                          <option key={account.id} value={account.id}>
                            {account.name} · {account.type === "cashbox" ? t("finance.cash") : t("finance.bankTransfer")} ({account.currency || "EUR"})
                          </option>
                        ))}
                      </select>
                    </label>
                    <p>
                      {t("debts.remainingDebt")}:{" "}
                      <strong>{euro(remainingPreview)}</strong>
                    </p>
                  </>
                )}
                <label>
                  {t("debts.reference")}
                  <input
                    value={form.reference_number}
                    onChange={(e) => setFormField("reference_number", e.target.value)}
                  />
                </label>
                <label>
                  {t("debts.notes")}
                  <textarea
                    value={form.note}
                    onChange={(e) => setFormField("note", e.target.value)}
                  />
                </label>
              </>
            )}
            <div className="form-actions">
              <button disabled={busy}>
                {busy ? t("debts.saving") : t("debts.save")}
              </button>
              <button
                type="button"
                className="secondary"
                onClick={() => {
                  setMode("");
                  clearCreditBlock();
                }}
              >
                {t("debts.cancel")}
              </button>
            </div>
          </form>
          {mode === "debt" && creditBlock && (
            <section className="credit-block-panel" aria-live="polite">
              <header>
                <ShieldAlert size={22} />
                <div>
                  <h4>{t("debts.creditBlockedTitle")}</h4>
                  <p>{creditBlock.reason}</p>
                </div>
              </header>
              <div className="credit-block-metrics">
                <span>
                  <small>{t("debts.creditLimit")}</small>
                  <strong>{hasValue(creditBlock.credit_limit) ? euro(creditBlock.credit_limit) : t("debts.noCreditLimit")}</strong>
                </span>
                <span>
                  <small>{t("debts.exposure")}</small>
                  <strong>{euro(creditBlock.current_exposure ?? selectedCredit.total_exposure)}</strong>
                </span>
                <span>
                  <small>{t("debts.projectedExposure")}</small>
                  <strong>{euro(creditBlock.projected_exposure)}</strong>
                </span>
                <span>
                  <small>{t("debts.proposedAmount")}</small>
                  <strong>{euro(creditBlock.transaction?.amount)}</strong>
                </span>
              </div>
              {approval && (
                <div className="credit-approval-state">
                  <span>{t("debts.overrideStatus")}</span>
                  <strong className={`credit-approval-badge ${approvalStatus || "pending"}`}>
                    {t(`debts.approval.${approvalStatus || "pending"}`)}
                  </strong>
                  <small>#{approval.id}</small>
                </div>
              )}
              {(!approval || approvalStatus === "rejected") && (
                <form className="credit-override-form" onSubmit={requestOverride}>
                  <label>
                    {t("debts.overrideReason")}
                    <textarea
                      required
                      minLength="5"
                      value={overrideReason}
                      onChange={(event) => setOverrideReason(event.target.value)}
                    />
                  </label>
                  <button disabled={overrideBusy || overrideReason.trim().length < 5}>
                    {overrideBusy ? t("debts.requestingOverride") : t("debts.requestOverride")}
                  </button>
                </form>
              )}
              {approval && ["pending", "approved"].includes(approvalStatus) && (
                <button
                  type="button"
                  className={approvalStatus === "approved" ? "" : "secondary"}
                  disabled={overrideBusy}
                  onClick={approvalStatus === "approved" ? retryProtectedTransaction : refreshOverrideStatus}
                >
                  <RefreshCw size={15} />
                  {approvalStatus === "approved"
                    ? t("debts.completeWithOverride")
                    : t("debts.checkOverrideStatus")}
                </button>
              )}
            </section>
          )}
        </section>
      )}

      {showCreditReport ? (
        <section className="card credit-report-section">
          <div className="section-header">
            <div>
              <h2>{t("debts.creditReport")}</h2>
              <p className="page-intro">{t("debts.creditReportHint")}</p>
            </div>
            <button className="secondary" disabled={reportLoading} onClick={() => loadCreditReport(reportPage)}>
              <RefreshCw size={15} />
              {t("debts.refresh")}
            </button>
          </div>
          <section className="credit-report-summary">
            <article className="stat-card">
              <p className="stat-label">{t("debts.totalReceivables")}</p>
              <p className="stat-value">{euro(reportSummary.total_receivables)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.totalOverdue")}</p>
              <p className="stat-value">{euro(reportSummary.total_overdue)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.total90Plus")}</p>
              <p className="stat-value">{euro(reportSummary.total_90_plus)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.totalAdvances")}</p>
              <p className="stat-value">{euro(reportSummary.total_advances)}</p>
            </article>
          </section>
          <div className="filter-toolbar credit-report-toolbar">
            <label>
              {t("debts.reportFilter")}
              <select value={reportFilter} onChange={(event) => setReportFilter(event.target.value)}>
                <option value="all">{t("debts.all")}</option>
                <option value="blocked">{t("debts.filter.blocked")}</option>
                <option value="over_limit">{t("debts.filter.overLimit")}</option>
                <option value="overdue">{t("debts.filter.overdue")}</option>
                <option value="90_plus">{t("debts.filter.90Plus")}</option>
              </select>
            </label>
            <label>
              {t("debts.reportSort")}
              <select value={reportSort} onChange={(event) => setReportSort(event.target.value)}>
                <option value="exposure_desc">{t("debts.sort.exposureDesc")}</option>
                <option value="exposure_asc">{t("debts.sort.exposureAsc")}</option>
                <option value="utilization_desc">{t("debts.sort.utilizationDesc")}</option>
                <option value="utilization_asc">{t("debts.sort.utilizationAsc")}</option>
                <option value="overdue_desc">{t("debts.sort.overdueDesc")}</option>
                <option value="overdue_asc">{t("debts.sort.overdueAsc")}</option>
              </select>
            </label>
          </div>
          <div className="table-wrap credit-report-table">
            <table className="product-table">
              <thead>
                <tr>
                  <th>{t("debts.customer")}</th>
                  <th>{t("debts.currentDebt")}</th>
                  <th>{t("debts.advance")}</th>
                  <th>{t("debts.exposure")}</th>
                  <th>{t("debts.overdue")}</th>
                  <th>{t("debts.aging.90_plus")}</th>
                  <th>{t("debts.creditLimit")}</th>
                  <th>{t("debts.availableCredit")}</th>
                  <th>{t("debts.utilization")}</th>
                  <th>{t("debts.status")}</th>
                  <th>{t("debts.oldestOverdue")}</th>
                </tr>
              </thead>
              <tbody>
                {reportLoading ? (
                  <tr><td colSpan="11">{t("debts.reportLoading")}</td></tr>
                ) : reportRows.length ? reportRows.map((row) => {
                  const state = reportState(row);
                  return (
                    <tr key={row.customer_id}>
                      <td>
                        <button
                          type="button"
                          className="credit-report-customer"
                          onClick={() => {
                            setShowCreditReport(false);
                            open(row.customer_id);
                          }}
                        >
                          {row.customer}
                        </button>
                        <small>{row.business_name || ""}</small>
                      </td>
                      <td>{euro(row.debt)}</td>
                      <td>{euro(row.advance)}</td>
                      <td>{euro(row.exposure)}</td>
                      <td>{euro(row.overdue)}</td>
                      <td>{euro(row.overdue_90_plus)}</td>
                      <td>{hasValue(row.credit_limit) ? euro(row.credit_limit) : t("debts.unlimited")}</td>
                      <td>{hasValue(row.available_credit) ? euro(row.available_credit) : t("debts.unlimited")}</td>
                      <td>{percent(row.utilization_percent)}</td>
                      <td><span className={`credit-state-badge ${state}`}>{t(`debts.state.${state}`)}</span></td>
                      <td>{dateOnly(row.oldest_overdue_date)}</td>
                    </tr>
                  );
                }) : (
                  <tr><td colSpan="11">{t("debts.reportEmpty")}</td></tr>
                )}
              </tbody>
            </table>
          </div>
          <div className="pagination">
            <button
              className="secondary"
              disabled={reportLoading || reportPagination.current_page <= 1}
              onClick={() => {
                const next = reportPage - 1;
                setReportPage(next);
                loadCreditReport(next);
              }}
            >{t("debts.previous")}</button>
            <span>{t("debts.page")} {reportPagination.current_page} / {reportPagination.last_page}</span>
            <button
              className="secondary"
              disabled={reportLoading || reportPagination.current_page >= reportPagination.last_page}
              onClick={() => {
                const next = reportPage + 1;
                setReportPage(next);
                loadCreditReport(next);
              }}
            >{t("debts.next")}</button>
          </div>
        </section>
      ) : selected ? (
        <section className="card table-wrap">
          <div className="section-header">
            <div>
              <div className="customer-credit-title">
                <h2>{selected.name}</h2>
                <span className={`credit-state-badge ${selectedCreditState}`}>
                  {t(`debts.state.${selectedCreditState}`)}
                </span>
              </div>
              <p>
                {selected.business_name || "—"} · {selected.phone || "—"} ·{" "}
                {selected.email || "—"}
              </p>
              <p>
                {selected.address || ""}{" "}
                {selected.tax_number ? `· ${selected.tax_number}` : ""}
              </p>
              <small>{selected.notes || ""}</small>
            </div>
            <div className="table-actions">
              {canCreateDebt && (
                <button onClick={() => begin("debt")}>
                  {t("debts.addDebt")}
                </button>
              )}
              {canRecordPayment && (
                <button
                  onClick={() => begin("payment")}
                  disabled={Number(selected.current_debt) <= 0}
                >
                  {t("debts.recordPayment")}
                </button>
              )}
              {canManageCustomers && (
                <button className="secondary" onClick={() => begin("edit")}>
                  {t("debts.editCustomer")}
                </button>
              )}
              {canExportDebt && (
                <button
                  className="secondary"
                  onClick={() => downloadDebtStatement(selected.id)}
                >
                  {t("debts.export")}
                </button>
              )}
              <button className="secondary" onClick={() => window.print()}>
                {t("debts.print")}
              </button>
              {canManageCustomers && (
                <button
                  className="danger"
                  disabled={busy}
                  onClick={() => removeSheet(selected)}
                >
                  {t("debts.deleteSheet")}
                </button>
              )}
              <button
                className="secondary"
                onClick={() => {
                  setSelected(null);
                  setMode("");
                  setCorrection(null);
                  clearCreditBlock();
                }}
              >
                {t("debts.back")}
              </button>
            </div>
          </div>
          <section className="credit-policy-strip">
            <div>
              <small>{t("debts.creditLimit")}</small>
              <strong>{hasValue(selectedCredit.credit_limit) ? euro(selectedCredit.credit_limit) : t("debts.noCreditLimit")}</strong>
            </div>
            <div>
              <small>{t("debts.paymentTerms")}</small>
              <strong>{hasValue(selected.payment_terms_days) ? t("debts.days", { count: selected.payment_terms_days }) : "—"}</strong>
            </div>
            <div>
              <small>{t("debts.creditStatus")}</small>
              <strong>{t(`debts.state.${String(selected.credit_status || "normal").toLowerCase()}`)}</strong>
            </div>
            {selected.credit_hold_reason && (
              <div className="credit-hold-reason">
                <small>{t("debts.creditHoldReason")}</small>
                <strong>{selected.credit_hold_reason}</strong>
              </div>
            )}
          </section>
          {correction && (
            <section className="inline-editor">
              <div className="section-header">
                <div>
                  <h3>{t("debts.correctionTitle")}</h3>
                  <p className="page-intro">
                    {t("debts.correctionFor")}:{" "}
                    {typeText(correction.transaction.type)}
                  </p>
                </div>
                <button
                  type="button"
                  className="secondary"
                  disabled={busy}
                  onClick={() => setCorrection(null)}
                >
                  {t("debts.cancel")}
                </button>
              </div>
              <form
                className="form-grid form-grid-2"
                onSubmit={submitCorrection}
              >
                <label>
                  {[
                    "payment",
                    "negative_adjustment",
                    "return",
                    "cancellation",
                  ].includes(correction.transaction.type)
                    ? t("debts.paymentAmount")
                    : t("debts.amountOwed")}
                  <input
                    required
                    type="number"
                    min="0.01"
                    step="0.01"
                    value={correction.amount}
                    onChange={(event) =>
                      setCorrection({
                        ...correction,
                        amount: event.target.value,
                      })
                    }
                  />
                </label>
                <label>
                  {t("debts.date")}
                  <input
                    required
                    type="date"
                    value={correction.transaction_date}
                    onChange={(event) =>
                      setCorrection({
                        ...correction,
                        transaction_date: event.target.value,
                      })
                    }
                  />
                </label>
                <label>
                  {t("debts.dueDate")}
                  <input
                    type="date"
                    min={correction.transaction_date}
                    value={correction.due_date}
                    onChange={(event) =>
                      setCorrection({
                        ...correction,
                        due_date: event.target.value,
                      })
                    }
                  />
                </label>
                <label>
                  {t("debts.paymentMethod")}
                  <select
                    value={correction.payment_method}
                    onChange={(event) =>
                      setCorrection({
                        ...correction,
                        payment_method: event.target.value,
                      })
                    }
                  >
                    <option value="">—</option>
                    {["cash", "bank_transfer", "card", "cheque", "other"].map(
                      (method) => (
                        <option key={method} value={method}>
                          {paymentText(method)}
                        </option>
                      ),
                    )}
                  </select>
                </label>
                <label>
                  {t("debts.reference")}
                  <input
                    value={correction.reference_number}
                    onChange={(event) =>
                      setCorrection({
                        ...correction,
                        reference_number: event.target.value,
                      })
                    }
                  />
                </label>
                <label>
                  {t("debts.notes")}
                  <textarea
                    value={correction.note}
                    onChange={(event) =>
                      setCorrection({ ...correction, note: event.target.value })
                    }
                  />
                </label>
                <label className="form-span-2">
                  {t("debts.correctionReason")}
                  <textarea
                    required
                    minLength="5"
                    value={correction.reason}
                    onChange={(event) =>
                      setCorrection({
                        ...correction,
                        reason: event.target.value,
                      })
                    }
                  />
                </label>
                <div className="form-actions form-span-2">
                  <button disabled={busy}>
                    {busy ? t("debts.saving") : t("debts.saveCorrection")}
                  </button>
                  <button
                    type="button"
                    className="secondary"
                    disabled={busy}
                    onClick={() => setCorrection(null)}
                  >
                    {t("debts.cancel")}
                  </button>
                </div>
              </form>
            </section>
          )}
          <section className="credit-metrics-grid">
            <article className="stat-card">
              <p className="stat-label">{t("debts.currentDebt")}</p>
              <p className="stat-value">{euro(selectedCredit.current_debt)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.advance")}</p>
              <p className="stat-value">{euro(selectedCredit.advance)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.exposure")}</p>
              <p className="stat-value">{euro(selectedCredit.total_exposure)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.availableCredit")}</p>
              <p className="stat-value">{hasValue(selectedCredit.available_credit) ? euro(selectedCredit.available_credit) : t("debts.unlimited")}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.utilization")}</p>
              <p className="stat-value">{percent(selectedCredit.utilization_percent)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.overdue")}</p>
              <p className="stat-value">{euro(selectedCredit.overdue)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.oldestOverdue")}</p>
              <p className="stat-value">{dateOnly(selectedCredit.oldest_overdue_date)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.creditLimit")}</p>
              <p className="stat-value">{hasValue(selectedCredit.credit_limit) ? euro(selectedCredit.credit_limit) : t("debts.unlimited")}</p>
            </article>
          </section>
          <section className="credit-aging-section">
            <div className="section-header">
              <h3>{t("debts.agingTitle")}</h3>
              <small>{t("debts.agingHint")}</small>
            </div>
            <div className="credit-aging-grid">
              {["current", "1_30", "31_60", "61_90", "90_plus"].map((bucket) => (
                <article key={bucket} className={bucket === "90_plus" && Number(selectedCredit.aging?.[bucket]) > 0 ? "is-danger" : ""}>
                  <span>{t(`debts.aging.${bucket}`)}</span>
                  <strong>{euro(selectedCredit.aging?.[bucket])}</strong>
                </article>
              ))}
            </div>
          </section>
          <div className="credit-ledger-dates">
            <span>{t("debts.lastPayment")}: <strong>{dateOnly(selected.ledger_summary?.last_payment_at)}</strong></span>
            <span>{t("debts.lastDebt")}: <strong>{dateOnly(selected.ledger_summary?.last_debt_at)}</strong></span>
          </div>
          <table className="product-table">
            <thead>
              <tr>
                <th>{t("debts.date")}</th>
                <th>{t("debts.type")}</th>
                <th>{t("debts.reference")}</th>
                <th>{t("debts.sale")}</th>
                <th>{t("debts.amountOwed")}</th>
                <th>{t("debts.before")}</th>
                <th>{t("debts.after")}</th>
                <th>{t("debts.paymentMethod")}</th>
                <th>{t("debts.notes")}</th>
                <th>{t("debts.action")}</th>
              </tr>
            </thead>
            <tbody>
              {selected.debt_transactions?.length ? (
                selected.debt_transactions.map((tx) => {
                  const decrease = [
                    "payment",
                    "negative_adjustment",
                    "return",
                    "cancellation",
                  ].includes(tx.type);
                  return (
                    <tr key={tx.id}>
                      <td>
                        {dateOnly(tx.transaction_date)}
                        {tx.due_date && (
                          <>
                            <br />
                            <small>
                              {t("debts.dueDate")}: {dateOnly(tx.due_date)}
                            </small>
                          </>
                        )}
                      </td>
                      <td>{typeText(tx.type)}</td>
                      <td>{tx.reference_number || "—"}</td>
                      <td>{tx.daily_sale_id ? `#${tx.daily_sale_id}` : "—"}</td>
                      <td>
                        {decrease ? "−" : "+"}
                        {euro(tx.amount)}
                      </td>
                      <td>{euro(tx.balance_before)}</td>
                      <td>{euro(tx.balance_after)}</td>
                      <td>{paymentText(tx.payment_method)}</td>
                      <td>
                        {tx.note || "—"}
                        <br />
                        <small>{tx.user?.name || ""}</small>
                      </td>
                      <td>
                        {canAdjustDebt && (
                          <button
                            className="secondary"
                            disabled={busy}
                            onClick={() => correct(tx)}
                          >
                            {t("debts.correct")}
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })
              ) : (
                <tr>
                  <td colSpan="10">{t("debts.noTransactions")}</td>
                </tr>
              )}
            </tbody>
          </table>
        </section>
      ) : (
        <section className="card">
          <div className="filter-toolbar">
            <input
              placeholder={t("debts.search")}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="all">{t("debts.all")}</option>
              <option value="active">{t("debts.activeDebt")}</option>
              <option value="paid">{t("debts.noDebt")}</option>
              <option value="credit">{t("debts.hasCredit")}</option>
              <option value="overdue">{t("debts.overdue")}</option>
            </select>
            <input
              type="number"
              min="0"
              step="0.01"
              placeholder={t("debts.minDebt")}
              value={minDebt}
              onChange={(e) => setMinDebt(e.target.value)}
            />
            <input
              type="number"
              min="0"
              step="0.01"
              placeholder={t("debts.maxDebt")}
              value={maxDebt}
              onChange={(e) => setMaxDebt(e.target.value)}
            />
            <select value={sort} onChange={(e) => setSort(e.target.value)}>
              <option value="debt_desc">{t("debts.debtHighest")}</option>
              <option value="debt_asc">{t("debts.debtLowest")}</option>
              <option value="recent">{t("debts.recent")}</option>
            </select>
          </div>
          <div className="table-wrap">
            <table className="product-table">
              <thead>
                <tr>
                  <th>{t("debts.customer")}</th>
                  <th>{t("debts.phone")}</th>
                  <th>{t("debts.currentDebt")}</th>
                  <th>{t("debts.currentCredit")}</th>
                  <th>{t("debts.lastTransaction")}</th>
                  <th>{t("debts.lastPayment")}</th>
                  <th>{t("debts.status")}</th>
                  <th>{t("debts.action")}</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan="8">{t("debts.loading")}</td>
                  </tr>
                ) : rows.length ? (
                  rows.map((row) => (
                    <tr key={row.id}>
                      <td>
                        {row.name}
                        <br />
                        <small>{row.business_name || ""}</small>
                      </td>
                      <td>{row.phone || "—"}</td>
                      <td>{euro(row.current_debt)}</td>
                      <td>{euro(row.current_credit)}</td>
                      <td>
                        {dateOnly(row.debt_transactions_max_transaction_date)}
                      </td>
                      <td>{dateOnly(row.last_payment_at)}</td>
                      <td>
                        <span className={`credit-state-badge ${creditState(row)}`}>
                          {t(`debts.state.${creditState(row)}`)}
                        </span>
                        <small className="customer-debt-status">{statusText(row)}</small>
                      </td>
                      <td>
                        <div className="table-actions">
                          <button onClick={() => open(row.id)}>
                            {t("debts.openSheet")}
                          </button>
                          {canManageCustomers && (
                            <button
                              className="danger"
                              disabled={busy || Number(row.current_debt) > 0 || Number(row.current_credit) > 0}
                              onClick={() => removeSheet(row)}
                            >
                              {t("debts.deleteSheet")}
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan="8">{t("debts.empty")}</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          <div className="pagination">
            <button
              className="secondary"
              disabled={loading || pagination.current_page <= 1}
              onClick={() => {
                const next = page - 1;
                setPage(next);
                load(next, false);
              }}
            >
              {t("debts.previous")}
            </button>
            <span>
              {t("debts.page")} {pagination.current_page} /{" "}
              {pagination.last_page}
            </span>
            <button
              className="secondary"
              disabled={
                loading || pagination.current_page >= pagination.last_page
              }
              onClick={() => {
                const next = page + 1;
                setPage(next);
                load(next, false);
              }}
            >
              {t("debts.next")}
            </button>
          </div>
        </section>
      )}
    </main>
  );
}
