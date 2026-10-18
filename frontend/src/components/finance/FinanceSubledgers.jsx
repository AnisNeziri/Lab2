import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import * as api from "../../api/accounting";
import * as finance from "../../api/finance";
import * as operations from "../../api/advancedOperations";
import WorkspaceModal from "../WorkspaceModal";
import EntityDocuments from "../EntityDocuments";
import FinanceBillForm, { FinancePaymentForm } from "./FinanceBillForm";
import {
  Amount,
  Badge,
  DateValue,
  Empty,
  Field,
  MoneyTable,
  Pager,
  PartySelect,
  QueryState,
  useFinance,
  useFinanceQuery,
} from "./FinanceShared";

export default function FinanceReceivables() {
  const { t, dates, refresh, run, currency } = useFinance();
  const [params] = useSearchParams();
  const [filters, setFilters] = useState({
    customer_id: params.get("customer") || "",
    salesperson_id: "",
    currency,
    page: 1,
  });
  const [customer, setCustomer] = useState(() => params.get("customer") || "");
  const query = useFinanceQuery(
    () => finance.getReceivablesAging({ ...filters, as_of: dates.to }),
    [JSON.stringify(filters), dates.to, refresh],
  );
  return (
    <>
      <section className="fc-panel">
        <div className="fc-section-head">
          <h2>{t("Customer aging", "Vjetërsia e borxheve të klientëve")}</h2>
          <button
            onClick={() =>
              run(
                () =>
                  api.downloadAccountingCsv("ar", { ...filters, to: dates.to }),
                t("AR exported.", "Të arkëtueshmet u eksportuan."),
              )
            }
          >
            {t("Export CSV", "Eksporto CSV")}
          </button>
        </div>
        <div className="fc-filters">
          <PartySelect
            type="customer"
            value={filters.customer_id}
            onChange={(customer_id) =>
              setFilters({ ...filters, customer_id, page: 1 })
            }
          />
          <Field
            label={t("Currency", "Monedha")}
            value={filters.currency}
            maxLength={3}
            onChange={(e) =>
              setFilters({
                ...filters,
                currency: e.target.value.toUpperCase(),
                page: 1,
              })
            }
          />
          <Field
            label={t("Salesperson user ID", "ID e shitësit")}
            type="number"
            min="1"
            value={filters.salesperson_id}
            onChange={(e) =>
              setFilters({
                ...filters,
                salesperson_id: e.target.value,
                page: 1,
              })
            }
          />
          {filters.customer_id && (
            <button onClick={() => setCustomer(filters.customer_id)}>
              {t("Statement & allocations", "Pasqyra dhe alokimet")}
            </button>
          )}
        </div>
        <QueryState query={query}>
          {(d) => (
            <>
              <div className="fc-inline-totals">
                <span>
                  {t("Total AR", "Gjithsej borxhe")}{" "}
                  <Amount
                    value={d.summary.total_outstanding}
                    currency={d.currency}
                  />
                </span>
                <span>
                  {t("Overdue", "Të vonuara")}{" "}
                  <Amount
                    value={d.summary.overdue_total}
                    currency={d.currency}
                  />
                </span>
                <span>
                  90+{" "}
                  <Amount
                    value={d.summary.overdue_90_plus}
                    currency={d.currency}
                  />
                </span>
                <span>
                  {t("Due date unknown", "Afati i panjohur")}{" "}
                  <Amount value={d.summary.unknown_due} currency={d.currency} />
                </span>
              </div>
              <div className="fc-aging-buckets">
                {Object.entries(d.aging_buckets).map(([key, amount], i) => (
                  <div key={key}>
                    <span>
                      {
                        [
                          t("Current", "Aktuale"),
                          "1–30",
                          "31–60",
                          "61–90",
                          "90+",
                          t("Unknown due date", "Pa afat të njohur"),
                        ][i]
                      }
                    </span>
                    <Amount value={amount} currency={d.currency} />
                  </div>
                ))}
              </div>
              {d.receivables.length ? (
                <MoneyTable label={t("Open receivables", "Borxhet e hapura")}>
                  <thead>
                    <tr>
                      <th>{t("Customer", "Klienti")}</th>
                      <th>{t("Invoice / source", "Fatura / burimi")}</th>
                      <th>{t("Date", "Data")}</th>
                      <th>{t("Due", "Afati")}</th>
                      <th>{t("Days overdue", "Ditë vonesë")}</th>
                      <th className="num">{t("Outstanding", "Mbetja")}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {d.receivables.map((r) => (
                      <tr key={`${r.source}-${r.source_id}`}>
                        <td>
                          <button
                            className="fc-link"
                            onClick={() => setCustomer(r.customer_id)}
                          >
                            {r.customer_name || `#${r.customer_id}`}
                          </button>
                        </td>
                        <td>
                          <Link to={r.href}>
                            {r.reference || `#${r.source_id}`}
                          </Link>
                          <small>
                            {r.source === "invoice"
                              ? t("Invoice", "Faturë")
                              : t("Customer ledger", "Libri i klientit")}
                          </small>
                          {Number(r.legacy_snapshot) > 0 && (
                            <Badge
                              value={t("Legacy review", "Shqyrtim historik")}
                            />
                          )}
                        </td>
                        <td>
                          <DateValue value={r.invoice_date} />
                        </td>
                        <td>
                          {r.due_date ? (
                            <DateValue value={r.due_date} />
                          ) : (
                            t("Unknown", "I panjohur")
                          )}
                        </td>
                        <td>{r.days_overdue ?? "—"}</td>
                        <td className="num">
                          <Amount value={r.outstanding} currency={r.currency} />
                        </td>
                        <td>
                          <button
                            className="fc-link"
                            onClick={() => setCustomer(r.customer_id)}
                          >
                            {t("Statement", "Pasqyra")}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
              ) : (
                <Empty />
              )}
              <Pager
                page={d.pagination}
                onPage={(page) => setFilters({ ...filters, page })}
              />
              <details>
                <summary>
                  {t(
                    "Top overdue customers",
                    "Klientët me borxhin më të madh të vonuar",
                  )}
                </summary>
                {d.top_overdue_customers.map((c) => (
                  <div className="fc-total" key={c.customer_id}>
                    <button
                      className="fc-link"
                      onClick={() => setCustomer(c.customer_id)}
                    >
                      {c.customer_name || `#${c.customer_id}`}
                    </button>
                    <Amount value={c.outstanding} currency={d.currency} />
                  </div>
                ))}
              </details>
              <p className="fc-caption">{d.note}</p>
            </>
          )}
        </QueryState>
      </section>
      {customer && (
        <CustomerWorkbench
          key={customer}
          id={customer}
          currency={filters.currency || currency}
          onClose={() => setCustomer("")}
        />
      )}
    </>
  );
}
function CustomerWorkbench({ id, currency, onClose }) {
  const { t, dates, refresh, can, run, today } = useFinance();
  const [view, setView] = useState("statement"),
    [page, setPage] = useState(1),
    [historyPage, setHistoryPage] = useState(1),
    [payment, setPayment] = useState(null),
    [release, setRelease] = useState(null);
  const query = useFinanceQuery(
    () =>
      view === "statement"
        ? api.getCustomerStatement(id, { ...dates, currency, page })
        : api.getCustomerSettlements(id, { page, history_page: historyPage }),
    [id, view, page, historyPage, dates.from, dates.to, refresh],
  );
  return (
    <section className="fc-panel">
      <div className="fc-section-head">
        <h2>
          {t(
            "Customer statement & payment allocation",
            "Pasqyra e klientit dhe alokimi i pagesave",
          )}
        </h2>
        <button onClick={onClose}>
          {t("Close customer", "Mbyll klientin")}
        </button>
      </div>
      <div className="fc-toolbar">
        <button
          aria-pressed={view === "statement"}
          onClick={() => {
            setView("statement");
            setPage(1);
          }}
        >
          {t("Statement", "Pasqyra")}
        </button>
        <button
          aria-pressed={view === "allocations"}
          onClick={() => {
            setView("allocations");
            setPage(1);
          }}
        >
          {t("Payment allocations", "Alokimet e pagesave")}
        </button>
      </div>
      <QueryState query={query}>
        {(d) => (
          <>
            <h3>{d.customer.name}</h3>
            {view === "statement" && (
              <button
                onClick={() =>
                  run(
                    () =>
                      api.downloadAccountingCsv("customer-statement", {
                        ...dates,
                        customer_id: id,
                        currency,
                      }),
                    t(
                      "Customer statement exported.",
                      "Pasqyra e klientit u eksportua.",
                    ),
                  )
                }
              >
                {t("Export statement CSV", "Eksporto pasqyrën CSV")}
              </button>
            )}
            {view === "statement" ? (
              <>
                <div className="fc-inline-totals">
                  <span>
                    {t("Opening receivable", "Borxhi në fillim")}{" "}
                    <Amount value={d.opening.receivable} currency={currency} />
                  </span>
                  <span>
                    {t("Closing receivable", "Borxhi në fund")}{" "}
                    <Amount value={d.closing.receivable} currency={currency} />
                  </span>
                  <span>
                    {t("Opening advance", "Paradhënia në fillim")}{" "}
                    <Amount value={d.opening.advance} currency={currency} />
                  </span>
                  <span>
                    {t("Closing advance", "Paradhënia në fund")}{" "}
                    <Amount value={d.closing.advance} currency={currency} />
                  </span>
                </div>
                <MoneyTable
                  label={t("Customer statement", "Pasqyra e klientit")}
                >
                  <thead>
                    <tr>
                      <th>{t("Date", "Data")}</th>
                      <th>
                        {t("Activity / reference", "Aktiviteti / referenca")}
                      </th>
                      <th className="num">
                        {t("AR change", "Ndryshimi i borxhit")}
                      </th>
                      <th className="num">
                        {t("Advance change", "Ndryshimi i paradhënies")}
                      </th>
                      <th className="num">
                        {t("Running AR", "Borxhi rrjedhës")}
                      </th>
                      <th className="num">
                        {t("Running advance", "Paradhënia rrjedhëse")}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.entries.data.map((r) => (
                      <tr key={`${r.source}-${r.source_id}`}>
                        <td>
                          <DateValue value={r.date} />
                        </td>
                        <td>
                          {r.type.replaceAll("_", " ")} ·{" "}
                          {r.reference || `#${r.source_id}`}
                          <small>{r.description}</small>
                        </td>
                        {[
                          "ar_delta",
                          "advance_delta",
                          "ar_balance",
                          "advance_balance",
                        ].map((k) => (
                          <td key={k} className="num">
                            <Amount value={r[k]} currency={currency} />
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
                <Pager page={d.entries} onPage={setPage} />
                {d.legacy_snapshot_rows > 0 && (
                  <p className="fc-warning">
                    {t(
                      "Legacy position snapshots need accountant review.",
                      "Gjendjet historike kërkojnë shqyrtim nga kontabilisti.",
                    )}
                  </p>
                )}
                <p className="fc-caption">{d.note}</p>
              </>
            ) : (
              <>
                <p className="fc-caption">{d.policy}</p>
                <MoneyTable
                  label={t("Customer payments", "Pagesat e klientit")}
                >
                  <thead>
                    <tr>
                      <th>{t("Payment / date", "Pagesa / data")}</th>
                      <th className="num">{t("Amount", "Shuma")}</th>
                      <th className="num">
                        {t("AR settlement", "Shlyerja e borxhit")}
                      </th>
                      <th className="num">
                        {t("Advance created", "Paradhënia e krijuar")}
                      </th>
                      <th className="num">
                        {t(
                          "Unassigned AR portion",
                          "Pjesa e pacaktuar e borxhit",
                        )}
                      </th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {d.payments.data.map((p) => (
                      <tr key={p.id}>
                        <td>
                          {p.reference_number || `#${p.id}`}
                          <small>
                            <DateValue value={p.transaction_date} />
                          </small>
                          {p.funding_review_required && (
                            <small>
                              {t(
                                "Earlier receipt funding was reclassified to advances. Review correction lineage.",
                                "Financimi i mëparshëm u riklasifikua në paradhënie. Shqyrto historikun e korrigjimit.",
                              )}
                            </small>
                          )}
                        </td>
                        <td className="num">
                          <Amount value={p.amount} />
                        </td>
                        <td className="num">
                          <Amount value={p.receivable_portion} />
                        </td>
                        <td className="num">
                          <Amount value={p.advance_created} />
                        </td>
                        <td className="num">
                          <Amount value={p.remaining_for_review} />
                        </td>
                        <td>
                          {can("accounting.ar.allocate") &&
                            Number(p.remaining_for_review) > 0 && (
                              <button
                                onClick={() =>
                                  setPayment({
                                    payment: p,
                                    customerId: id,
                                  })
                                }
                              >
                                {t("Allocate", "Aloko")}
                              </button>
                            )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
                <Pager page={d.payments} onPage={setPage} />
                <h3>{t("Allocation history", "Historiku i alokimit")}</h3>
                <MoneyTable
                  label={t("Allocation history", "Historiku i alokimit")}
                >
                  <thead>
                    <tr>
                      <th>{t("Payment → debt", "Pagesa → borxhi")}</th>
                      <th>{t("Allocation date", "Data e alokimit")}</th>
                      <th className="num">{t("Amount", "Shuma")}</th>
                      <th>{t("History / reason", "Historiku / arsyeja")}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {d.history.data.map((a) => (
                      <tr key={a.id}>
                        <td>
                          {a.settlement?.reference_number ||
                            `#${a.settlement_transaction_id}`}{" "}
                          →{" "}
                          {a.debt?.reference_number ||
                            `#${a.debt_transaction_id}`}
                        </td>
                        <td>
                          <DateValue value={a.allocated_on} />
                        </td>
                        <td className="num">
                          <Amount value={a.amount} />
                        </td>
                        <td>
                          {a.reason}
                          <small>
                            {a.released_on
                              ? `${t("Released", "Liruar")}: ${a.release_reason}`
                              : t("Active attribution", "Caktim aktiv")}
                          </small>
                        </td>
                        <td>
                          {!a.released_on && can("accounting.ar.allocate") && (
                            <button onClick={() => setRelease(a)}>
                              {t("Release", "Liro")}
                            </button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
                {d.history.last_page > 1 && (
                  <p>
                    {t(
                      "History is paginated separately; open the next history page below.",
                      "Historiku ka faqosje veçmas; hap faqen tjetër më poshtë.",
                    )}
                  </p>
                )}
                <Pager page={d.history} onPage={setHistoryPage} />
                <EntityDocuments entityType="customer" entityId={id} />
              </>
            )}
          </>
        )}
      </QueryState>
      {payment && (
        <CustomerAllocation data={payment} onClose={() => setPayment(null)} />
      )}
      {release && (
        <ReasonAction
          title={t("Release payment attribution", "Liro caktimin e pagesës")}
          impact={t(
            "Original allocation remains in history. Cash, AR and advances do not change.",
            "Alokimi origjinal mbetet në histori. Mjetet, borxhi dhe paradhëniet nuk ndryshojnë.",
          )}
          onClose={() => setRelease(null)}
          onConfirm={({ date, reason, key }) =>
            api.releaseCustomerAllocation(release.id, {
              allocation_date: date,
              reason,
              idempotency_key: key,
            })
          }
        />
      )}
    </section>
  );
}
function CustomerAllocation({ data, onClose }) {
  const { t, today, busy, run } = useFinance();
  const [amounts, setAmounts] = useState({}),
    [reason, setReason] = useState(""),
    [date, setDate] = useState(today),
    [key] = useState(() => crypto.randomUUID());
  const [debtsPage, setDebtsPage] = useState(1);
  const debtsQuery = useFinanceQuery(
    () =>
      api.getCustomerSettlements(data.customerId, { debts_page: debtsPage }),
    [data.customerId, debtsPage],
  );
  const sum =
    Object.values(amounts).reduce(
      (n, a) => n + Math.round(Number(a || 0) * 100),
      0,
    ) / 100;
  const submit = async (e) => {
    e.preventDefault();
    const result = await run(() =>
      api.allocateCustomerPayment(data.payment.id, {
        idempotency_key: key,
        allocation_date: date,
        reason,
        allocations: Object.entries(amounts)
          .filter(([, a]) => Number(a) > 0)
          .map(([id, amount]) => ({ debt_transaction_id: Number(id), amount })),
      }),
    );
    if (result) onClose();
  };
  return (
    <WorkspaceModal
      title={t("Allocate customer payment", "Aloko pagesën e klientit")}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!reason || sum > 0}
    >
      <form className="fc-form" onSubmit={submit}>
        <p>
          {t("AR portion available", "Pjesa e borxhit e disponueshme")}:{" "}
          <Amount value={data.payment.remaining_for_review} />
        </p>
        <p className="fc-caption">
          {t(
            "This assigns an existing payment to receivables. Its advance portion stays separate.",
            "Kjo cakton një pagesë ekzistuese te borxhet. Pjesa e paradhënies mbetet veçmas.",
          )}
        </p>
        <QueryState query={debtsQuery}>
          {(result) => (
            <>
              {result.debts.data.map((d) => (
                <Field
                  key={d.id}
                  label={`${d.reference_number || `#${d.id}`} · ${t("Original AR", "Borxhi origjinal")}: ${d.capacity}`}
                  type="number"
                  min="0"
                  step=".01"
                  max={d.capacity}
                  value={amounts[d.id] || ""}
                  onChange={(e) =>
                    setAmounts({ ...amounts, [d.id]: e.target.value })
                  }
                />
              ))}
              <Pager page={result.debts} onPage={setDebtsPage} />
            </>
          )}
        </QueryState>
        <Field
          label={t("Allocation date", "Data e alokimit")}
          required
          type="date"
          max={today}
          value={date}
          onChange={(e) => setDate(e.target.value)}
        />
        <Field
          label={t(
            "Reason / remittance evidence",
            "Arsyeja / dëshmia e pagesës",
          )}
          required
          minLength={5}
          maxLength={1000}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
        <div className="fc-total">
          <b>{t("Selected allocation", "Alokimi i zgjedhur")}</b>
          <Amount value={sum} />
        </div>
        <button
          disabled={
            busy || sum <= 0 || sum > Number(data.payment.remaining_for_review)
          }
        >
          {t("Confirm allocation", "Konfirmo alokimin")}
        </button>
      </form>
    </WorkspaceModal>
  );
}

export function FinancePayables() {
  const { t, dates, refresh, run, can } = useFinance();
  const [params, setParams] = useSearchParams();
  const [filters, setFilters] = useState({
      supplier_id: params.get("supplier") || "",
      page: 1,
    }),
    [view, setView] = useState("aging"),
    [newBill, setNewBill] = useState(false),
    [supplier, setSupplier] = useState("");
  const query = useFinanceQuery(
    () =>
      view === "aging"
        ? api.getPayableAging({ ...filters, as_of: dates.to })
        : operations.getSupplierInvoices({ ...filters, per_page: 50 }),
    [view, JSON.stringify(filters), dates.to, refresh],
  );
  const selectedId = params.get("supplier_invoice"),
    selected = useFinanceQuery(
      () => (selectedId ? operations.getSupplierInvoice(selectedId) : null),
      [selectedId, refresh],
    );
  return (
    <>
      <section className="fc-panel">
        <div className="fc-section-head">
          <h2>
            {t(
              "Supplier payables & invoices",
              "Detyrimet dhe faturat e furnitorëve",
            )}
          </h2>
          <div className="fc-toolbar">
            {can("supplier_invoices.manage") && (
              <button onClick={() => setNewBill(true)}>
                {t("New supplier bill", "Faturë e re e furnitorit")}
              </button>
            )}
            <button
              onClick={() =>
                run(
                  () =>
                    api.downloadAccountingCsv("ap", {
                      ...filters,
                      to: dates.to,
                    }),
                  t("AP exported.", "Detyrimet u eksportuan."),
                )
              }
            >
              {t("Export AP CSV", "Eksporto detyrimet CSV")}
            </button>
          </div>
        </div>
        <div className="fc-toolbar">
          <button
            aria-pressed={view === "aging"}
            onClick={() => {
              setView("aging");
              setFilters({ ...filters, page: 1 });
            }}
          >
            {t("AP aging", "Vjetërsia e detyrimeve")}
          </button>
          <button
            aria-pressed={view === "bills"}
            onClick={() => {
              setView("bills");
              setFilters({ ...filters, page: 1 });
            }}
          >
            {t("Supplier bill review", "Shqyrtimi i faturave")}
          </button>
          <Link to="/finance">
            {t("Expense register", "Regjistri i shpenzimeve")} ↗
          </Link>
        </div>
        <div className="fc-filters">
          <PartySelect
            type="supplier"
            value={filters.supplier_id}
            onChange={(supplier_id) =>
              setFilters({ ...filters, supplier_id, page: 1 })
            }
          />
          {filters.supplier_id && can("supplier_invoices.view") && (
            <button onClick={() => setSupplier(filters.supplier_id)}>
              {t("Payment workbench", "Hapësira e pagesave")}
            </button>
          )}
        </div>
        <QueryState query={query}>
          {(d) => (
            <>
              {view === "aging" && (
                <div className="fc-aging-buckets">
                  {[
                    ["total", "Total AP", "Detyrimet gjithsej"],
                    ["current", "Current", "Aktuale"],
                    ["days_1_30", "1–30", "1–30"],
                    ["days_31_60", "31–60", "31–60"],
                    ["days_61_90", "61–90", "61–90"],
                    ["days_90_plus", "90+", "90+"],
                    ["unknown_due", "Unknown due", "Afati i panjohur"],
                  ].map(([k, en, sq]) => (
                    <div key={k}>
                      <span>{t(en, sq)}</span>
                      <Amount value={d.summary[k]} />
                    </div>
                  ))}
                </div>
              )}
              <MoneyTable
                label={t("Supplier invoices", "Faturat e furnitorëve")}
              >
                <thead>
                  <tr>
                    <th>{t("Supplier / invoice", "Furnitori / fatura")}</th>
                    <th>{t("Invoice date", "Data e faturës")}</th>
                    <th>{t("Due", "Afati")}</th>
                    <th>{t("Match / status", "Përputhja / gjendja")}</th>
                    <th className="num">
                      {t("Total / outstanding", "Gjithsej / mbetja")}
                    </th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {(view === "aging" ? d.invoices.data : d.data).map((b) => (
                    <tr key={b.id}>
                      <td>
                        {b.vendor_name}
                        <small>{b.document_number}</small>
                      </td>
                      <td>
                        <DateValue value={b.invoice_date} />
                      </td>
                      <td>
                        {b.due_date ? (
                          <DateValue value={b.due_date} />
                        ) : (
                          t("Unknown", "I panjohur")
                        )}
                      </td>
                      <td>
                        <Badge
                          value={
                            view === "aging"
                              ? t("Posted payable", "Detyrim i postuar")
                              : b.match_summary?.match_label || b.match_status
                          }
                        />
                        {view === "bills" && (
                          <small>
                            {b.status} ·{" "}
                            {b.approved_at
                              ? t("Approved", "Aprovuar")
                              : t("Awaiting review", "Në pritje të shqyrtimit")}
                          </small>
                        )}
                      </td>
                      <td className="num">
                        <Amount
                          value={
                            view === "aging" ? b.outstanding : b.gross_amount
                          }
                          currency={b.currency}
                        />
                        {view === "aging" && (
                          <small>
                            <Amount value={b.base_outstanding} />
                          </small>
                        )}
                      </td>
                      <td>
                        {b.supplier_id && can("supplier_invoices.view") && (
                          <button
                            className="fc-link"
                            onClick={() =>
                              setParams({ tab: "ap", supplier_invoice: b.id })
                            }
                          >
                            {t("Review bill", "Shqyrto faturën")}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </MoneyTable>
              <Pager
                page={view === "aging" ? d.invoices : d}
                onPage={(page) => setFilters({ ...filters, page })}
              />
              {d.note && <p className="fc-caption">{d.note}</p>}
            </>
          )}
        </QueryState>
      </section>
      {selectedId && (
        <QueryState query={selected}>
          {(bill) =>
            bill && (
              <SupplierBill
                bill={bill}
                onClose={() => setParams({ tab: "ap" })}
              />
            )
          }
        </QueryState>
      )}
      {supplier && (
        <SupplierWorkbench
          key={supplier}
          id={supplier}
          onClose={() => setSupplier("")}
        />
      )}
      {newBill && (
        <FinanceBillForm
          onClose={() => setNewBill(false)}
          onSaved={(id) => {
            setNewBill(false);
            setParams({ tab: "ap", supplier_invoice: id });
          }}
        />
      )}
    </>
  );
}
function SupplierBill({ bill: b, onClose }) {
  const { t, can, run } = useFinance();
  const [action, setAction] = useState(""),
    [paymentReversal, setPaymentReversal] = useState(null),
    [pay, setPay] = useState(false),
    [edit, setEdit] = useState(false),
    [allocation, setAllocation] = useState(false);
  const matching = b.match_summary || {};
  const advances = (b.purchase_order?.payments || [])
    .filter((p) => p.status === "completed")
    .reduce(
      (s, p) =>
        s +
        Number(p.amount) -
        (p.allocations || []).reduce((n, a) => n + Number(a.amount), 0),
      0,
    );
  return (
    <section className="fc-panel">
      <div className="fc-section-head">
        <div>
          <h2>
            {b.document_number} · {b.vendor_name}
          </h2>
          <p>
            <Badge value={b.status} />{" "}
            <Badge value={matching.match_label || b.match_status} />{" "}
            {b.purchase_order?.po_number}
          </p>
        </div>
        <button onClick={onClose}>{t("Close bill", "Mbyll faturën")}</button>
      </div>
      <div className="fc-inline-totals">
        <span>
          {t("Subtotal", "Nëntotali")}{" "}
          <Amount value={b.net_amount} currency={b.currency} />
        </span>
        <span>
          {t("Tax", "Tatimi")}{" "}
          <Amount value={b.vat_amount} currency={b.currency} />
        </span>
        <span>
          {t("Total", "Gjithsej")}{" "}
          <Amount value={b.gross_amount} currency={b.currency} />
        </span>
        <span>
          {t("Price variance", "Diferenca e çmimit")}{" "}
          <Amount value={matching.price_variance_total} currency={b.currency} />
        </span>
      </div>
      <MoneyTable
        label={t(
          "Three-way invoice matching",
          "Përputhja porosi-pranim-faturë",
        )}
      >
        <thead>
          <tr>
            <th>{t("Item", "Artikulli")}</th>
            <th className="num">{t("Ordered", "Porositur")}</th>
            <th className="num">{t("Received", "Pranuar")}</th>
            <th className="num">{t("Invoiced", "Faturuar")}</th>
            <th className="num">
              {t("Quantity variance", "Diferenca e sasisë")}
            </th>
            <th className="num">{t("Price variance", "Diferenca e çmimit")}</th>
            <th>{t("Review", "Shqyrtimi")}</th>
          </tr>
        </thead>
        <tbody>
          {b.supplier_invoice_items?.map((l) => (
            <tr key={l.id}>
              <td>
                {l.description}
                <small>{l.unit}</small>
              </td>
              {["ordered", "received", "invoiced", "quantityVariance"].map(
                (k) => (
                  <td className="num" key={k}>
                    {l.variance?.[k] ?? "—"}
                  </td>
                ),
              )}
              <td className="num">
                <Amount
                  value={l.variance?.priceVarianceTotal}
                  currency={b.currency}
                />
              </td>
              <td>
                {l.variance?.matchFlags?.map((x) => (
                  <Badge key={x} value={x} />
                ))}
              </td>
            </tr>
          ))}
        </tbody>
      </MoneyTable>
      <div className="fc-toolbar">
        {can("supplier_invoices.manage") &&
          !b.approved_at &&
          b.status === "draft" && (
            <>
              <button onClick={() => setEdit(true)}>
                {t("Edit draft", "Redakto draftin")}
              </button>
              <button
                onClick={() =>
                  run(() => operations.recalculateSupplierInvoice(b.id))
                }
              >
                {t("Refresh matching", "Rifresko përputhjen")}
              </button>
            </>
          )}
        {["draft", "posted"].includes(b.status) &&
          !b.approved_at &&
          can("supplier_invoices.approve") &&
          can("supplier_invoices.post") && (
            <button onClick={() => setAction("approve")}>
              {t("Review approval & posting", "Shqyrto aprovimin dhe postimin")}
            </button>
          )}
        {b.status === "posted" && can("expenses.manage") && (
          <>
            <button
              disabled={Number(b.remaining_amount) <= 0}
              onClick={() => setPay(true)}
            >
              {t(
                "Record partial / full payment",
                "Regjistro pagesë të pjesshme / të plotë",
              )}
            </button>
            <button onClick={() => setAction("reverse")}>
              {t("Reverse bill", "Anulo faturën")}
            </button>
          </>
        )}
        {b.status === "posted" && can("supplier_invoices.manage") && (
          <button
            disabled={Number(b.remaining_amount) <= 0}
            onClick={() => setAllocation(true)}
          >
            {t("Apply supplier advance", "Apliko paradhënien")}
          </button>
        )}
      </div>
      <EntityDocuments entityType="expense" entityId={b.id} />
      <h3>
        {t(
          "Payments and advance allocation history",
          "Historiku i pagesave dhe alokimit të paradhënieve",
        )}
      </h3>
      {b.payments?.map((p) => (
        <div className="fc-total" key={`p${p.id}`}>
          <span>
            {p.reference_number || `#${p.id}`} ·{" "}
            <DateValue value={p.payment_date} /> · <Badge value={p.status} />
          </span>
          <Amount value={p.amount} currency={b.currency} />
          {p.status === "completed" && can("expenses.manage") && (
            <button onClick={() => setPaymentReversal(p)}>
              {t("Reverse payment", "Anulo pagesën")}
            </button>
          )}
        </div>
      ))}
      {b.allocation_history?.map((a) => (
        <div className="fc-total" key={`a${a.id}`}>
          <span>
            {t("Advance", "Paradhënia")} #{a.purchase_order_payment_id} →{" "}
            {b.document_number}
            <small>
              {a.reason} · <Badge value={a.status} />
            </small>
          </span>
          <Amount value={a.amount} currency={b.currency} />
          {a.status === "completed" &&
            can("supplier_invoices.manage") &&
            can("purchase_orders.payments") && (
              <button onClick={() => setAction(a)}>
                {t("Release allocation", "Liro alokimin")}
              </button>
            )}
        </div>
      ))}
      {action && (
        <ReasonAction
          title={
            action === "approve"
              ? t(
                  "Approve and post supplier invoice",
                  "Aprovo dhe posto faturën e furnitorit",
                )
              : action === "reverse"
                ? t("Reverse supplier bill", "Anulo faturën e furnitorit")
                : t(
                    "Release supplier advance allocation",
                    "Liro alokimin e paradhënies",
                  )
          }
          impact={
            action === "approve"
              ? `${t("This posts the reviewed bill and applies available advances from its Purchase Order.", "Kjo poston faturën e shqyrtuar dhe aplikon paradhëniet e porosisë.")} ${t("Available PO advances", "Paradhëniet e disponueshme të porosisë")}: ${advances.toFixed(2)} ${b.currency}. ${t("Expected application, capped by the payable", "Aplikimi i pritshëm, deri në detyrimin e mbetur")}: ${Math.max(0, Math.min(advances, Number(b.remaining_amount ?? b.outstanding_amount ?? b.gross_amount))).toFixed(2)} ${b.currency}.`
              : t(
                  "A linked reversal preserves the original. Existing settlements must be released or reversed first. The source workflow validates the period and downstream effects.",
                  "Një anulim i lidhur ruan origjinalin. Pagesat duhet të lirohen ose anulohen fillimisht. Burimi kontrollon periudhën dhe pasojat.",
                )
          }
          showDate={typeof action === "object"}
          onClose={() => setAction("")}
          onConfirm={({ reason, date }) =>
            action === "approve"
              ? operations.approveSupplierInvoice(b.id, reason)
              : action === "reverse"
                ? finance.reverseExpense(b.id, reason)
                : operations.reverseSupplierAllocation(action.id, {
                    reversal_date: date,
                    reason,
                  })
          }
        />
      )}
      {paymentReversal && (
        <ReasonAction
          title={t("Reverse supplier payment", "Anulo pagesën e furnitorit")}
          impact={t(
            "This posts a linked reversal on the current company business date and restores the payable. Bank matches must be released first. The original receipt remains in history.",
            "Kjo poston anulim të lidhur në datën aktuale të biznesit dhe rikthen detyrimin. Përputhjet bankare duhet të lirohen fillimisht. Pagesa origjinale mbetet në histori.",
          )}
          showDate={false}
          onClose={() => setPaymentReversal(null)}
          onConfirm={({ reason }) =>
            finance.reverseExpensePayment(paymentReversal.id, reason)
          }
        />
      )}
      {pay && <FinancePaymentForm bill={b} onClose={() => setPay(false)} />}
      {edit && (
        <FinanceBillForm
          original={b}
          onClose={() => setEdit(false)}
          onSaved={() => setEdit(false)}
        />
      )}
      {allocation && (
        <SupplierAllocation bill={b} onClose={() => setAllocation(false)} />
      )}
    </section>
  );
}
function SupplierWorkbench({ id, onClose }) {
  const { t, refresh } = useFinance(),
    [page, setPage] = useState(1),
    [advancesPage, setAdvancesPage] = useState(1),
    [historyPage, setHistoryPage] = useState(1),
    [statement, setStatement] = useState(false);
  const query = useFinanceQuery(
    () =>
      api.getSupplierWorkbench(id, {
        page,
        advances_page: advancesPage,
        history_page: historyPage,
      }),
    [id, page, advancesPage, historyPage, refresh],
  );
  if (statement)
    return <SupplierStatement id={id} onClose={() => setStatement(false)} />;
  return (
    <section className="fc-panel">
      <button onClick={() => setStatement(true)}>
        {t("Supplier statement", "Pasqyra e furnitorit")}
      </button>
      <div className="fc-section-head">
        <h2>
          {t("Supplier payment workbench", "Hapësira e pagesave të furnitorit")}
        </h2>
        <button onClick={onClose}>
          {t("Close supplier", "Mbyll furnitorin")}
        </button>
      </div>
      <QueryState query={query}>
        {(d) => (
          <>
            <h3>{d.supplier.name}</h3>
            <div className="fc-total">
              <b>
                {t(
                  "Total payable at carrying value",
                  "Detyrimi gjithsej në vlerën kontabël",
                )}
              </b>
              <Amount value={d.summary.total} />
            </div>
            {d.invoices.data.map((b) => (
              <div className="fc-total" key={b.id}>
                <Link to={`/accounting?tab=ap&supplier_invoice=${b.id}`}>
                  {b.document_number} · {t("Due", "Afati")}{" "}
                  <DateValue value={b.due_date} />
                </Link>
                <Amount value={b.outstanding} currency={b.currency} />
              </div>
            ))}
            <Pager page={d.invoices} onPage={setPage} />
            <h3>{t("Supplier advances", "Paradhëniet e furnitorit")}</h3>
            <MoneyTable
              label={t("Supplier advances", "Paradhëniet e furnitorit")}
            >
              <thead>
                <tr>
                  <th>{t("Payment / PO", "Pagesa / porosia")}</th>
                  <th>{t("Date", "Data")}</th>
                  <th className="num">{t("Amount", "Shuma")}</th>
                  <th className="num">{t("Allocated", "Alokuar")}</th>
                  <th className="num">{t("Remaining", "Mbetja")}</th>
                </tr>
              </thead>
              <tbody>
                {d.advances.data.map((a) => (
                  <tr key={a.id}>
                    <td>
                      #{a.id} ·{" "}
                      {a.po_number ||
                        t("Opening advance", "Paradhënie fillestare")}
                    </td>
                    <td>
                      <DateValue value={a.date} />
                    </td>
                    <td className="num">
                      <Amount value={a.amount} currency={a.currency} />
                    </td>
                    <td className="num">
                      <Amount value={a.allocated} currency={a.currency} />
                    </td>
                    <td className="num">
                      <Amount value={a.remaining} currency={a.currency} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </MoneyTable>
            <Pager page={d.advances} onPage={setAdvancesPage} />
            <h3>
              {t(
                "Advance allocation history",
                "Historiku i alokimit të paradhënieve",
              )}
            </h3>
            <MoneyTable
              label={t(
                "Advance allocation history",
                "Historiku i alokimit të paradhënieve",
              )}
            >
              <thead>
                <tr>
                  <th>{t("Bill", "Fatura")}</th>
                  <th>{t("Date", "Data")}</th>
                  <th>{t("Status", "Statusi")}</th>
                  <th className="num">{t("Amount", "Shuma")}</th>
                </tr>
              </thead>
              <tbody>
                {d.allocation_history.data.map((a) => (
                  <tr key={a.id}>
                    <td>
                      <Link
                        to={`/accounting?tab=ap&supplier_invoice=${a.expense_id}`}
                      >
                        {a.document_number}
                      </Link>
                      <small>
                        #{a.purchase_order_payment_id} · {a.reason}
                      </small>
                    </td>
                    <td>
                      <DateValue value={a.allocation_date || a.created_at} />
                    </td>
                    <td>
                      <Badge value={a.status} />
                      {a.reversal_reason && <small>{a.reversal_reason}</small>}
                    </td>
                    <td className="num">
                      <Amount value={a.amount} currency={a.currency} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </MoneyTable>
            <Pager page={d.allocation_history} onPage={setHistoryPage} />
            <EntityDocuments entityType="supplier" entityId={id} />
          </>
        )}
      </QueryState>
    </section>
  );
}
function SupplierAllocation({ bill, onClose }) {
  const { t, refresh, busy, run } = useFinance();
  const [advancesPage, setAdvancesPage] = useState(1);
  const [payment, setPayment] = useState(""),
    [amount, setAmount] = useState(""),
    [reason, setReason] = useState(""),
    [key] = useState(() => crypto.randomUUID());
  const query = useFinanceQuery(
    () =>
      api.getSupplierWorkbench(bill.supplier_id, {
        advances_page: advancesPage,
      }),
    [bill.supplier_id, advancesPage, refresh],
  );
  return (
    <WorkspaceModal
      title={t(
        "Apply existing supplier advance",
        "Apliko paradhënien ekzistuese",
      )}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!amount || !!reason}
    >
      <QueryState query={query}>
        {(d) => (
          <form
            className="fc-form"
            onSubmit={async (e) => {
              e.preventDefault();
              const result = await run(() =>
                operations.allocateSupplierInvoicePayment(bill.id, {
                  purchase_order_payment_id: Number(payment),
                  amount,
                  reason,
                  idempotency_key: key,
                }),
              );
              if (result) onClose();
            }}
          >
            <p>
              {bill.document_number} ·{" "}
              <Amount
                value={
                  bill.remaining_amount ??
                  bill.outstanding_amount ??
                  bill.gross_amount
                }
                currency={bill.currency}
              />
            </p>
            <Field
              label={t(
                "Available advance payment",
                "Pagesa e paradhënies së disponueshme",
              )}
            >
              <select
                required
                value={payment}
                onChange={(e) => setPayment(e.target.value)}
              >
                <option value="">
                  {t("Select advance", "Zgjidh paradhënien")}
                </option>
                {d.advances.data
                  .filter(
                    (a) =>
                      a.currency === bill.currency && Number(a.remaining) > 0,
                  )
                  .map((a) => (
                    <option key={a.id} value={a.id}>
                      #{a.id} · {a.po_number || "Opening"} · {a.remaining}{" "}
                      {a.currency}
                    </option>
                  ))}
              </select>
            </Field>
            <Pager
              page={d.advances}
              onPage={(next) => {
                setPayment("");
                setAdvancesPage(next);
              }}
            />
            <Field
              label={t("Amount", "Shuma")}
              type="number"
              required
              min=".01"
              step=".01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
            />
            <Field
              label={t("Reason", "Arsyeja")}
              required
              minLength={10}
              maxLength={1000}
              value={reason}
              onChange={(e) => setReason(e.target.value)}
            />
            <p className="fc-caption">
              {t(
                "Reclassifies the existing advance to AP settlement. No new cash or expense is created.",
                "Riklasifikon paradhënien ekzistuese si shlyerje të detyrimit. Nuk krijon mjete ose shpenzim të ri.",
              )}
            </p>
            <button disabled={busy}>
              {t(
                "Confirm advance application",
                "Konfirmo aplikimin e paradhënies",
              )}
            </button>
          </form>
        )}
      </QueryState>
    </WorkspaceModal>
  );
}
function SupplierStatement({ id, onClose }) {
  const { t, dates, refresh, run } = useFinance(),
    [page, setPage] = useState(1);
  const query = useFinanceQuery(
    () => api.getSupplierStatement(id, { ...dates, page }),
    [id, dates.from, dates.to, page, refresh],
  );
  return (
    <section className="fc-panel">
      <div className="fc-section-head">
        <h2>{t("Supplier statement", "Pasqyra e furnitorit")}</h2>
        <div className="fc-toolbar">
          <button
            onClick={() =>
              run(
                () =>
                  api.downloadAccountingCsv("supplier-statement", {
                    ...dates,
                    supplier_id: id,
                  }),
                t(
                  "Supplier statement exported.",
                  "Pasqyra e furnitorit u eksportua.",
                ),
              )
            }
          >
            {t("Export CSV", "Eksporto CSV")}
          </button>
          <button onClick={onClose}>
            {t("Back to payment workbench", "Kthehu te pagesat")}
          </button>
        </div>
      </div>
      <QueryState query={query}>
        {(d) => (
          <>
            <h3>{d.supplier.name}</h3>
            <div className="fc-inline-totals">
              <span>
                {t("Opening payable", "Detyrimi në fillim")}{" "}
                <Amount value={d.opening.payable} />
              </span>
              <span>
                {t("Closing payable", "Detyrimi në fund")}{" "}
                <Amount value={d.closing.payable} />
              </span>
              <span>
                {t("Opening advance", "Paradhënia në fillim")}{" "}
                <Amount value={d.opening.advance} />
              </span>
              <span>
                {t("Closing advance", "Paradhënia në fund")}{" "}
                <Amount value={d.closing.advance} />
              </span>
            </div>
            <MoneyTable label={t("Supplier statement", "Pasqyra e furnitorit")}>
              <thead>
                <tr>
                  <th>{t("Date", "Data")}</th>
                  <th>{t("Source / reference", "Burimi / referenca")}</th>
                  <th className="num">
                    {t("Source amount", "Shuma burimore")}
                  </th>
                  <th className="num">
                    {t("AP change", "Ndryshimi i detyrimit")}
                  </th>
                  <th className="num">
                    {t("Advance change", "Ndryshimi i paradhënies")}
                  </th>
                  <th className="num">
                    {t("Running AP", "Detyrimi rrjedhës")}
                  </th>
                  <th className="num">
                    {t("Running advance", "Paradhënia rrjedhëse")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {d.entries.data.map((r) => (
                  <tr key={`${r.source}-${r.source_id}`}>
                    <td>
                      <DateValue value={r.date} />
                    </td>
                    <td>
                      {r.source.replaceAll("_", " ")} ·{" "}
                      {r.reference || `#${r.source_id}`}
                      <small>{r.description}</small>
                    </td>
                    <td className="num">
                      <Amount
                        value={r.transaction_amount}
                        currency={r.currency}
                      />
                    </td>
                    {[
                      "ap_delta",
                      "advance_delta",
                      "payable_balance",
                      "advance_balance",
                    ].map((k) => (
                      <td key={k} className="num">
                        <Amount value={r[k]} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </MoneyTable>
            <Pager page={d.entries} onPage={setPage} />
            <p className="fc-caption">{d.note}</p>
          </>
        )}
      </QueryState>
    </section>
  );
}
export function ReasonAction({
  title,
  impact,
  onClose,
  onConfirm,
  showDate = true,
}) {
  const { t, busy, run, today } = useFinance();
  const [reason, setReason] = useState(""),
    [date, setDate] = useState(today),
    [key] = useState(() => crypto.randomUUID());
  return (
    <WorkspaceModal
      title={title}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!reason}
    >
      <form
        className="fc-form"
        onSubmit={async (e) => {
          e.preventDefault();
          const result = await run(() => onConfirm({ reason, date, key }));
          if (result) onClose();
        }}
      >
        <p>{impact}</p>
        {showDate && (
          <Field
            label={t("Effective date", "Data efektive")}
            type="date"
            required
            max={today}
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
        )}
        <Field label={t("Reason", "Arsyeja")}>
          <textarea
            required
            minLength={10}
            maxLength={1000}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </Field>
        <button disabled={busy}>
          {t("Confirm reviewed action", "Konfirmo veprimin e shqyrtuar")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
export function SourceJournals({ type, id }) {
  const { t, can, refresh } = useFinance();
  const query = useFinanceQuery(
    () =>
      can("accounting.journal.view") ? api.getSourceJournals(type, id) : [],
    [type, id, refresh],
  );
  if (!can("accounting.journal.view")) return null;
  return (
    <div className="fc-source-journals">
      <h3>{t("Linked journals", "Ditarët e lidhur")}</h3>
      <QueryState query={query}>
        {(rows) =>
          rows.length ? (
            rows.map((j) => (
              <p key={j.id}>
                <Link to={j.url}>{j.journal_number}</Link> ·{" "}
                <DateValue value={j.posting_date} /> · {j.source_label} ·{" "}
                <Badge value={j.status} /> · <Amount value={j.total_debit} />
              </p>
            ))
          ) : (
            <Empty />
          )
        }
      </QueryState>
    </div>
  );
}
