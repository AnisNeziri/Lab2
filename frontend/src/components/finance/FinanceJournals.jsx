import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import WorkspaceModal from "../WorkspaceModal";
import EntityDocuments from "../EntityDocuments";
import * as api from "../../api/accounting";
import {
  Amount,
  Badge,
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

export default function FinanceJournals() {
  const f = useFinance(),
    { t, dates, refresh, accounts, periods, can, busy, run } = f;
  const [params, setParams] = useSearchParams();
  const [filters, setFilters] = useState({
    status: "",
    reference: params.get("reference")||"",
    account_id: "",
    source_module: "",
    period_id: "",
    user_id: "",
    page: 1,
  });
  const [draft, setDraft] = useState(null);
  const list = useFinanceQuery(
    () => api.getJournals({ ...dates, ...filters }),
    [dates.from, dates.to, JSON.stringify(filters), refresh],
  );
  const selectedId = params.get("journal");
  const selected = useFinanceQuery(
    () => (selectedId ? api.getJournal(selectedId) : null),
    [selectedId, refresh],
  );
  const filter = (key, value) =>
    setFilters({ ...filters, [key]: value, page: 1 });
  const open = (id) => setParams({ tab: "journal", journal: id });
  return (
    <>
      <section className="fc-panel">
        <div className="fc-section-head">
          <h2>{t("General Ledger journals", "Ditarët e librit kryesor")}</h2>
          {can("accounting.journal.create") && (
            <button
              disabled={busy || !accounts.length}
              onClick={() => setDraft({})}
            >
              {t("New manual draft", "Draft i ri manual")}
            </button>
          )}
        </div>
        <div className="fc-filters">
          <Field
            label={t(
              "Reference / journal / description",
              "Referenca / ditari / përshkrimi",
            )}
            value={filters.reference}
            maxLength={100}
            onChange={(e) => filter("reference", e.target.value)}
          />
          <Field label={t("Account", "Llogaria")}>
            <select
              value={filters.account_id}
              onChange={(e) => filter("account_id", e.target.value)}
            >
              <option value="">
                {t("All accounts", "Të gjitha llogaritë")}
              </option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.code} · {a.name}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t("Status", "Gjendja")}>
            <select
              value={filters.status}
              onChange={(e) => filter("status", e.target.value)}
            >
              <option value="">{t("All states", "Të gjitha gjendjet")}</option>
              {["draft", "posted", "reversed"].map((x) => (
                <option key={x} value={x}>
                  {t(
                    { draft: "Draft", posted: "Posted", reversed: "Reversed" }[
                      x
                    ],
                    { draft: "Draft", posted: "Postuar", reversed: "Anuluar" }[
                      x
                    ],
                  )}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t("Source module", "Moduli burimor")}>
            <select
              value={filters.source_module}
              onChange={(e) => filter("source_module", e.target.value)}
            >
              <option value="">{t("All sources", "Të gjitha burimet")}</option>
              {[
                ["manual", "Manual journal"],
                ["sales", "Customer sales"],
                ["receivables", "Receivables"],
                ["supplier_accounting", "Supplier bills"],
                ["procurement", "Procurement"],
                ["inventory", "Inventory"],
                ["landed_cost", "Landed cost"],
                ["banking", "Cash / bank"],
                ["opening", "Opening balances"],
                ["accounting", "Reversals"],
              ].map(([v, label]) => (
                <option value={v} key={v}>
                  {label}
                </option>
              ))}
            </select>
          </Field>
          <Field label={t("Period", "Periudha")}>
            <select
              value={filters.period_id}
              onChange={(e) => filter("period_id", e.target.value)}
            >
              <option value="">
                {t("Selected date range", "Intervali i zgjedhur")}
              </option>
              {periods.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name}
                </option>
              ))}
            </select>
          </Field>
          <Field
            label={t(
              "Created / posted by user ID",
              "ID e krijuesit / postuesit",
            )}
            type="number"
            min="1"
            value={filters.user_id}
            onChange={(e) => filter("user_id", e.target.value)}
          />
        </div>
        <QueryState query={list}>
          {(page) => (
            <>
              {page.data.length ? (
                <MoneyTable label={t("Journal list", "Lista e ditarëve")}>
                  <thead>
                    <tr>
                      {[
                        t("Journal number", "Numri i ditarit"),
                        t("Posting date / period", "Data / periudha"),
                        t("Source / reference", "Burimi / referenca"),
                        t("Description", "Përshkrimi"),
                        t("Debit", "Debi"),
                        t("Credit", "Kredi"),
                        t("Status", "Gjendja"),
                        t("Posted by", "Postuar nga"),
                        "",
                      ].map((x, i) => (
                        <th key={i} className={i === 4 || i === 5 ? "num" : ""}>
                          {x}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {page.data.map((j) => (
                      <tr key={j.id}>
                        <td>
                          <button
                            className="fc-link"
                            onClick={() => open(j.id)}
                          >
                            {j.journal_number}
                          </button>
                        </td>
                        <td>
                          <DateValue value={j.posting_date} />
                          <small>{j.period?.name || "—"}</small>
                        </td>
                        <td>
                          {j.source_label}
                          <small>{j.reference_number || "—"}</small>
                        </td>
                        <td>{j.description}</td>
                        <td className="num">
                          <Amount value={j.total_debit} plain />
                        </td>
                        <td className="num">
                          <Amount value={j.total_credit} plain />
                        </td>
                        <td>
                          <Badge value={j.status} />
                        </td>
                        <td>{j.poster?.name || "—"}</td>
                        <td>
                          <button
                            className="fc-link"
                            onClick={() => open(j.id)}
                          >
                            {t("Review", "Shqyrto")}
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
                page={page}
                onPage={(page) => setFilters({ ...filters, page })}
              />
            </>
          )}
        </QueryState>
      </section>
      {selectedId && (
        <QueryState query={selected}>
          {(j) =>
            j && (
              <JournalDetail
                journal={j}
                onClose={() => setParams({ tab: "journal" })}
                onEdit={() => setDraft(j)}
              />
            )
          }
        </QueryState>
      )}
      {draft && (
        <ManualDraft
          original={draft.id ? draft : null}
          onClose={() => setDraft(null)}
          onSaved={(id) => {
            setDraft(null);
            open(id);
          }}
        />
      )}
    </>
  );
}

function JournalDetail({ journal: j, onClose, onEdit }) {
  const { t, can, busy, run, today } = useFinance();
  const [action, setAction] = useState(""),
    [reason, setReason] = useState(""),
    [date, setDate] = useState(today),
    [ack, setAck] = useState(false),
    [soft, setSoft] = useState(false);
  const controls = j.controlled_accounts || [];
  const manual = !j.source_owns_correction;
  const act = async (e) => {
    e.preventDefault();
    const result = await run(() =>
      action === "post"
        ? api.postJournal(j.id, {
            revision: j.revision,
            acknowledge_controls: ack,
            control_reason: reason,
            soft_close_adjustment: soft,
          })
        : action === "correct"
          ? api.correctJournal(j.id, { posting_date: date, reason })
          : api.reverseJournal(j.id, { posting_date: date, reason }),
    );
    if (result) {
      setAction("");
      setReason("");
      setAck(false);
    }
  };
  return (
    <section className="fc-panel fc-journal-detail">
      <div className="fc-section-head">
        <div>
          <h2>{j.journal_number}</h2>
          <p>{j.description}</p>
        </div>
        <button onClick={onClose}>
          {t("Close detail", "Mbyll hollësitë")}
        </button>
      </div>
      <div className="fc-facts">
        <span>
          {t("Source", "Burimi")}
          <b>{j.source_label}</b>
        </span>
        <span>
          {t("Posting date", "Data e postimit")}
          <b>
            <DateValue value={j.posting_date} />
          </b>
        </span>
        <span>
          {t("Period", "Periudha")}
          <b>{j.period?.name || "—"}</b>
        </span>
        <span>
          {t("Reference", "Referenca")}
          <b>{j.reference_number || "—"}</b>
        </span>
        <span>
          {t("Status", "Gjendja")}
          <Badge value={j.status} />
        </span>
        <span>
          {t("Posted by", "Postuar nga")}
          <b>{j.poster?.name || "—"}</b>
        </span>
      </div>
      {j.source && (
        <Link to={j.source.url}>
          {t("Open source transaction", "Hap transaksionin burimor")}:{" "}
          {j.source.label} #{j.source.id} ↗
        </Link>
      )}
      <MoneyTable label={t("Journal lines", "Rreshtat e ditarit")}>
        <thead>
          <tr>
            <th>{t("Account code / name", "Kodi / emri i llogarisë")}</th>
            <th>{t("Description / context", "Përshkrimi / konteksti")}</th>
            <th className="num">{t("Debit", "Debi")}</th>
            <th className="num">{t("Credit", "Kredi")}</th>
            <th>{t("Transaction currency", "Monedha e transaksionit")}</th>
          </tr>
        </thead>
        <tbody>
          {j.lines.map((l) => (
            <tr key={l.id}>
              <td>
                {l.account?.code} · {l.account?.name}
              </td>
              <td>
                {l.description || "—"}
                {l.dimensions &&
                  Object.entries(l.dimensions).map(([k, v]) => (
                    <small key={k}>
                      {k.replaceAll("_", " ")}: {v}
                    </small>
                  ))}
              </td>
              <td className="num">
                <Amount value={l.debit} plain />
              </td>
              <td className="num">
                <Amount value={l.credit} plain />
              </td>
              <td>
                {j.currency}
                {Number(l.foreign_debit) > 0 && (
                  <small>
                    {t("Debit", "Debi")}{" "}
                    <Amount value={l.foreign_debit} currency={j.currency} />
                  </small>
                )}
                {Number(l.foreign_credit) > 0 && (
                  <small>
                    {t("Credit", "Kredi")}{" "}
                    <Amount value={l.foreign_credit} currency={j.currency} />
                  </small>
                )}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr>
            <th colSpan={2}>{t("Total", "Gjithsej")}</th>
            <td className="num">
              <Amount value={j.totals.debit} />
            </td>
            <td className="num">
              <Amount value={j.totals.credit} />
            </td>
            <td>
              {t("Difference", "Diferenca")}:{" "}
              <Amount value={j.totals.difference} />
            </td>
          </tr>
        </tfoot>
      </MoneyTable>
      {j.exchange_rate_date && (
        <p>
          {t("Historical rate", "Kursi historik")}: {j.exchange_rate} ·{" "}
          {dateOnly(j.exchange_rate_date)} · {j.exchange_rate_source}
        </p>
      )}
      {[
        ["reversal_of", j.reversal_of],
        ["reversal", j.reversal],
      ]
        .filter(([, v]) => v)
        .map(([key, value]) => (
          <p key={key}>
            {key === "reversal_of"
              ? t("Reverses", "Anulon")
              : t("Reversed by", "Anuluar nga")}{" "}
            <Link to={`/accounting?tab=journal&journal=${value.id}`}>
              {value.journal_number}
            </Link>
          </p>
        ))}
      {j.corrections?.map((c) => (
        <p key={c.id}>
          {t("Corrected draft", "Drafti i korrigjuar")}:{" "}
          <Link to={`/accounting?tab=journal&journal=${c.id}`}>
            {c.journal_number}
          </Link>{" "}
          <Badge value={c.status} />
        </p>
      ))}
      <div className="fc-toolbar">
        {manual && j.status === "draft" && can("accounting.journal.create") && (
          <button disabled={busy} onClick={onEdit}>
            {t("Edit draft", "Redakto draftin")}
          </button>
        )}
        {manual && j.status === "draft" && can("accounting.journal.post") && (
          <button onClick={() => setAction("post")}>
            {t("Review posting", "Shqyrto postimin")}
          </button>
        )}
        {manual &&
          j.status === "posted" &&
          can("accounting.journal.reverse") && (
            <>
              <button onClick={() => setAction("reverse")}>
                {t("Reverse", "Anulo")}
              </button>
              {can("accounting.journal.create") && (
                <button onClick={() => setAction("correct")}>
                  {t(
                    "Reverse & prepare correction",
                    "Anulo dhe përgatit korrigjimin",
                  )}
                </button>
              )}
            </>
          )}
      </div>
      {!manual && (
        <p className="fc-caption">
          {t(
            "Corrections belong to the source workflow so settlements and inventory remain consistent.",
            "Korrigjimet bëhen në burim që pagesat dhe inventari të mbeten të sakta.",
          )}
        </p>
      )}
      <details>
        <summary>
          {t("Audit history", "Historiku i auditimit")} ({j.audit.length})
        </summary>
        {j.audit.map((a) => (
          <div className="fc-audit" key={a.id}>
            <b>{a.description || a.action.replaceAll(".", " ")}</b>
            <span>
              {a.user?.name} · <DateValue value={a.created_at} time />
            </span>
            {a.new_value?.reason && <p>{a.new_value.reason}</p>}
          </div>
        ))}
      </details>
      <EntityDocuments entityType="journal" entityId={j.id} />
      {action && (
        <WorkspaceModal
          title={
            action === "post"
              ? t(
                  "Review and post manual journal",
                  "Shqyrto dhe posto ditarin manual",
                )
              : t("Review journal correction", "Shqyrto korrigjimin e ditarit")
          }
          onClose={() => setAction("")}
          busy={busy}
          warnOnUnsaved={!!reason}
        >
          <form className="fc-form" onSubmit={act}>
            <p>
              {j.journal_number} · {j.description}
            </p>
            <p>
              {t("Total debit and credit", "Gjithsej debi dhe kredi")}:{" "}
              <Amount value={j.totals.debit} />
            </p>
            {action !== "post" && (
              <>
                <Field
                  label={t("Reversal date", "Data e anulimit")}
                  type="date"
                  required
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                />
                <p>
                  {t(
                    "Original entries remain. A linked journal reverses each debit and credit in the selected open period.",
                    "Hyrjet origjinale mbeten. Një ditar i lidhur anulon çdo debi dhe kredi në periudhën e zgjedhur të hapur.",
                  )}
                </p>
              </>
            )}
            {controls.length > 0 && (
              <div className="fc-warning">
                <b>
                  {t(
                    "System-controlled accounts",
                    "Llogari të kontrolluara nga sistemi",
                  )}
                </b>
                <p>{controls.map((a) => `${a.code} · ${a.name}`).join(", ")}</p>
                <p>
                  {t(
                    "Prefer correcting the operational document. A direct entry can create a subledger difference.",
                    "Preferoni korrigjimin e dokumentit burimor. Një hyrje e drejtpërdrejtë mund të krijojë diferencë me nënlibrin.",
                  )}
                </p>
                {action === "post" && can("accounting.journal.controlled") && (
                  <label className="fc-check">
                    <input
                      type="checkbox"
                      checked={ack}
                      onChange={(e) => setAck(e.target.checked)}
                    />
                    {t(
                      "I reviewed the control-account impact",
                      "Shqyrtova ndikimin në llogaritë e kontrollit",
                    )}
                  </label>
                )}
              </div>
            )}
            {action === "post" && can("accounting.periods.adjust") && (
              <label className="fc-check">
                <input
                  type="checkbox"
                  checked={soft}
                  onChange={(e) => setSoft(e.target.checked)}
                />
                {t(
                  "Authorized adjustment in a soft-closed period",
                  "Korrigjim i autorizuar në periudhë të mbyllur paraprakisht",
                )}
              </label>
            )}
            <Field label={t("Reason", "Arsyeja")}>
              <textarea
                required={action !== "post" || controls.length > 0 || soft}
                minLength={10}
                maxLength={1000}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
            </Field>
            <button
              disabled={
                busy ||
                (action === "post" &&
                  controls.length > 0 &&
                  (!can("accounting.journal.controlled") || !ack))
              }
            >
              {action === "post"
                ? t("Post balanced journal", "Posto ditarin e balancuar")
                : action === "correct"
                  ? t(
                      "Reverse and create an unposted correction",
                      "Anulo dhe krijo korrigjimin e papostuar",
                    )
                  : t("Create linked reversal", "Krijo anulimin e lidhur")}
            </button>
          </form>
        </WorkspaceModal>
      )}
    </section>
  );
}

function ManualDraft({ original, onClose, onSaved }) {
  const { t, accounts, today, currency, busy, run } = useFinance();
  const [form, setForm] = useState(() =>
    original
      ? {
          ...original,
          posting_date: dateOnly(original.posting_date),
          lines: original.lines.map((l) => ({ ...l })),
        }
      : {
          posting_date: today,
          currency,
          exchange_rate: 1,
          exchange_rate_date: today,
          exchange_rate_source: "",
          description: "",
          reference_number: "",
          lines: [
            { accounting_account_id: "", debit: "", credit: "" },
            { accounting_account_id: "", debit: "", credit: "" },
          ],
        },
  );
  const [dirty, setDirty] = useState(false);
  const change = (key, value) => {
    setDirty(true);
    setForm({ ...form, [key]: value });
  };
  const line = (i, key, value) =>
    change(
      "lines",
      form.lines.map((l, n) => (n === i ? { ...l, [key]: value } : l)),
    );
  const totals = form.lines.reduce(
    (s, l) => ({
      debit: s.debit + Math.round(Number(l.debit || 0) * 100),
      credit: s.credit + Math.round(Number(l.credit || 0) * 100),
    }),
    { debit: 0, credit: 0 },
  );
  const submit = async (e) => {
    e.preventDefault();
    const payload = Object.fromEntries(
      [
        "posting_date",
        "description",
        "reference_number",
        "currency",
        "exchange_rate",
        "exchange_rate_date",
        "exchange_rate_source",
        "revision",
      ]
        .filter((k) => form[k] != null)
        .map((k) => [k, form[k]]),
    );
    payload.lines = form.lines.map((l) => ({
      accounting_account_id: Number(l.accounting_account_id),
      description: l.description || null,
      debit: l.debit || 0,
      credit: l.credit || 0,
      foreign_debit: l.foreign_debit || 0,
      foreign_credit: l.foreign_credit || 0,
      dimensions: l.dimensions || null,
    }));
    const result = await run(
      () =>
        original
          ? api.updateJournal(original.id, payload)
          : api.createJournal(payload),
      t(
        "Manual draft saved; review before posting.",
        "Drafti manual u ruajt; shqyrtojeni para postimit.",
      ),
    );
    if (result) onSaved(result.id);
  };
  return (
    <WorkspaceModal
      title={
        original
          ? t("Edit manual draft", "Redakto draftin manual")
          : t("Prepare manual journal", "Përgatit ditarin manual")
      }
      onClose={onClose}
      busy={busy}
      warnOnUnsaved={dirty}
    >
      <form className="fc-form" onSubmit={submit}>
        <div className="fc-form-grid">
          <Field
            label={t("Posting date", "Data e postimit")}
            type="date"
            required
            value={form.posting_date}
            onChange={(e) => change("posting_date", e.target.value)}
          />
          <Field
            label={t("Reference", "Referenca")}
            maxLength={100}
            value={form.reference_number || ""}
            onChange={(e) => change("reference_number", e.target.value)}
          />
          <Field
            label={t("Currency", "Monedha")}
            required
            pattern="[A-Z]{3}"
            maxLength={3}
            value={form.currency}
            onChange={(e) => change("currency", e.target.value.toUpperCase())}
          />
        </div>
        <Field
          label={t("Description", "Përshkrimi")}
          required
          maxLength={1000}
          value={form.description}
          onChange={(e) => change("description", e.target.value)}
        />
        {form.currency !== currency && (
          <div className="fc-form-grid">
            <Field
              label={t(
                "Historical rate to base currency",
                "Kursi historik në monedhën bazë",
              )}
              required
              type="number"
              step=".00000001"
              min=".00000001"
              value={form.exchange_rate}
              onChange={(e) => change("exchange_rate", e.target.value)}
            />
            <Field
              label={t("Rate date", "Data e kursit")}
              required
              type="date"
              max={form.posting_date}
              value={form.exchange_rate_date}
              onChange={(e) => change("exchange_rate_date", e.target.value)}
            />
            <Field
              label={t(
                "Authorized rate source",
                "Burimi i autorizuar i kursit",
              )}
              required
              maxLength={160}
              value={form.exchange_rate_source || ""}
              onChange={(e) => change("exchange_rate_source", e.target.value)}
            />
          </div>
        )}
        <p className="fc-caption">
          {t(
            "Debit and credit are base-currency amounts. Save a draft first; posting is a separate reviewed action.",
            "Debi dhe kredi janë në monedhën bazë. Ruani fillimisht draftin; postimi kërkon shqyrtim veçmas.",
          )}
        </p>
        {form.lines.map((l, i) => (
          <fieldset className="fc-journal-line" key={i}>
            <legend>
              {t("Line", "Rreshti")} {i + 1}
            </legend>
            <Field label={t("Account", "Llogaria")}>
              <select
                required
                value={l.accounting_account_id}
                onChange={(e) =>
                  line(i, "accounting_account_id", e.target.value)
                }
              >
                <option value="">
                  {t("Select account", "Zgjidh llogarinë")}
                </option>
                {accounts
                  .filter((a) => a.is_active && a.is_posting)
                  .map((a) => (
                    <option value={a.id} key={a.id}>
                      {a.code} · {a.name}
                    </option>
                  ))}
              </select>
            </Field>
            <Field
              label={t("Debit", "Debi")}
              type="number"
              step=".01"
              min="0"
              value={l.debit}
              onChange={(e) => line(i, "debit", e.target.value)}
            />
            <Field
              label={t("Credit", "Kredi")}
              type="number"
              step=".01"
              min="0"
              value={l.credit}
              onChange={(e) => line(i, "credit", e.target.value)}
            />
            <Field
              label={t("Line description", "Përshkrimi i rreshtit")}
              maxLength={500}
              value={l.description || ""}
              onChange={(e) => line(i, "description", e.target.value)}
            />
            {form.currency !== currency && (
              <>
                <Field
                  label={t("Foreign debit", "Debi në valutë")}
                  type="number"
                  step=".01"
                  min="0"
                  value={l.foreign_debit || ""}
                  onChange={(e) => line(i, "foreign_debit", e.target.value)}
                />
                <Field
                  label={t("Foreign credit", "Kredi në valutë")}
                  type="number"
                  step=".01"
                  min="0"
                  value={l.foreign_credit || ""}
                  onChange={(e) => line(i, "foreign_credit", e.target.value)}
                />
              </>
            )}
            {form.lines.length > 2 && (
              <button
                type="button"
                onClick={() =>
                  change(
                    "lines",
                    form.lines.filter((_, n) => n !== i),
                  )
                }
              >
                {t("Remove line", "Hiq rreshtin")}
              </button>
            )}
          </fieldset>
        ))}
        <button
          type="button"
          disabled={form.lines.length >= 100}
          onClick={() =>
            change("lines", [
              ...form.lines,
              { accounting_account_id: "", debit: "", credit: "" },
            ])
          }
        >
          {t("Add line", "Shto rresht")}
        </button>
        <div className="fc-inline-totals">
          <span>
            {t("Debit", "Debi")} <Amount value={totals.debit / 100} />
          </span>
          <span>
            {t("Credit", "Kredi")} <Amount value={totals.credit / 100} />
          </span>
          <span>
            {t("Difference", "Diferenca")}{" "}
            <Amount value={(totals.debit - totals.credit) / 100} />
          </span>
        </div>
        <button disabled={busy}>{t("Save draft", "Ruaj draftin")}</button>
      </form>
    </WorkspaceModal>
  );
}
