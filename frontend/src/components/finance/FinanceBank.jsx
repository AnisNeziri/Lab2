import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import * as api from "../../api/advancedOperations";
import WorkspaceModal from "../WorkspaceModal";
import EntityDocuments from "../EntityDocuments";
import { ReasonAction } from "./FinanceSubledgers";
import {
  Amount,
  Badge,
  CheckList,
  DateValue,
  Empty,
  Field,
  MoneyTable,
  Pager,
  QueryState,
  useFinance,
  useFinanceQuery,
  dateOnly,
} from "./FinanceShared";

export default function FinanceBank() {
  const { t, refresh, can, busy } = useFinance();
  const [params, setParams] = useSearchParams();
  const [statementPage, setStatementPage] = useState(1),
    [account, setAccount] = useState(() => params.get("account") || ""),
    [accountPage, setAccountPage] = useState(1),
    [action, setAction] = useState("");
  const accounts = useFinanceQuery(api.getFinancialAccounts, [refresh]);
  const statements = useFinanceQuery(
    () => api.getBankStatements({ page: statementPage }),
    [statementPage, refresh],
  );
  const selectedId = params.get("statement"),
    [rowsPage, setRowsPage] = useState(1);
  const recordId = params.get("transaction") || params.get("transfer"),
    recordType = params.get("transaction")
      ? "financial-transaction"
      : "financial-transfer";
  const sourceRecord = useFinanceQuery(
    () =>
      recordId
        ? recordType === "financial-transaction"
          ? api.getFinancialTransaction(recordId)
          : api.getFinancialTransfer(recordId)
        : null,
    [recordId, recordType, refresh],
  );
  const requestedAccount = params.get("account");
  useEffect(() => {
    if (requestedAccount) {
      setAccount(requestedAccount);
      setAccountPage(1);
    }
  }, [requestedAccount]);
  const statement = useFinanceQuery(
    () =>
      selectedId ? api.getBankStatement(selectedId, { page: rowsPage }) : null,
    [selectedId, rowsPage, refresh],
  );
  const ledger = useFinanceQuery(
    () =>
      account
        ? api.getFinancialTransactions(account, { page: accountPage })
        : null,
    [account, accountPage, refresh],
  );
  return (
    <>
      <section className="fc-panel">
        <div className="fc-section-head">
          <h2>
            {t("Cash registers & bank accounts", "Arkat dhe llogaritë bankare")}
          </h2>
          <div className="fc-toolbar">
            {can("financial_accounts.manage") && (
              <button disabled={busy} onClick={() => setAction("account")}>
                {t("New account", "Llogari e re")}
              </button>
            )}
            {can("financial_accounts.adjust") && (
              <button onClick={() => setAction("transfer")}>
                {t("Cash / bank transfer", "Transfer arkë / bankë")}
              </button>
            )}
            {can("bank_reconciliation.manage") && (
              <button onClick={() => setAction("import")}>
                {t("Stage bank CSV", "Ngarko CSV bankare")}
              </button>
            )}
          </div>
        </div>
        <QueryState query={accounts}>
          {(rows) =>
            rows.length ? (
              <MoneyTable
                label={t(
                  "Cash and bank accounts",
                  "Arkat dhe llogaritë bankare",
                )}
              >
                <thead>
                  <tr>
                    <th>{t("Account", "Llogaria")}</th>
                    <th>{t("Type / currency", "Lloji / monedha")}</th>
                    <th className="num">
                      {t("Current balance", "Gjendja aktuale")}
                    </th>
                    <th>{t("Status", "Gjendja")}</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((a) => (
                    <tr key={a.id}>
                      <td>
                        <button
                          className="fc-link"
                          onClick={() => {
                            setAccount(a.id);
                            setAccountPage(1);
                          }}
                        >
                          {a.name}
                        </button>
                      </td>
                      <td>
                        {a.type === "bank"
                          ? t("Bank", "Banka")
                          : t("Cash register", "Arka")}{" "}
                        · {a.currency}
                      </td>
                      <td className="num">
                        <Amount
                          value={a.current_balance ?? a.balance}
                          currency={a.currency}
                        />
                      </td>
                      <td>
                        <Badge
                          value={
                            a.is_active
                              ? t("Active", "Aktive")
                              : t("Inactive", "Joaktive")
                          }
                        />
                      </td>
                      <td>
                        <button
                          className="fc-link"
                          onClick={() => {
                            setAccount(a.id);
                            setAccountPage(1);
                          }}
                        >
                          {t("Ledger", "Libri")}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </MoneyTable>
            ) : (
              <Empty />
            )
          }
        </QueryState>
      </section>
      {recordId && (
        <QueryState query={sourceRecord}>
          {(r) => r && <CashSourceRecord record={r} type={recordType} />}
        </QueryState>
      )}
      {account && (
        <section className="fc-panel">
          <div className="fc-section-head">
            <h2>
              {t("Account ledger", "Libri i llogarisë")} ·{" "}
              {accounts.data?.find((a) => a.id === Number(account))?.name}
            </h2>
            <button onClick={() => setAccount("")}>
              {t("Close ledger", "Mbyll librin")}
            </button>
          </div>
          <QueryState query={ledger}>
            {(d) =>
              d && (
                <>
                  <MoneyTable
                    label={t(
                      "Cash / bank transactions",
                      "Transaksionet e arkës / bankës",
                    )}
                  >
                    <thead>
                      <tr>
                        <th>{t("Date", "Data")}</th>
                        <th>
                          {t(
                            "Reference / description",
                            "Referenca / përshkrimi",
                          )}
                        </th>
                        <th>{t("Type", "Lloji")}</th>
                        <th className="num">{t("Amount", "Shuma")}</th>
                        <th>{t("Status / journal", "Gjendja / ditari")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(d.data || []).map((r) => (
                        <tr key={r.id}>
                          <td>
                            <DateValue value={r.transaction_date} />
                          </td>
                          <td>
                            {r.reference_number || `#${r.id}`}
                            <small>{r.description}</small>
                          </td>
                          <td>{r.type.replaceAll("_", " ")}</td>
                          <td className="num">
                            <Amount value={r.amount} currency={r.currency} />
                          </td>
                          <td>
                            <Badge value={r.status} />
                            {r.journal_entry_id && (
                              <Link
                                to={`/accounting?tab=journal&journal=${r.journal_entry_id}`}
                              >
                                {" "}
                                ↗ {t("Journal", "Ditari")}
                              </Link>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </MoneyTable>
                  <Pager page={d} onPage={setAccountPage} />
                </>
              )
            }
          </QueryState>
          <EntityDocuments entityType="financial-account" entityId={account} />
        </section>
      )}
      {can("bank_reconciliation.view") && (
        <section className="fc-panel">
          <h2>
            {t(
              "Bank statement staging & reconciliation",
              "Ngarkimi dhe rakordimi i pasqyrave bankare",
            )}
          </h2>
          <QueryState query={statements}>
            {(d) => (
              <>
                <MoneyTable label={t("Bank statements", "Pasqyrat bankare")}>
                  <thead>
                    <tr>
                      <th>{t("File / account", "Skedari / llogaria")}</th>
                      <th>{t("Period", "Periudha")}</th>
                      <th>{t("Matched rows", "Rreshtat e përputhur")}</th>
                      <th>{t("Status", "Gjendja")}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {d.data.map((s) => (
                      <tr key={s.id}>
                        <td>
                          {s.file_name}
                          <small>{s.account?.name}</small>
                        </td>
                        <td>
                          {dateOnly(s.period_from)} — {dateOnly(s.period_to)}
                        </td>
                        <td>
                          {s.reconciled_rows_count} / {s.rows_count}
                        </td>
                        <td>
                          <Badge
                            value={s.reconciliation_status}
                            good={
                              s.reconciliation_status === "completed"
                                ? true
                                : undefined
                            }
                          />
                        </td>
                        <td>
                          <button
                            onClick={() => {
                              setRowsPage(1);
                              setParams({ tab: "bank", statement: s.id });
                            }}
                          >
                            {t("Review", "Shqyrto")}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
                <Pager page={d} onPage={setStatementPage} />
              </>
            )}
          </QueryState>
        </section>
      )}
      {selectedId && (
        <QueryState query={statement}>
          {(s) =>
            s && (
              <BankStatementDetail
                statement={s}
                onPage={setRowsPage}
                onClose={() => setParams({ tab: "bank" })}
              />
            )
          }
        </QueryState>
      )}
      {action === "import" && (
        <ImportStatement
          accounts={accounts.data || []}
          onClose={() => setAction("")}
          onSaved={(s) => {
            setAction("");
            setRowsPage(1);
            setParams({ tab: "bank", statement: s.id });
          }}
        />
      )}
      {["account", "transfer"].includes(action) && (
        <CashAccountAction
          action={action}
          accounts={accounts.data || []}
          onClose={() => setAction("")}
        />
      )}
    </>
  );
}
function BankStatementDetail({ statement: s, onPage, onClose }) {
  const { t, can, run, busy } = useFinance();
  const [match, setMatch] = useState(null),
    [action, setAction] = useState(null);
  const proof = s.balance_control;
  return (
    <section className="fc-panel">
      <div className="fc-section-head">
        <div>
          <h2>{s.file_name}</h2>
          <p>
            {s.account?.name} · <Badge value={s.reconciliation_status} />
          </p>
        </div>
        <button onClick={onClose}>
          {t("Close statement", "Mbyll pasqyrën")}
        </button>
      </div>
      <div className="fc-inline-totals">
        {[
          ["opening_balance", "Statement opening", "Fillimi i pasqyrës"],
          ["closing_balance", "Statement closing", "Fundi i pasqyrës"],
          ["aims_closing_balance", "AIMS balance", "Gjendja AIMS"],
          ["difference", "Difference", "Diferenca"],
        ].map(([k, en, sq]) => (
          <span key={k}>
            {t(en, sq)}{" "}
            <Amount value={proof[k]} currency={s.account.currency} />
          </span>
        ))}
      </div>
      <p className="fc-caption">{proof.policy}</p>
      <details open={!proof.ready_to_complete}>
        <summary>
          {t(
            "Reconciliation completion checks",
            "Kontrollet e përfundimit të rakordimit",
          )}
        </summary>
        <CheckList checks={proof.checks} />
      </details>
      <div className="fc-toolbar">
        {s.reconciliation_status !== "completed" &&
          can("bank_reconciliation.confirm") && (
            <button onClick={() => setAction({ type: "balances" })}>
              {t(
                "Review statement balances / dates",
                "Shqyrto gjendjet / datat e pasqyrës",
              )}
            </button>
          )}
        {s.reconciliation_status !== "completed" &&
          can("bank_reconciliation.complete") && (
            <button
              disabled={busy || !proof.ready_to_complete}
              onClick={() =>
                run(
                  () => api.completeBankStatement(s.id),
                  t(
                    "Bank reconciliation completed with a zero-difference proof.",
                    "Rakordimi bankar përfundoi me provë pa diferencë.",
                  ),
                )
              }
            >
              {t("Complete reconciliation", "Përfundo rakordimin")}
            </button>
          )}
        {s.reconciliation_status === "completed" &&
          can("bank_reconciliation.reopen") && (
            <button onClick={() => setAction({ type: "reopen" })}>
              {t("Reopen for review", "Rihap për shqyrtim")}
            </button>
          )}
      </div>
      <MoneyTable label={t("Statement rows", "Rreshtat e pasqyrës")}>
        <thead>
          <tr>
            <th>{t("Date / reference", "Data / referenca")}</th>
            <th>{t("Description", "Përshkrimi")}</th>
            <th className="num">{t("Bank amount", "Shuma bankare")}</th>
            <th>{t("Status / evidence", "Gjendja / dëshmitë")}</th>
            <th>{t("Actions", "Veprimet")}</th>
          </tr>
        </thead>
        <tbody>
          {s.rows.map((r) => (
            <tr key={r.id}>
              <td>
                <DateValue value={r.transaction_date} />
                <small>{r.reference_number || "—"}</small>
              </td>
              <td>{r.description || "—"}</td>
              <td className="num">
                <Amount value={r.amount} currency={s.account.currency} />
              </td>
              <td>
                <Badge
                  value={r.status}
                  good={r.status === "reconciled" ? true : undefined}
                />
                {r.duplicate_of_row_id && (
                  <small>
                    {r.duplicate_reviewed_at
                      ? t(
                          "Distinct event reviewed",
                          "Ngjarje e veçantë e shqyrtuar",
                        )
                      : t(
                          "Overlapping import: review duplicate evidence",
                          "Import i mbivendosur: shqyrto dyfishimin",
                        )}
                  </small>
                )}
                {r.matches?.map((m) => (
                  <small key={m.id}>
                    #{m.financial_account_transaction_id} ·{" "}
                    <Amount
                      value={m.signed_amount}
                      currency={s.account.currency}
                    />{" "}
                    {m.transaction?.journal_entry_id && (
                      <Link
                        to={`/accounting?tab=journal&journal=${m.transaction.journal_entry_id}`}
                      >
                        {t("Journal", "Ditari")} ↗
                      </Link>
                    )}
                  </small>
                ))}
              </td>
              <td>
                {s.reconciliation_status !== "completed" &&
                  can("bank_reconciliation.confirm") && (
                    <div className="fc-row-actions">
                      {r.status !== "reconciled" && (
                        <button onClick={() => setMatch(r)}>
                          {t("Review matches", "Shqyrto përputhjet")}
                        </button>
                      )}
                      {r.status === "reconciled" && (
                        <button
                          onClick={() => setAction({ type: "unmatch", row: r })}
                        >
                          {t("Release match", "Liro përputhjen")}
                        </button>
                      )}
                      {r.duplicate_of_row_id && !r.duplicate_reviewed_at && (
                        <button
                          onClick={() =>
                            setAction({ type: "duplicate", row: r })
                          }
                        >
                          {t("Review duplicate", "Shqyrto dyfishimin")}
                        </button>
                      )}
                      {r.status !== "reconciled" && (
                        <button
                          onClick={() => setAction({ type: "ignore", row: r })}
                        >
                          {t("Retain as unexplained", "Mbaje të pashpjeguar")}
                        </button>
                      )}
                    </div>
                  )}
              </td>
            </tr>
          ))}
        </tbody>
      </MoneyTable>
      <Pager page={s.pagination} onPage={onPage} />
      <p className="fc-caption">
        {proof.gl_qualification} · {t("GL balance", "Gjendja e librit kryesor")}
        : <Amount value={proof.gl_balance} currency={proof.gl_currency} />
      </p>
      {s.reconciliation_proof && (
        <details>
          <summary>
            {t("Saved completion evidence", "Dëshmia e ruajtur e përfundimit")}
          </summary>
          <p>
            {s.reconciliation_proof.historical
              ? t(
                  "Historical proof: review under this company before completing again.",
                  "Provë historike: shqyrtojeni në këtë kompani para përfundimit të ri.",
                )
              : s.reconciliation_proof.fingerprint}
          </p>
          <DateValue value={s.reconciled_at} time />
        </details>
      )}
      <EntityDocuments entityType="bank-statement" entityId={s.id} />
      {match && (
        <MatchStatementRow
          row={match}
          statement={s}
          onClose={() => setMatch(null)}
        />
      )}
      {action?.type === "balances" && (
        <StatementBalances statement={s} onClose={() => setAction(null)} />
      )}
      {action && action.type !== "balances" && (
        <ReasonAction
          title={
            {
              reopen: t(
                "Reopen completed statement",
                "Rihap pasqyrën e përfunduar",
              ),
              unmatch: t("Release bank match", "Liro përputhjen bankare"),
              duplicate: t(
                "Review overlapping bank evidence",
                "Shqyrto dëshmitë bankare të mbivendosura",
              ),
              ignore: t(
                "Keep bank item unexplained",
                "Mbaj zërin bankar të pashpjeguar",
              ),
            }[action.type]
          }
          impact={
            action.type === "duplicate"
              ? t(
                  "Confirm with bank evidence that this row is a distinct bank event. The original duplicate link remains in audit history.",
                  "Konfirmoni me dëshmi bankare se ky rresht është ngjarje e veçantë. Lidhja e dyfishimit mbetet në auditim.",
                )
              : action.type === "ignore"
                ? t(
                    "This keeps the row visible and blocks completion until a valid posted source explains its amount.",
                    "Rreshti mbetet i dukshëm dhe bllokon përfundimin derisa një burim i postuar shpjegon shumën.",
                  )
                : t(
                    "The original proof and match history remain. This action does not change cash or post a journal.",
                    "Prova dhe historiku origjinal mbeten. Veprimi nuk ndryshon mjetet dhe nuk poston ditar.",
                  )
          }
          showDate={false}
          onClose={() => setAction(null)}
          onConfirm={({ reason }) =>
            action.type === "reopen"
              ? api.reopenBankStatement(s.id, reason)
              : action.type === "unmatch"
                ? api.unmatchBankRow(action.row.id, reason)
                : action.type === "duplicate"
                  ? api.reviewBankDuplicate(action.row.id, reason)
                  : api.ignoreBankRow(action.row.id, reason)
          }
        />
      )}
    </section>
  );
}
function CashSourceRecord({ record: r, type }) {
  const { t } = useFinance();
  return (
    <section className="fc-panel">
      <h2>
        {type === "financial-transaction"
          ? t("Cash / bank transaction", "Transaksioni arkë / bankë")
          : t(
              "Internal cash / bank transfer",
              "Transferi i brendshëm arkë / bankë",
            )}{" "}
        #{r.id}
      </h2>
      <div className="fc-facts">
        <span>
          {t("Reference", "Referenca")}
          <b>{r.reference_number || "—"}</b>
        </span>
        <span>
          {t("Date", "Data")}
          <b>
            <DateValue value={r.transaction_date || r.transfer_date} />
          </b>
        </span>
        <span>
          {t("Amount", "Shuma")}
          <Amount
            value={r.amount}
            currency={r.currency || r.source_account?.currency}
          />
        </span>
        <span>
          {t("Account", "Llogaria")}
          <b>
            {r.account?.name ||
              `${r.source_account?.name} → ${r.destination_account?.name}`}
          </b>
        </span>
        <span>
          {t("Status", "Gjendja")}
          <Badge value={r.status} />
        </span>
      </div>
      <p>{r.description || r.notes}</p>
      {r.journal_entry_id && (
        <Link to={`/accounting?tab=journal&journal=${r.journal_entry_id}`}>
          {t("Open linked journal", "Hap ditarin e lidhur")} ↗
        </Link>
      )}
      <EntityDocuments entityType={type} entityId={r.id} />
    </section>
  );
}
function MatchStatementRow({ row, statement, onClose }) {
  const { t, busy, run } = useFinance(),
    [selected, setSelected] = useState({}),
    [search, setSearch] = useState(""),
    [manual, setManual] = useState(false),
    [page, setPage] = useState(1);
  const suggestions = useFinanceQuery(
    () => api.getBankMatchSuggestions(row.id),
    [row.id],
  );
  const transactions = useFinanceQuery(
    () =>
      manual
        ? api.getFinancialTransactions(statement.financial_account_id, {
            search,
            page,
            from: dateOnly(statement.period_from),
            to: dateOnly(statement.period_to),
          })
        : null,
    [manual, search, page],
  );
  const sum = Object.values(selected).reduce(
    (n, a) => n + Math.round(Number(a) * 100),
    0,
  );
  const target = Math.round(Number(row.amount) * 100);
  const candidates = manual
    ? transactions.data?.data || []
    : suggestions.data || [];
  return (
    <WorkspaceModal
      title={t(
        "Review bank match evidence",
        "Shqyrto dëshmitë e përputhjes bankare",
      )}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={Object.keys(selected).length > 0}
    >
      <form
        className="fc-form"
        onSubmit={async (e) => {
          e.preventDefault();
          const result = await run(() =>
            api.reconcileBankRows(row.id, Object.keys(selected).map(Number)),
          );
          if (result) onClose();
        }}
      >
        <p>
          <DateValue value={row.transaction_date} /> · {row.reference_number} ·{" "}
          <Amount value={row.amount} currency={statement.account.currency} />
        </p>
        <p>{row.description}</p>
        <p className="fc-caption">
          {t(
            "Select one or more complete existing transactions. Their signed sum must equal this bank row. Each transaction has one active owner.",
            "Zgjidh një ose disa transaksione të plota ekzistuese. Shuma me shenjë duhet të jetë e njëjtë me rreshtin bankar. Çdo transaksion ka një pronar aktiv.",
          )}
        </p>
        <label className="fc-check">
          <input
            type="checkbox"
            checked={manual}
            onChange={(e) => setManual(e.target.checked)}
          />
          {t(
            "Search account activity instead of suggestions",
            "Kërko aktivitetin e llogarisë në vend të sugjerimeve",
          )}
        </label>
        {manual && (
          <Field
            label={t("Reference / description", "Referenca / përshkrimi")}
            value={search}
            onChange={(e) => {
              setPage(1);
              setSearch(e.target.value);
            }}
          />
        )}
        {(manual ? transactions : suggestions).error && (
          <p role="alert" className="fc-error">
            {(manual ? transactions : suggestions).error}
          </p>
        )}
        {candidates.map((c) => {
          const amount =
            c.signed_amount ??
            (["inflow", "transfer_in", "adjustment_in", "refund_in"].includes(
              c.type,
            )
              ? Number(c.amount)
              : -Number(c.amount));
          return (
            <label className="fc-match-candidate" key={c.id}>
              <input
                type="checkbox"
                checked={selected[c.id] !== undefined}
                disabled={
                  c.status !== "posted" || c.match_evidence?.needs_posting
                }
                onChange={(e) => {
                  const next = { ...selected };
                  if (e.target.checked) next[c.id] = amount;
                  else delete next[c.id];
                  setSelected(next);
                }}
              />
              <div>
                <b>
                  {c.reference_number || `#${c.id}`} ·{" "}
                  <Amount value={amount} currency={c.currency} />
                </b>
                <small>
                  <DateValue value={c.transaction_date} /> · {c.description}
                </small>
                {c.match_evidence && (
                  <small>
                    {c.match_evidence.exact_amount
                      ? t("Exact amount", "Shumë e njëjtë")
                      : t("Split candidate", "Kandidat për ndarje")}{" "}
                    · {t("Date difference", "Diferenca e datës")}:{" "}
                    {c.match_evidence.date_difference_days} {t("days", "ditë")}{" "}
                    ·{" "}
                    {c.match_evidence.reference_match
                      ? t("Reference matches", "Referenca përputhet")
                      : t(
                          "Reference requires review",
                          "Referenca kërkon shqyrtim",
                        )}
                    {c.match_evidence.needs_posting &&
                      ` · ${t("Needs posting first", "Kërkon postim fillimisht")}`}
                  </small>
                )}
              </div>
            </label>
          );
        })}
        {manual && <Pager page={transactions.data} onPage={setPage} />}
        <div className="fc-total">
          <span>
            {t(
              "Selected total / difference",
              "Gjithsej e zgjedhur / diferenca",
            )}
          </span>
          <Amount value={sum / 100} currency={statement.account.currency} />
          <Amount
            value={(target - sum) / 100}
            currency={statement.account.currency}
          />
        </div>
        <button
          disabled={busy || sum !== target || !Object.keys(selected).length}
        >
          {t("Confirm explicit match", "Konfirmo përputhjen")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
function ImportStatement({ accounts, onClose, onSaved }) {
  const { t, run, busy } = useFinance();
  const [account, setAccount] = useState(""),
    [file, setFile] = useState(null),
    [delimiter, setDelimiter] = useState("auto");
  const [mapping, setMapping] = useState({
    date: "Date",
    amount: "Amount",
    debit: "",
    credit: "",
    reference: "",
    description: "",
    balance: "",
  });
  const [options, setOptions] = useState({
    date_format: "Y-m-d",
    decimal_separator: "dot",
    thousands_separator: "none",
    row_order: "ascending",
  });
  return (
    <WorkspaceModal
      title={t("Stage a bank statement CSV", "Ngarko pasqyrën bankare CSV")}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!file}
    >
      <form
        className="fc-form"
        onSubmit={async (e) => {
          e.preventDefault();
          const form = new FormData();
          form.append("financial_account_id", account);
          form.append("file", file);
          form.append("delimiter", delimiter);
          form.append("column_mapping", JSON.stringify(mapping));
          Object.entries(options).forEach(([k, v]) =>
            form.append(`format_options[${k}]`, v),
          );
          const result = await run(
            () => api.importBankStatement(form),
            t(
              "Statement staged. Review matches before completing reconciliation.",
              "Pasqyra u ngarkua. Shqyrto përputhjet para përfundimit të rakordimit.",
            ),
          );
          if (result) onSaved(result);
        }}
      >
        <p>
          {t(
            "Import creates staged rows. Map your bank’s CSV header names exactly; dates and number conventions are explicit.",
            "Importi krijon rreshta për shqyrtim. Shkruani saktë titujt e CSV-së bankare; datat dhe formatet numerike janë të përcaktuara.",
          )}
        </p>
        <Field label={t("Bank account", "Llogaria bankare")}>
          <select
            required
            value={account}
            onChange={(e) => setAccount(e.target.value)}
          >
            <option value="">{t("Select bank", "Zgjidh bankën")}</option>
            {accounts
              .filter((a) => a.type === "bank" && a.is_active)
              .map((a) => (
                <option value={a.id} key={a.id}>
                  {a.name} · {a.currency}
                </option>
              ))}
          </select>
        </Field>
        <Field
          label={t(
            "CSV file · up to 10 MB / 10,000 movements",
            "Skedari CSV · deri 10 MB / 10.000 lëvizje",
          )}
          type="file"
          accept=".csv,.txt,text/csv"
          required
          onChange={(e) => setFile(e.target.files[0])}
        />
        <div className="fc-form-grid">
          {Object.entries(mapping).map(([k, v], i) => (
            <Field
              key={k}
              label={t(
                [
                  "Date column",
                  "Signed amount column",
                  "Debit column · if no signed amount",
                  "Credit column · if no signed amount",
                  "Reference column",
                  "Description column",
                  "Running balance column",
                ][i],
                [
                  "Kolona e datës",
                  "Kolona e shumës me shenjë",
                  "Kolona e debitit · pa shumë me shenjë",
                  "Kolona e kreditit · pa shumë me shenjë",
                  "Kolona e referencës",
                  "Kolona e përshkrimit",
                  "Kolona e gjendjes rrjedhëse",
                ][i],
              )}
              required={k === "date"}
              value={v}
              onChange={(e) => setMapping({ ...mapping, [k]: e.target.value })}
            />
          ))}
        </div>
        <div className="fc-form-grid">
          <Field label={t("Delimiter", "Ndarësi")}>
            <select
              value={delimiter}
              onChange={(e) => setDelimiter(e.target.value)}
            >
              {["auto", ",", ";", "tab"].map((x) => (
                <option key={x}>{x}</option>
              ))}
            </select>
          </Field>
          {[
            [
              "date_format",
              ["Y-m-d", "d/m/Y", "m/d/Y", "d.m.Y", "d-m-Y", "Y/m/d"],
              t("Date format", "Formati i datës"),
            ],
            [
              "decimal_separator",
              ["dot", "comma"],
              t("Decimal separator", "Ndarësi dhjetor"),
            ],
            [
              "thousands_separator",
              ["none", "comma", "dot", "space"],
              t("Thousands separator", "Ndarësi i mijësheve"),
            ],
            [
              "row_order",
              ["ascending", "descending"],
              t("Date order", "Rendi i datave"),
            ],
          ].map(([key, values, label]) => (
            <Field key={key} label={label}>
              <select
                value={options[key]}
                onChange={(e) =>
                  setOptions({ ...options, [key]: e.target.value })
                }
              >
                {values.map((x) => (
                  <option key={x}>{x}</option>
                ))}
              </select>
            </Field>
          ))}
        </div>
        <button disabled={busy || !file}>
          {t("Validate and stage statement", "Kontrollo dhe ngarko pasqyrën")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
function StatementBalances({ statement: s, onClose }) {
  const { t, busy, run } = useFinance();
  const [form, setForm] = useState({
    opening_balance: s.opening_balance ?? "",
    closing_balance: s.closing_balance ?? "",
    period_from: dateOnly(s.period_from),
    period_to: dateOnly(s.period_to),
    reason: "",
  });
  const field = (k, v) => setForm({ ...form, [k]: v });
  const evidence = !!s.column_mapping.balance;
  return (
    <WorkspaceModal
      title={t(
        "Statement balances and period",
        "Gjendjet dhe periudha e pasqyrës",
      )}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!form.reason}
    >
      <form
        className="fc-form"
        onSubmit={async (e) => {
          e.preventDefault();
          const result = await run(() =>
            api.setBankStatementBalances(s.id, form),
          );
          if (result) onClose();
        }}
      >
        {["period_from", "period_to", "opening_balance", "closing_balance"].map(
          (key, i) => (
            <Field
              key={key}
              label={
                [
                  t("Period from", "Periudha nga"),
                  t("Period to", "Periudha deri"),
                  t("Statement opening", "Fillimi i pasqyrës"),
                  t("Statement closing", "Fundi i pasqyrës"),
                ][i]
              }
              required
              type={i < 2 ? "date" : "number"}
              step={i < 2 ? undefined : ".01"}
              readOnly={evidence && i >= 2}
              value={form[key]}
              onChange={(e) => field(key, e.target.value)}
            />
          ),
        )}
        {evidence && (
          <p>
            {t(
              "Balances came from the imported bank evidence and cannot be overwritten.",
              "Gjendjet vijnë nga dëshmia e importuar bankare dhe nuk mbishkruhen.",
            )}
          </p>
        )}
        <Field
          label={t(
            "Review reason / bank evidence",
            "Arsyeja e shqyrtimit / dëshmia bankare",
          )}
          required
          minLength={10}
          maxLength={1000}
          value={form.reason}
          onChange={(e) => field("reason", e.target.value)}
        />
        <button disabled={busy}>
          {t("Save reviewed balances", "Ruaj gjendjet e shqyrtuara")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
function CashAccountAction({ action, accounts, onClose }) {
  const { t, today, currency, busy, run } = useFinance();
  const [form, setForm] = useState({
    name: "",
    type: "bank",
    currency: "EUR",
    source_account_id: "",
    destination_account_id: "",
    amount: "",
    transfer_date: today,
    reference_number: "",
    description: "",
    idempotency_key: crypto.randomUUID(),
  });
  const field = (k, v) => setForm({ ...form, [k]: v }),
    source = accounts.find((a) => String(a.id) === form.source_account_id);
  return (
    <WorkspaceModal
      title={
        action === "account"
          ? t("Add cash / bank account", "Shto arkë / llogari bankare")
          : t(
              "Internal cash / bank transfer",
              "Transfer i brendshëm arkë / bankë",
            )
      }
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!form.name || !!form.amount}
    >
      <form
        className="fc-form"
        onSubmit={async (e) => {
          e.preventDefault();
          const result = await run(() =>
            action === "account"
              ? api.createFinancialAccount({
                  name: form.name,
                  type: form.type,
                  currency: form.currency,
                  opening_balance: 0,
                  opening_date: today,
                })
              : api.transferFinancialAccounts(form),
          );
          if (result) onClose();
        }}
      >
        {action === "account" ? (
          <>
            <Field
              label={t("Name", "Emri")}
              required
              value={form.name}
              onChange={(e) => field("name", e.target.value)}
            />
            <Field label={t("Type", "Lloji")}>
              <select
                value={form.type}
                onChange={(e) => field("type", e.target.value)}
              >
                <option value="bank">{t("Bank", "Banka")}</option>
                <option value="cashbox">{t("Cash register", "Arka")}</option>
              </select>
            </Field>
            <Field
              label={t("Currency", "Monedha")}
              required
              pattern="[A-Z]{3}"
              maxLength={3}
              value={form.currency}
              onChange={(e) => field("currency", e.target.value.toUpperCase())}
            />
            <p>
              {t(
                "New accounts start at zero. Verified opening balances use controlled company onboarding.",
                "Llogaritë e reja fillojnë me zero. Gjendjet fillestare të verifikuara regjistrohen përmes konfigurimit të kontrolluar të kompanisë.",
              )}
            </p>
          </>
        ) : (
          <>
            <Field label={t("From account", "Nga llogaria")}>
              <select
                required
                value={form.source_account_id}
                onChange={(e) =>
                  setForm({
                    ...form,
                    source_account_id: e.target.value,
                    destination_account_id: "",
                  })
                }
              >
                <option value="">{t("Select", "Zgjidh")}</option>
                {accounts
                  .filter((a) => a.is_active && a.currency === currency)
                  .map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.name} · {a.currency}
                    </option>
                  ))}
              </select>
            </Field>
            <Field
              label={t(
                "To account · same currency",
                "Te llogaria · e njëjta monedhë",
              )}
            >
              <select
                required
                value={form.destination_account_id}
                onChange={(e) =>
                  field("destination_account_id", e.target.value)
                }
              >
                <option value="">{t("Select", "Zgjidh")}</option>
                {accounts
                  .filter(
                    (a) =>
                      a.is_active &&
                      a.id !== source?.id &&
                      a.currency === source?.currency,
                  )
                  .map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.name}
                    </option>
                  ))}
              </select>
            </Field>
            <Field
              label={t("Amount", "Shuma")}
              type="number"
              required
              min=".01"
              step=".01"
              value={form.amount}
              onChange={(e) => field("amount", e.target.value)}
            />
            <Field
              label={t("Transfer date", "Data e transferit")}
              type="date"
              required
              value={form.transfer_date}
              onChange={(e) => field("transfer_date", e.target.value)}
            />
            <Field
              label={t("Reference", "Referenca")}
              value={form.reference_number}
              onChange={(e) => field("reference_number", e.target.value)}
            />
            <p>
              {t(
                "Transfers post balanced cash/bank entries and do not create revenue or expense.",
                "Transferet postojnë hyrje të balancuara arkë/bankë dhe nuk krijojnë të ardhura ose shpenzime.",
              )}
            </p>
          </>
        )}
        <button disabled={busy}>
          {action === "account"
            ? t("Create zero-balance account", "Krijo llogari me gjendje zero")
            : t("Confirm internal transfer", "Konfirmo transferin e brendshëm")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
