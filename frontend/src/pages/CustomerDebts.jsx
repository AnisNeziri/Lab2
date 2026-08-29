import { useCallback, useEffect, useMemo, useState } from "react";
import { Plus, WalletCards } from "lucide-react";
import {
  createCustomer,
  createCustomerDebtEntry,
  deleteCustomerDebtSheet,
  downloadDebtStatement,
  getCustomerDebt,
  getCustomerDebts,
  recordCustomerDebtPayment,
  updateDebtTransaction,
  updateCustomer,
} from "../api/customerDebts";
import { getFinancialAccounts } from "../api/advancedOperations";
import { useTranslation } from "../hooks/useTranslation";

const euro = (value) =>
  new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(
    Number(value || 0),
  );
const today = () => new Date().toISOString().slice(0, 10);
const dateOnly = (value) => (value ? String(value).slice(0, 10) : "—");
const emptyForm = () => ({
  name: "",
  business_name: "",
  phone: "",
  email: "",
  address: "",
  tax_number: "",
  notes: "",
  amount: "",
  transaction_date: today(),
  due_date: "",
  payment_method: "cash",
  financial_account_id: "",
  reference_number: "",
  note: "",
  opening_balance: false,
});

export default function CustomerDebts() {
  const { t } = useTranslation();
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

  const remainingPreview = useMemo(
    () =>
      Math.max(
        0,
        Number(selected?.current_debt || 0) - Number(form.amount || 0),
      ),
    [selected?.current_debt, form.amount],
  );

  const open = async (id) => {
    setLoading(true);
    try {
      setCorrection(null);
      setSelected(await getCustomerDebt(id));
      setMode("");
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
    setMessage("");
    setError("");
  };

  const submit = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      if (mode === "customer") {
        await createCustomer(form);
      } else if (mode === "edit") {
        await updateCustomer(selected.id, {
          name: form.name,
          business_name: form.business_name || null,
          phone: form.phone || null,
          email: form.email || null,
          address: form.address || null,
          tax_number: form.tax_number || null,
          notes: form.notes || null,
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
          idempotency_key: crypto.randomUUID(),
        };
        if (mode === "debt") {
          await createCustomerDebtEntry(selected.id, payload);
        } else {
          await recordCustomerDebtPayment(selected.id, payload);
        }
      }
      setMode("");
      setForm(emptyForm());
      setMessage(t("debts.saved"));
      await load(page);
    } catch (e) {
      setError(e.errors ? Object.values(e.errors).flat().join(" ") : e.message);
    } finally {
      setBusy(false);
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
          <button onClick={() => begin("customer")}>
            <Plus size={16} />
            {t("debts.newCustomer")}
          </button>
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
                    max={
                      mode === "payment" ? selected?.current_debt : undefined
                    }
                    step="0.01"
                    value={form.amount}
                    onChange={(e) =>
                      setForm({ ...form, amount: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.date")}
                  <input
                    required
                    type="date"
                    value={form.transaction_date}
                    onChange={(e) =>
                      setForm({ ...form, transaction_date: e.target.value })
                    }
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
                        onChange={(e) =>
                          setForm({ ...form, due_date: e.target.value })
                        }
                      />
                    </label>
                    <label className="debt-opening-balance-field">
                      <input
                        type="checkbox"
                        checked={form.opening_balance}
                        onChange={(e) =>
                          setForm({
                            ...form,
                            opening_balance: e.target.checked,
                          })
                        }
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
                    onChange={(e) =>
                      setForm({ ...form, reference_number: e.target.value })
                    }
                  />
                </label>
                <label>
                  {t("debts.notes")}
                  <textarea
                    value={form.note}
                    onChange={(e) => setForm({ ...form, note: e.target.value })}
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
                onClick={() => setMode("")}
              >
                {t("debts.cancel")}
              </button>
            </div>
          </form>
        </section>
      )}

      {selected ? (
        <section className="card table-wrap">
          <div className="section-header">
            <div>
              <h2>{selected.name}</h2>
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
              <button onClick={() => begin("debt")}>
                {t("debts.addDebt")}
              </button>
              <button
                onClick={() => begin("payment")}
                disabled={Number(selected.current_debt) <= 0}
              >
                {t("debts.recordPayment")}
              </button>
              <button className="secondary" onClick={() => begin("edit")}>
                {t("debts.editCustomer")}
              </button>
              <button
                className="secondary"
                onClick={() => downloadDebtStatement(selected.id)}
              >
                {t("debts.export")}
              </button>
              <button className="secondary" onClick={() => window.print()}>
                {t("debts.print")}
              </button>
              <button
                className="danger"
                disabled={busy}
                onClick={() => removeSheet(selected)}
              >
                {t("debts.deleteSheet")}
              </button>
              <button
                className="secondary"
                onClick={() => {
                  setSelected(null);
                  setMode("");
                  setCorrection(null);
                }}
              >
                {t("debts.back")}
              </button>
            </div>
          </div>
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
          <section className="stats-grid stats-grid-3">
            <article className="stat-card">
              <p className="stat-label">{t("debts.currentDebt")}</p>
              <p className="stat-value">{euro(selected.current_debt)}</p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.lastPayment")}</p>
              <p className="stat-value">
                {dateOnly(selected.ledger_summary?.last_payment_at)}
              </p>
            </article>
            <article className="stat-card">
              <p className="stat-label">{t("debts.lastDebt")}</p>
              <p className="stat-value">
                {dateOnly(selected.ledger_summary?.last_debt_at)}
              </p>
            </article>
          </section>
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
                        <button
                          className="secondary"
                          disabled={busy}
                          onClick={() => correct(tx)}
                        >
                          {t("debts.correct")}
                        </button>
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
                  <th>{t("debts.lastTransaction")}</th>
                  <th>{t("debts.lastPayment")}</th>
                  <th>{t("debts.status")}</th>
                  <th>{t("debts.action")}</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan="7">{t("debts.loading")}</td>
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
                      <td>
                        {dateOnly(row.debt_transactions_max_transaction_date)}
                      </td>
                      <td>{dateOnly(row.last_payment_at)}</td>
                      <td>{statusText(row)}</td>
                      <td>
                        <div className="table-actions">
                          <button onClick={() => open(row.id)}>
                            {t("debts.openSheet")}
                          </button>
                          <button
                            className="danger"
                            disabled={busy}
                            onClick={() => removeSheet(row)}
                          >
                            {t("debts.deleteSheet")}
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan="7">{t("debts.empty")}</td>
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
