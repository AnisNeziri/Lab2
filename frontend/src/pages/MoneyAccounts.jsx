import { useUiText } from '../hooks/useUiText'
import { Fragment, useCallback, useEffect, useMemo, useState } from "react";
import { ArrowLeftRight, Banknote, Landmark, RefreshCw, Search, Upload } from "lucide-react";
import {
  createFinancialAccount,
  getBankMatchSuggestions,
  getBankStatement,
  getBankStatements,
  getFinancialAccounts,
  getFinancialPostingAccounts,
  getFinancialTransactions,
  ignoreBankRow,
  importBankStatement,
  postFinancialTransaction,
  reconcileBankRow,
  transferFinancialAccounts,
  unmatchBankRow,
} from "../api/advancedOperations";
import { useAuthStore } from "../store/authStore";
import { useSettingsStore } from "../store/settingsStore";
import "./MoneyAccounts.css";

const copy = {
  en: {
    title: "Cash & Bank",
    sub: "Calculated balances, one money movement per business event, and controlled bank reconciliation.",
    accounts: "Accounts", cash: "Cashbox", bank: "Bank account", newAccount: "New account",
    movement: "Record movement", transfer: "Own-account transfer", statements: "Bank reconciliation",
    save: "Save", refresh: "Refresh", loading: "Loading…", empty: "No records yet.",
    noBankAccounts: "Create a bank account before importing a statement.",
    imported: "Statement imported.", accountSaved: "Account saved.", movementSaved: "Movement recorded.",
    transferSaved: "Transfer recorded.", confirm: "Confirm match", chooseMatch: "Choose match",
    reviewMatch: "Review match", noMatches: "No exact-value system transaction was found.",
    ignore: "Ignore", unmatch: "Unmatch", reopen: "Reopen", importCsv: "Import CSV",
    bankAccount: "Bank account", csvFile: "CSV file", mapping: "Column mapping", notMapped: "Not mapped",
    mappingHelp: "Map Date and either Amount, or both Debit and Credit.",
    mappingRequired: "Map Date and either Amount, or both Debit and Credit before importing.",
    type: "Type", name: "Name", openingBalance: "Opening balance", amount: "Amount", date: "Date",
    reason: "Reason / description", counterparty: "Counterparty", reference: "Reference",
    from: "From", to: "To", notes: "Notes", description: "Description", status: "Status",
    action: "Action", transaction: "System transaction", ignoredReason: "Reviewed bank line without a matching ledger transaction",
    classification: "Accounting category", classificationHint: "Required so this cash movement is posted to the correct ledger account.",
    exchangeRate: "Exchange rate to company currency", exchangeRateDate: "Rate date", exchangeRateSource: "Rate source",
    reopenedReason: "Reopened for review", operationFailed: "The operation could not be completed.",
  },
  sq: {
    title: "Arka & Banka",
    sub: "Gjendje të llogaritura, një lëvizje parash për çdo veprim dhe rakordim bankar i kontrolluar.",
    accounts: "Llogaritë", cash: "Arka", bank: "Llogari bankare", newAccount: "Llogari e re",
    movement: "Regjistro lëvizjen", transfer: "Transfer mes llogarive", statements: "Rakordimi bankar",
    save: "Ruaj", refresh: "Rifresko", loading: "Duke ngarkuar…", empty: "Nuk ka të dhëna.",
    noBankAccounts: "Krijoni një llogari bankare para importimit të ekstraktit.",
    imported: "Ekstrakti u importua.", accountSaved: "Llogaria u ruajt.", movementSaved: "Lëvizja u regjistrua.",
    transferSaved: "Transferi u regjistrua.", confirm: "Konfirmo përputhjen", chooseMatch: "Zgjidh përputhjen",
    reviewMatch: "Shqyrto përputhjen", noMatches: "Nuk u gjet transaksion i sistemit me vlerë të njëjtë.",
    ignore: "Injoro", unmatch: "Hiq përputhjen", reopen: "Rihap", importCsv: "Importo CSV",
    bankAccount: "Llogaria bankare", csvFile: "Skedari CSV", mapping: "Lidhja e kolonave", notMapped: "Pa lidhur",
    mappingHelp: "Lidhni Datën dhe Shumën, ose të dyja kolonat Debi dhe Kredi.",
    mappingRequired: "Lidhni Datën dhe Shumën, ose Debinë dhe Kredinë para importimit.",
    type: "Lloji", name: "Emri", openingBalance: "Gjendja fillestare", amount: "Shuma", date: "Data",
    reason: "Arsyeja / përshkrimi", counterparty: "Pala tjetër", reference: "Referenca",
    from: "Nga", to: "Te", notes: "Shënime", description: "Përshkrimi", status: "Statusi",
    action: "Veprimi", transaction: "Transaksioni në sistem", ignoredReason: "Rresht bankar i shqyrtuar pa transaksion përkatës në sistem",
    classification: "Kategoria kontabël", classificationHint: "Kërkohet që kjo lëvizje parash të regjistrohet në llogarinë e saktë kontabël.",
    exchangeRate: "Kursi në valutën e kompanisë", exchangeRateDate: "Data e kursit", exchangeRateSource: "Burimi i kursit",
    reopenedReason: "Rihapur për shqyrtim", operationFailed: "Veprimi nuk mund të përfundohej.",
  },
};

const movementLabels = {
  en: { inflow: "Money in", outflow: "Money out", adjustment_in: "Increase adjustment", adjustment_out: "Decrease adjustment", refund_in: "Refund received", refund_out: "Refund paid", transfer_in: "Transfer in", transfer_out: "Transfer out" },
  sq: { inflow: "Hyrje parash", outflow: "Dalje parash", adjustment_in: "Korrigjim rritës", adjustment_out: "Korrigjim zbritës", refund_in: "Rimbursim i pranuar", refund_out: "Rimbursim i paguar", transfer_in: "Transfer hyrës", transfer_out: "Transfer dalës" },
};
const statusLabels = {
  en: { posted: "Posted", reversed: "Reversed", unmatched: "Unmatched", suggested: "Suggested", reconciled: "Reconciled", ignored: "Ignored" },
  sq: { posted: "Regjistruar", reversed: "Anuluar", unmatched: "Pa përputhje", suggested: "Sugjeruar", reconciled: "Rakorduar", ignored: "Injoruar" },
};
const mappingLabels = {
  en: { date: "Date", description: "Description", reference: "Reference", amount: "Signed amount", debit: "Debit", credit: "Credit", balance: "Balance" },
  sq: { date: "Data", description: "Përshkrimi", reference: "Referenca", amount: "Shuma me shenjë", debit: "Debi", credit: "Kredi", balance: "Gjendja" },
};

const rows = (value) => Array.isArray(value) ? value : Array.isArray(value?.data) ? value.data : [];
const uuid = () => crypto.randomUUID?.() || `${Date.now()}-${Math.random()}`;
const today = () => new Date().toISOString().slice(0, 10);
const signedAmount = (transaction) => ["inflow", "transfer_in", "adjustment_in", "refund_in"].includes(transaction.type)
  ? Number(transaction.amount) : -Number(transaction.amount);
const errorText = (error, fallback) => error?.errors
  ? Object.values(error.errors).flat().join(" ") : error?.message || fallback;

function Field({ label, children, hint }) {
  return <label className="money-field"><span>{label}</span>{children}{hint && <small>{hint}</small>}</label>;
}

function formatMoney(value, currency, language) {
  const number = Number(value);
  if (!Number.isFinite(number)) return "—";
  return new Intl.NumberFormat(language === "sq" ? "sq-XK" : "en-IE", {
    style: "currency", currency: currency || "EUR",
  }).format(number);
}

export default function MoneyAccounts() {
 const tx = useUiText()

  const language = useSettingsStore((state) => state.language);
  const baseCurrency = useSettingsStore((state) => state.base_currency || "EUR");
  const permissions = useAuthStore((state) => state.permissions);
  const t = copy[language] || copy.en;
  const canManageAccounts = permissions.includes("financial_accounts.manage");
  const canAdjustAccounts = permissions.includes("financial_accounts.adjust");
  const canViewReconciliation = permissions.includes("bank_reconciliation.view");
  const canManageReconciliation = permissions.includes("bank_reconciliation.manage");
  const canConfirmReconciliation = permissions.includes("bank_reconciliation.confirm");
  const [accounts, setAccounts] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [transactions, setTransactions] = useState([]);
  const [postingAccounts, setPostingAccounts] = useState([]);
  const [statements, setStatements] = useState([]);
  const [statement, setStatement] = useState(null);
  const [tab, setTab] = useState("accounts");
  const [busy, setBusy] = useState(true);
  const [transactionRevision, setTransactionRevision] = useState(0);
  const [notice, setNotice] = useState("");
  const [failed, setFailed] = useState(false);
  const selected = useMemo(
    () => accounts.find((item) => Number(item.id) === Number(selectedId)) || accounts[0] || null,
    [accounts, selectedId],
  );

  const reload = useCallback(async () => {
    setBusy(true);
    try {
      const accountData = await getFinancialAccounts();
      const nextAccounts = rows(accountData);
      setAccounts(nextAccounts);
      setSelectedId((current) => nextAccounts.some((item) => Number(item.id) === Number(current)) ? current : nextAccounts[0]?.id || null);
      if (canViewReconciliation) setStatements(rows(await getBankStatements()));
      else setStatements([]);
      if (canAdjustAccounts) setPostingAccounts(rows(await getFinancialPostingAccounts()));
      else setPostingAccounts([]);
      setTransactionRevision((value) => value + 1);
      setFailed(false);
    } catch (error) {
      setNotice(errorText(error, t.operationFailed));
      setFailed(true);
    } finally {
      setBusy(false);
    }
  }, [canAdjustAccounts, canViewReconciliation, t.operationFailed]);

  useEffect(() => { reload(); }, [reload]);
  useEffect(() => {
    if (!selected?.id) {
      setTransactions([]);
      return;
    }
    getFinancialTransactions(selected.id, { per_page: 100 })
      .then((value) => setTransactions(rows(value)))
      .catch(() => setTransactions([]));
  }, [selected?.id, transactionRevision]);

  const run = async (operation, success = t.save) => {
    setBusy(true);
    setNotice("");
    try {
      const result = await operation();
      setNotice(success);
      setFailed(false);
      await reload();
      return result;
    } catch (error) {
      setNotice(errorText(error, t.operationFailed));
      setFailed(true);
      return null;
    } finally {
      setBusy(false);
    }
  };

  return <div className="money-page">
    <header className="money-hero">
      <div><p>{tx("AIMS FINANCE")}</p><h1>{t.title}</h1><span>{t.sub}</span></div>
      <button type="button" className="secondary" disabled={busy} onClick={reload}><RefreshCw size={16} /> {t.refresh}</button>
    </header>
    <nav className="money-tabs" aria-label={t.title}>
      <button type="button" className={tab === "accounts" ? "active" : ""} onClick={() => setTab("accounts")}><Banknote size={17} /> {t.accounts}</button>
      {canViewReconciliation && <button type="button" className={tab === "statements" ? "active" : ""} onClick={() => setTab("statements")}><Landmark size={17} /> {t.statements}</button>}
    </nav>
    {notice && <p role={failed ? "alert" : "status"} className={`money-notice ${failed ? "error" : ""}`}>{notice}</p>}
    {busy && !accounts.length ? <p>{t.loading}</p> : null}
    {tab === "accounts"
      ? <Accounts accounts={accounts} postingAccounts={postingAccounts} baseCurrency={baseCurrency} selected={selected} setSelectedId={setSelectedId} transactions={transactions} run={run} busy={busy} t={t} language={language} canManage={canManageAccounts} canAdjust={canAdjustAccounts} />
      : <Statements accounts={accounts} statements={statements} selectedStatement={statement} setStatement={setStatement} run={run} busy={busy} t={t} language={language} canManage={canManageReconciliation} canConfirm={canConfirmReconciliation} />}
  </div>;
}

function Accounts({ accounts, postingAccounts, baseCurrency, selected, setSelectedId, transactions, run, busy, t, language, canManage, canAdjust }) {
 const tx = useUiText()

  const initialAccount = () => ({ type: "cashbox", name: "", currency: "EUR", opening_balance: "0", opening_date: today(), bank_name: "", account_number: "" });
  const initialMovement = () => ({ type: "inflow", amount: "", transaction_date: today(), counterparty: "", reference_number: "", description: "", counter_accounting_account_id: "", exchange_rate: "", exchange_rate_date: today(), exchange_rate_source: "" });
  const initialTransfer = () => ({ source_account_id: "", destination_account_id: "", amount: "", transfer_date: today(), reference_number: "", notes: "" });
  const [form, setForm] = useState(initialAccount);
  const [movement, setMovement] = useState(initialMovement);
  const [transfer, setTransfer] = useState(initialTransfer);
  const labels = movementLabels[language] || movementLabels.en;
  const statuses = statusLabels[language] || statusLabels.en;

  const createAccount = async (event) => {
    event.preventDefault();
    const result = await run(() => createFinancialAccount({ ...form, opening_balance: Number(form.opening_balance), is_active: true }), t.accountSaved);
    if (result) setForm(initialAccount());
  };
  const createMovement = async (event) => {
    event.preventDefault();
    const foreign = selected.currency !== baseCurrency;
    const payload = {
      ...movement,
      amount: Number(movement.amount),
      counter_accounting_account_id: Number(movement.counter_accounting_account_id),
      exchange_rate: foreign ? Number(movement.exchange_rate) : 1,
      exchange_rate_date: foreign ? movement.exchange_rate_date : null,
      exchange_rate_source: foreign ? movement.exchange_rate_source : null,
      idempotency_key: uuid(),
    };
    const result = await run(() => postFinancialTransaction(selected.id, payload), t.movementSaved);
    if (result) setMovement(initialMovement());
  };
  const createTransfer = async (event) => {
    event.preventDefault();
    if (transfer.source_account_id === transfer.destination_account_id) return;
    const result = await run(() => transferFinancialAccounts({ ...transfer, source_account_id: Number(transfer.source_account_id), destination_account_id: Number(transfer.destination_account_id), amount: Number(transfer.amount), idempotency_key: uuid() }), t.transferSaved);
    if (result) setTransfer(initialTransfer());
  };

  return <>
    <section className="money-account-grid">
      {accounts.map((account) => <button type="button" key={account.id} className={`money-account-card ${selected?.id === account.id ? "active" : ""}`} onClick={() => setSelectedId(account.id)}>
        <span>{account.type === "bank" ? <Landmark size={22} /> : <Banknote size={22} />}</span>
        <div><small>{account.type === "bank" ? t.bank : t.cash}</small><strong>{account.name}</strong><em>{formatMoney(account.current_balance, account.currency, language)}</em></div>
      </button>)}
    </section>
    <section className={`money-layout ${!canManage ? "money-layout-single" : ""}`}>
      {canManage && <aside className="money-panel"><h2>{t.newAccount}</h2><form onSubmit={createAccount}>
        <Field label={t.type}><select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value })}><option value="cashbox">{t.cash}</option><option value="bank">{t.bank}</option></select></Field>
        <Field label={t.name}><input required maxLength={120} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
        {form.type === "bank" && <><Field label={tx("Bank")}><input maxLength={255} value={form.bank_name} onChange={(event) => setForm({ ...form, bank_name: event.target.value })} /></Field><Field label={tx("IBAN / Account")}><input maxLength={100} value={form.account_number} onChange={(event) => setForm({ ...form, account_number: event.target.value })} /></Field></>}
        <Field label={t.openingBalance}><input type="number" step="0.01" value={form.opening_balance} onChange={(event) => setForm({ ...form, opening_balance: event.target.value })} /></Field>
        <button disabled={busy}>{t.save}</button>
      </form></aside>}
      <div className="money-main">
        {canAdjust && <section className="money-panel"><h2>{selected?.name || t.empty}</h2>{selected && <form className="money-inline-form" onSubmit={createMovement}>
          <Field label={t.movement}><select value={movement.type} onChange={(event) => setMovement({ ...movement, type: event.target.value })}>{["inflow", "outflow", "adjustment_in", "adjustment_out", "refund_in", "refund_out"].map((value) => <option key={value} value={value}>{labels[value]}</option>)}</select></Field>
          <Field label={t.amount}><input required type="number" min="0.01" step="0.01" value={movement.amount} onChange={(event) => setMovement({ ...movement, amount: event.target.value })} /></Field>
          <Field label={t.date}><input required type="date" value={movement.transaction_date} onChange={(event) => setMovement({ ...movement, transaction_date: event.target.value })} /></Field>
          <Field label={t.counterparty}><input maxLength={255} value={movement.counterparty} onChange={(event) => setMovement({ ...movement, counterparty: event.target.value })} /></Field>
          <Field label={t.reference}><input maxLength={255} value={movement.reference_number} onChange={(event) => setMovement({ ...movement, reference_number: event.target.value })} /></Field>
          <Field label={t.reason}><input required={movement.type.startsWith("adjustment")} maxLength={2000} value={movement.description} onChange={(event) => setMovement({ ...movement, description: event.target.value })} /></Field>
          <Field label={t.classification} hint={t.classificationHint}><select required value={movement.counter_accounting_account_id} onChange={(event) => setMovement({ ...movement, counter_accounting_account_id: event.target.value })}><option value="">—</option>{postingAccounts.map((account) => <option key={account.id} value={account.id}>{account.code} · {account.name}</option>)}</select></Field>
          {selected.currency !== baseCurrency && <>
            <Field label={t.exchangeRate}><input required type="number" min="0.00000001" step="0.00000001" value={movement.exchange_rate} onChange={(event) => setMovement({ ...movement, exchange_rate: event.target.value })} /></Field>
            <Field label={t.exchangeRateDate}><input required type="date" value={movement.exchange_rate_date} onChange={(event) => setMovement({ ...movement, exchange_rate_date: event.target.value })} /></Field>
            <Field label={t.exchangeRateSource}><input required maxLength={255} value={movement.exchange_rate_source} onChange={(event) => setMovement({ ...movement, exchange_rate_source: event.target.value })} /></Field>
          </>}
          <button disabled={busy}>{t.save}</button>
        </form>}</section>}
        {canManage && accounts.length > 1 && <section className="money-panel"><h2><ArrowLeftRight size={18} /> {t.transfer}</h2><form className="money-inline-form" onSubmit={createTransfer}>
          <Field label={t.from}><select required value={transfer.source_account_id} onChange={(event) => setTransfer({ ...transfer, source_account_id: event.target.value })}><option value="">—</option>{accounts.filter((account) => account.is_active).map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
          <Field label={t.to}><select required value={transfer.destination_account_id} onChange={(event) => setTransfer({ ...transfer, destination_account_id: event.target.value })}><option value="">—</option>{accounts.filter((account) => account.is_active && String(account.id) !== String(transfer.source_account_id)).map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
          <Field label={t.amount}><input required type="number" min="0.01" step="0.01" value={transfer.amount} onChange={(event) => setTransfer({ ...transfer, amount: event.target.value })} /></Field>
          <Field label={t.reference}><input maxLength={255} value={transfer.reference_number} onChange={(event) => setTransfer({ ...transfer, reference_number: event.target.value })} /></Field>
          <Field label={t.notes}><input maxLength={2000} value={transfer.notes} onChange={(event) => setTransfer({ ...transfer, notes: event.target.value })} /></Field>
          <button disabled={busy || !transfer.destination_account_id}>{t.save}</button>
        </form></section>}
        <section className="money-panel money-table"><table><thead><tr><th>{t.date}</th><th>{t.type}</th><th>{t.description}</th><th>{t.reference}</th><th>{t.amount}</th><th>{t.status}</th></tr></thead><tbody>
          {transactions.map((item) => <tr key={item.id}><td>{item.transaction_date}</td><td>{labels[item.type] || item.type}</td><td>{item.description || item.counterparty || "—"}</td><td>{item.reference_number || "—"}</td><td>{formatMoney(signedAmount(item), item.currency, language)}</td><td><span className={`money-status ${item.status}`}>{statuses[item.status] || item.status}</span></td></tr>)}
          {!transactions.length && <tr><td colSpan="6" className="money-empty">{t.empty}</td></tr>}
        </tbody></table></section>
      </div>
    </section>
  </>;
}

function parseHeader(line, separator) {
  const result = [];
  let current = "";
  let quoted = false;
  for (let index = 0; index < line.length; index += 1) {
    const character = line[index];
    if (character === '"' && quoted && line[index + 1] === '"') { current += '"'; index += 1; }
    else if (character === '"') quoted = !quoted;
    else if (character === separator && !quoted) { result.push(current.trim()); current = ""; }
    else current += character;
  }
  result.push(current.trim());
  return result.map((value, index) => index === 0 ? value.replace(/^\uFEFF/, "") : value);
}

function Statements({ accounts, statements, selectedStatement, setStatement, run, busy, t, language, canManage, canConfirm }) {
  const [file, setFile] = useState(null);
  const [headers, setHeaders] = useState([]);
  const [accountId, setAccountId] = useState("");
  const [delimiter, setDelimiter] = useState("auto");
  const [mapping, setMapping] = useState({ date: "", description: "", reference: "", amount: "", debit: "", credit: "", balance: "" });
  const [matchingRowId, setMatchingRowId] = useState(null);
  const [candidates, setCandidates] = useState([]);
  const [selectedMatch, setSelectedMatch] = useState("");
  const [matchBusy, setMatchBusy] = useState(false);
  const bankAccounts = accounts.filter((item) => item.type === "bank" && item.is_active);
  const statuses = statusLabels[language] || statusLabels.en;
  const labels = movementLabels[language] || movementLabels.en;
  const mapLabels = mappingLabels[language] || mappingLabels.en;

  const chooseFile = async (value) => {
    setFile(value || null);
    setHeaders([]);
    if (!value) return;
    const first = (await value.text()).split(/\r?\n/, 1)[0] || "";
    const counts = [[",", (first.match(/,/g) || []).length], [";", (first.match(/;/g) || []).length], ["\t", (first.match(/\t/g) || []).length]];
    counts.sort((left, right) => right[1] - left[1]);
    const separator = counts[0][0];
    const columns = parseHeader(first, separator);
    setDelimiter(separator === "\t" ? "tab" : separator);
    setHeaders(columns);
    const find = (...names) => columns.find((column) => names.some((name) => column.toLocaleLowerCase().includes(name))) || "";
    setMapping({ date: find("date", "data"), description: find("description", "përshkrim", "pershkrim", "details"), reference: find("reference", "referenc"), amount: find("amount", "shuma"), debit: find("debit", "debi"), credit: find("credit", "kredit"), balance: find("balance", "gjendje") });
  };

  const open = async (id) => {
    const value = await getBankStatement(id);
    setStatement(value);
    setMatchingRowId(null);
  };

  const upload = async (event) => {
    event.preventDefault();
    if (!file || !mapping.date || (!mapping.amount && (!mapping.debit || !mapping.credit))) {
      await run(() => Promise.reject(new Error(t.mappingRequired)), "");
      return;
    }
    const body = new FormData();
    body.append("financial_account_id", accountId);
    body.append("file", file);
    body.append("column_mapping", JSON.stringify(mapping));
    body.append("delimiter", delimiter);
    const imported = await run(() => importBankStatement(body), t.imported);
    if (imported) {
      setStatement(imported);
      setFile(null);
      setHeaders([]);
    }
  };

  const loadCandidates = async (row) => {
    setMatchingRowId(row.id);
    setSelectedMatch(row.matched_transaction_id ? String(row.matched_transaction_id) : "");
    setMatchBusy(true);
    const [suggestedValue, accountTransactions] = await Promise.all([
      getBankMatchSuggestions(row.id).catch(() => []),
      getFinancialTransactions(selectedStatement.financial_account_id, { per_page: 100 }).catch(() => []),
    ]);
    const exactTransactions = rows(accountTransactions).filter((transaction) => transaction.status === "posted" && Math.abs(signedAmount(transaction) - Number(row.amount)) < 0.005);
    const unique = new Map([...rows(suggestedValue), ...exactTransactions].map((transaction) => [String(transaction.id), transaction]));
    setCandidates([...unique.values()].sort((left, right) => Number(right.match_score || 0) - Number(left.match_score || 0)));
    setMatchBusy(false);
  };

  const confirmMatch = async (row) => {
    const result = await run(async () => {
      await reconcileBankRow(row.id, Number(selectedMatch));
      return getBankStatement(selectedStatement.id);
    }, t.confirm);
    if (result) {
      setStatement(result);
      setMatchingRowId(null);
    }
  };

  const changeRowStatus = async (row, action) => {
    const result = await run(async () => {
      if (action === "ignore") await ignoreBankRow(row.id, t.ignoredReason);
      else await unmatchBankRow(row.id, t.reopenedReason);
      return getBankStatement(selectedStatement.id);
    }, action === "ignore" ? t.ignore : t.reopen);
    if (result) setStatement(result);
  };

  return <section className={`money-layout ${!canManage ? "money-layout-single" : ""}`}>
    <aside className="money-panel">
      {canManage && <><h2><Upload size={18} /> {t.importCsv}</h2>{bankAccounts.length
        ? <form onSubmit={upload}>
          <Field label={t.bankAccount}><select required value={accountId} onChange={(event) => setAccountId(event.target.value)}><option value="">—</option>{bankAccounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>
          <Field label={t.csvFile}><input required type="file" accept=".csv,.txt,text/csv,text/plain" onChange={(event) => chooseFile(event.target.files?.[0])} /></Field>
          {headers.length > 0 && <div className="money-mapping"><strong>{t.mapping}</strong><small>{t.mappingHelp}</small>{Object.keys(mapping).map((key) => <Field key={key} label={`${mapLabels[key]}${key === "date" ? " *" : ""}`}><select value={mapping[key]} onChange={(event) => setMapping({ ...mapping, [key]: event.target.value })}><option value="">{t.notMapped}</option>{headers.map((header) => <option key={header} value={header}>{header}</option>)}</select></Field>)}</div>}
          <button disabled={busy || !headers.length}>{t.save}</button>
        </form>
        : <p className="money-muted">{t.noBankAccounts}</p>}</>}
      <div className="money-statement-list">{statements.map((item) => <button type="button" key={item.id} className={selectedStatement?.id === item.id ? "active" : ""} onClick={() => open(item.id)}><strong>{item.file_name}</strong><span>{item.account?.name} · {item.period_from || "—"} → {item.period_to || "—"}</span><small>{item.reconciled_rows_count || 0}/{item.rows_count || 0} {statuses.reconciled.toLocaleLowerCase()}</small></button>)}</div>
    </aside>
    <div className="money-panel"><h2>{selectedStatement?.file_name || t.statements}</h2>{selectedStatement ? <div className="money-table"><table><thead><tr><th>{t.date}</th><th>{t.description}</th><th>{t.amount}</th><th>{t.status}</th><th>{t.action}</th></tr></thead><tbody>
      {(selectedStatement.rows || []).map((row) => <Fragment key={row.id}>
        <tr><td>{row.transaction_date}</td><td>{row.description || "—"}<small>{row.reference_number || ""}</small></td><td>{formatMoney(row.amount, selectedStatement.account?.currency, language)}</td><td><span className={`money-status ${row.status}`}>{statuses[row.status] || row.status}</span></td><td><div className="money-row-actions">
          {canConfirm && !["reconciled", "ignored"].includes(row.status) && <button type="button" onClick={() => loadCandidates(row)}><Search size={14} /> {row.status === "suggested" ? t.reviewMatch : t.chooseMatch}</button>}
          {canConfirm && row.status === "reconciled" && <button type="button" className="secondary" disabled={busy} onClick={() => changeRowStatus(row, "unmatch")}>{t.unmatch}</button>}
          {canConfirm && row.status === "ignored" && <button type="button" className="secondary" disabled={busy} onClick={() => changeRowStatus(row, "unmatch")}>{t.reopen}</button>}
          {canConfirm && !["reconciled", "ignored"].includes(row.status) && <button type="button" className="secondary" disabled={busy} onClick={() => changeRowStatus(row, "ignore")}>{t.ignore}</button>}
        </div></td></tr>
        {canConfirm && matchingRowId === row.id && <tr className="money-match-row"><td colSpan="5">{matchBusy ? <span>{t.loading}</span> : candidates.length ? <div className="money-match-picker"><Field label={t.transaction}><select value={selectedMatch} onChange={(event) => setSelectedMatch(event.target.value)}><option value="">—</option>{candidates.map((candidate) => <option key={candidate.id} value={candidate.id}>{candidate.transaction_date} · {labels[candidate.type] || candidate.type} · {formatMoney(signedAmount(candidate), candidate.currency, language)} · {candidate.reference_number || candidate.description || `#${candidate.id}`}</option>)}</select></Field><button type="button" disabled={busy || !selectedMatch} onClick={() => confirmMatch(row)}>{t.confirm}</button></div> : <p className="money-muted">{t.noMatches}</p>}</td></tr>}
      </Fragment>)}
      {!selectedStatement.rows?.length && <tr><td colSpan="5" className="money-empty">{t.empty}</td></tr>}
    </tbody></table></div> : <p className="money-muted">{t.empty}</p>}</div>
  </section>;
}
