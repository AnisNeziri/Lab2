import { useEffect, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useAuthStore } from "../store/authStore";
import { useTranslation } from "../hooks/useTranslation";
import * as api from "../api/tax";
import { getAccountingAccounts } from "../api/accounting";
import { getProducts } from "../api/products";
import "./TaxWorkspace.css";
export const cash = (v) =>
  v == null
    ? "—"
    : new Intl.NumberFormat("en-IE", {
        style: "currency",
        currency: "EUR",
      }).format(Number(v));
export function TaxStatus({ value }) {
  return (
    <span className={"tax-status tax-status-" + String(value).toLowerCase()}>
      {String(value).replaceAll("_", " ")}
    </span>
  );
}
export default function TaxWorkspace() {
  const { language } = useTranslation(),
    t = (en, sq) => (language === "sq" ? sq : en);
  const permissions = useAuthStore((s) => s.permissions),
    can = permissions.includes("tax.configure");
  const [params, setParams] = useSearchParams(),
    tab = params.get("tab") || "vat",
    period = params.get("period") || new Date().toISOString().slice(0, 7);
  const [config, setConfig] = useState(null),
    [report, setReport] = useState(null),
    [docs, setDocs] = useState(null),
    [accounts, setAccounts] = useState([]),
    [products, setProducts] = useState([]),
    [search, setSearch] = useState("");
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [tick, setTick] = useState(0),
    [code, setCode] = useState(null);
  async function run(action) {
    setBusy(true);
    setError("");
    try {
      await action();
      setTick((x) => x + 1);
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    let alive = true;
    setError("");
    if (tab === "vat") setReport(null);
    const call =
      tab === "vat" ? api.vatPeriod({ period }) : api.taxConfiguration();
    call
      .then((d) => {
        if (alive) (tab === "vat" ? setReport : setConfig)(d);
      })
      .catch((e) => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [tab, period, tick]);
  useEffect(() => {
    if (tab !== "configuration" || !can) return;
    getAccountingAccounts()
      .then((d) => setAccounts(d.data || d.accounts || d))
      .catch((e) => setError(e.message));
    getProducts({ search, per_page: 20 })
      .then((d) => setProducts(d.data || []))
      .catch((e) => setError(e.message));
  }, [tab, can, search, tick]);
  useEffect(() => {
    if (!params.get("box")) {
      setDocs(null);
      return;
    }
    let alive = true;
    api
      .vatDocuments({
        period,
        book: params.get("book") || "sales",
        box: params.get("box"),
        page: params.get("page") || 1,
      })
      .then((d) => alive && setDocs(d))
      .catch((e) => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [params, period, tick]);
  const change = (p) => setParams({ ...Object.fromEntries(params), ...p });
  return (
    <main className="tax-workspace">
      <header className="tax-heading">
        <div>
          <p className="tax-eyebrow">
            AIMS · {t("Financial control", "Kontrolli financiar")}
          </p>
          <h1>{t("Tax & VAT", "Tatimet dhe TVSH")}</h1>
          <p>
            {t(
              "Posted source evidence, current return preview and control reconciliation.",
              "Provat e postuara, parapamja e deklaratës dhe rakordimi.",
            )}
          </p>
        </div>
        <Link to="/accounting">{t("Accounting", "Kontabiliteti")}</Link>
        <Link to="/fiscalization">{t("Fiscalization", "Fiskalizimi")}</Link>
      </header>
      <nav className="tax-tabs">
        {[
          ["vat", t("VAT period", "Periudha e TVSH")],
          ["configuration", t("Tax configuration", "Konfigurimi tatimor")],
        ].map(([id, label]) => (
          <button
            key={id}
            className={tab === id ? "active" : ""}
            onClick={() => setParams({ tab: id, period })}
          >
            {label}
          </button>
        ))}
      </nav>
      {error && (
        <p role="alert" className="tax-error">
          {error}
        </p>
      )}
      {tab === "vat" ? (
        <>
          <div className="tax-toolbar">
            <label>
              {t("Reporting month", "Muaji raportues")}{" "}
              <input
                type="month"
                value={period}
                onChange={(e) => setParams({ tab, period: e.target.value })}
              />
            </label>
            <button
              disabled={
                !report || busy || !permissions.includes("finance.export")
              }
              onClick={() => run(() => api.exportVat(period))}
            >
              {t("Export preview CSV", "Eksporto parapamjen CSV")}
            </button>
          </div>
          {!report ? (
            <p role="status">
              {t("Loading VAT controls…", "Duke ngarkuar kontrollet…")}
            </p>
          ) : (
            <>
              <div className="tax-notice">
                <TaxStatus
                  value={
                    report.ready_for_review ? "READY FOR REVIEW" : "ATTENTION"
                  }
                />{" "}
                {t(
                  "Preview only. No VAT return is filed. Prior filed carry-forward and refund choices require external evidence.",
                  "Vetëm parapamje. Deklarata nuk dorëzohet. Kreditimi i mëparshëm dhe rimbursimi kërkojnë prova të jashtme.",
                )}
              </div>
              <section className="tax-panel">
                <h2>{t("VAT ↔ General Ledger", "TVSH ↔ Libri kryesor")}</h2>
                <table>
                  <thead>
                    <tr>
                      <th>{t("Control", "Kontrolli")}</th>
                      <th className="money">
                        {t("Source documents", "Dokumentet")}
                      </th>
                      <th className="money">GL</th>
                      <th className="money">{t("Difference", "Diferenca")}</th>
                      <th>{t("Status", "Gjendja")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {Object.entries(report.reconciliation.controls).map(
                      ([key, r]) => (
                        <tr key={key}>
                          <th>
                            {key === "input_vat"
                              ? t("Input VAT", "TVSH hyrëse")
                              : t("Output VAT", "TVSH dalëse")}
                          </th>
                          <td className="money">{cash(r.operational_total)}</td>
                          <td className="money">{cash(r.gl_total)}</td>
                          <td className="money">{cash(r.difference)}</td>
                          <td>
                            <TaxStatus
                              value={r.reconciled ? "RECONCILED" : "DIFFERENCE"}
                            />
                          </td>
                        </tr>
                      ),
                    )}
                  </tbody>
                </table>
                <p className="tax-caption">{report.reconciliation.basis}</p>
              </section>
              {(report.warnings.length > 0 || report.unmapped.length > 0) && (
                <details className="tax-panel" open>
                  <summary>
                    {t("Accountant review", "Shqyrtimi i kontabilistit")} ·{" "}
                    {report.warnings.length + report.unmapped.length}
                  </summary>
                  <ul>
                    {report.warnings.map((w, i) => (
                      <li key={i}>
                        {w.message} {w.count ? "(" + w.count + ")" : ""}
                      </li>
                    ))}
                    {report.unmapped.map((w, i) => (
                      <li key={"u" + i}>
                        {w.book} · {w.count} · {w.reason}
                      </li>
                    ))}
                  </ul>
                </details>
              )}
              <section className="tax-panel">
                <h2>{t("ATK TV return preview", "Parapamja ATK TV")}</h2>
                <p className="tax-caption">
                  {report.qualification}{" "}
                  <a href={report.source} target="_blank" rel="noreferrer">
                    {t("Official fields", "Fushat zyrtare")}
                  </a>
                </p>
                <table>
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>{t("Official field", "Fusha zyrtare")}</th>
                      <th className="money">{t("Amount EUR", "Shuma EUR")}</th>
                      <th>{t("Source", "Burimi")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.rows.map((r) => (
                      <tr key={r.number}>
                        <td>{r.number}</td>
                        <th>{r.label}</th>
                        <td className="money">{cash(r.amount)}</td>
                        <td>
                          {r.drill_url ? (
                            <Link to={r.drill_url}>
                              {r.source_count} {t("lines", "rreshta")}
                            </Link>
                          ) : r.amount === null ? (
                            t("External/review", "Jashtë/shqyrtim")
                          ) : (
                            "—"
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </section>
              {docs && (
                <section className="tax-panel">
                  <h2>
                    {t("Source documents", "Dokumentet burimore")} ·{" "}
                    {docs.total}
                  </h2>
                  <table>
                    <thead>
                      <tr>
                        <th>{t("Reference", "Referenca")}</th>
                        <th>{t("Date", "Data")}</th>
                        <th>{t("Description", "Përshkrimi")}</th>
                        <th className="money">Net</th>
                        <th className="money">VAT</th>
                        <th>{t("Journal / source", "Ditari / burimi")}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {docs.data.map((r) => (
                        <tr key={r.id}>
                          <th>
                            <Link to={r.url}>{r.reference}</Link>
                          </th>
                          <td>{String(r.date).slice(0, 10)}</td>
                          <td>{r.description}</td>
                          <td className="money">
                            {cash(r.import_taxable_base_eur ?? r.net)}
                          </td>
                          <td className="money">{cash(r.vat)}</td>
                          <td>
                            <Link
                              to={
                                "/accounting?tab=journal&reference=" +
                                encodeURIComponent(r.reference)
                              }
                            >
                              {t("Trace journal", "Gjurmo ditarin")}
                            </Link>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  <button
                    disabled={docs.current_page <= 1}
                    onClick={() =>
                      change({ page: String(docs.current_page - 1) })
                    }
                  >
                    {t("Previous", "Prapa")}
                  </button>
                  <button
                    disabled={docs.current_page >= docs.last_page}
                    onClick={() =>
                      change({ page: String(docs.current_page + 1) })
                    }
                  >
                    {t("Next", "Tjetra")}
                  </button>
                </section>
              )}
            </>
          )}
        </>
      ) : (
        <>
          {!config ? (
            <p role="status">{t("Loading…", "Duke ngarkuar…")}</p>
          ) : (
            <>
              <div className="tax-toolbar">
                {can && (
                  <>
                    <button
                      disabled={busy}
                      onClick={() => run(api.initializeTax)}
                    >
                      {t(
                        "Initialize missing template codes",
                        "Shto kodet model që mungojnë",
                      )}
                    </button>
                    <button
                      onClick={() =>
                        setCode({
                          code: "",
                          name: "",
                          rate: "0",
                          treatment: "taxable",
                          sales_enabled: true,
                          purchases_enabled: true,
                          effective_from: new Date().toISOString().slice(0, 10),
                          effective_to: null,
                          active: true,
                          input_account_id: "",
                          output_account_id: "",
                          sef_tax_group: "",
                          legal_reference: "",
                        })
                      }
                    >
                      {t("New rule version", "Version i ri")}
                    </button>
                  </>
                )}
              </div>
              <p>{config.qualification}</p>
              <p className="tax-caption">
                {t(
                  "Explicit tax codes required from",
                  "Kodet tatimore kërkohen nga",
                )}
                : {config.required_from || "—"}
              </p>
              {can && (
                <form
                  className="tax-toolbar"
                  onSubmit={(e) => {
                    e.preventDefault();
                    const date = new FormData(e.currentTarget).get(
                      "required_from",
                    );
                    run(() => api.saveTaxPolicy(date));
                  }}
                >
                  <label>
                    {t("Require tax codes from", "Kërko kodet tatimore nga")}
                    <input
                      name="required_from"
                      type="date"
                      min={new Date().toISOString().slice(0, 10)}
                      required
                    />
                  </label>
                  <button disabled={busy}>
                    {t(
                      "Apply future entry policy",
                      "Zbato politikën e regjistrimit",
                    )}
                  </button>
                </form>
              )}
              <section className="tax-panel">
                <table>
                  <thead>
                    <tr>
                      <th>{t("Code / name", "Kodi / emri")}</th>
                      <th>%</th>
                      <th>{t("Treatment", "Trajtimi")}</th>
                      <th>{t("Effective", "Hyn në fuqi")}</th>
                      <th>{t("Control accounts", "Llogaritë")}</th>
                      <th>{t("Status", "Gjendja")}</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {config.codes.map((c) => (
                      <tr key={c.id}>
                        <th>
                          {c.code}
                          <small>{c.name}</small>
                        </th>
                        <td className="money">{c.rate}</td>
                        <td>{c.treatment}</td>
                        <td>
                          {String(c.effective_from).slice(0, 10)}
                          {c.effective_to
                            ? " → " + String(c.effective_to).slice(0, 10)
                            : ""}
                        </td>
                        <td>
                          IN #{c.input_account_id || "—"} · OUT #
                          {c.output_account_id || "—"}
                        </td>
                        <td>{c.active ? "Active" : "Retired"}</td>
                        <td>
                          {can && (
                            <>
                              <button
                                onClick={() =>
                                  setCode({
                                    ...c,
                                    id: undefined,
                                    effective_from: new Date()
                                      .toISOString()
                                      .slice(0, 10),
                                  })
                                }
                              >
                                {t("New version", "Version i ri")}
                              </button>
                              {c.active && (
                                <button
                                  disabled={busy}
                                  onClick={() =>
                                    run(() => api.retireTaxCode(c.id))
                                  }
                                >
                                  {t("Retire", "Çaktivizo")}
                                </button>
                              )}
                            </>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </section>
              {can && (
                <section className="tax-panel">
                  <h2>
                    {t(
                      "Product tax classification",
                      "Klasifikimi tatimor i produktit",
                    )}
                  </h2>
                  <input
                    aria-label={t("Find product", "Gjej produkt")}
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder={t("Search products…", "Kërko produkte…")}
                  />
                  <p className="tax-caption">
                    {t(
                      "Explicit accountant decision. Product names never select a VAT rate.",
                      "Vendim i kontabilistit. Emri i produktit nuk përcakton normën.",
                    )}
                  </p>
                  <table>
                    <tbody>
                      {products.map((p) => (
                        <tr key={p.id}>
                          <th>
                            {p.name}
                            <small>{p.sku}</small>
                          </th>
                          <td>
                            <select
                              aria-label={p.name + " tax code"}
                              value={p.default_tax_code_id || ""}
                              disabled={busy}
                              onChange={(e) =>
                                run(() =>
                                  api.assignProductTax(
                                    p.id,
                                    e.target.value
                                      ? Number(e.target.value)
                                      : null,
                                  ),
                                )
                              }
                            >
                              <option value="">
                                {t("Unclassified", "Pa klasifikim")}
                              </option>
                              {config.codes
                                .filter((c) => c.active && c.sales_enabled)
                                .map((c) => (
                                  <option value={c.id} key={c.id}>
                                    {c.code} · {c.name}
                                  </option>
                                ))}
                            </select>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </section>
              )}
            </>
          )}
          {code && (
            <section className="tax-panel">
              <h2>{t("New tax rule version", "Version i ri i rregullit")}</h2>
              <form
                className="tax-form"
                onSubmit={(e) => {
                  e.preventDefault();
                  run(async () => {
                    await api.saveTaxCode({
                      ...code,
                      input_account_id: code.input_account_id
                        ? Number(code.input_account_id)
                        : null,
                      output_account_id: code.output_account_id
                        ? Number(code.output_account_id)
                        : null,
                      sef_tax_group: code.sef_tax_group || null,
                      effective_to: code.effective_to || null,
                    });
                    setCode(null);
                  });
                }}
              >
                {[
                  "code",
                  "name",
                  "rate",
                  "effective_from",
                  "effective_to",
                  "legal_reference",
                ].map((k) => (
                  <label key={k}>
                    {k.replaceAll("_", " ")}
                    <input
                      required={[
                        "code",
                        "name",
                        "rate",
                        "effective_from",
                      ].includes(k)}
                      type={
                        k.startsWith("effective")
                          ? "date"
                          : k === "rate"
                            ? "number"
                            : "text"
                      }
                      step="0.01"
                      value={code[k] ?? ""}
                      onChange={(e) =>
                        setCode({ ...code, [k]: e.target.value })
                      }
                    />
                  </label>
                ))}
                <label>
                  {t("Treatment", "Trajtimi")}
                  <select
                    value={code.treatment}
                    onChange={(e) =>
                      setCode({ ...code, treatment: e.target.value })
                    }
                  >
                    {config?.treatments.map((x) => (
                      <option key={x}>{x}</option>
                    ))}
                  </select>
                </label>
                {["input", "output"].map((side) => (
                  <label key={side}>
                    {side === "input"
                      ? "Input VAT account"
                      : "Output VAT account"}
                    <select
                      value={code[side + "_account_id"] || ""}
                      onChange={(e) =>
                        setCode({
                          ...code,
                          [side + "_account_id"]: e.target.value,
                        })
                      }
                    >
                      <option value="">—</option>
                      {accounts
                        .filter(
                          (a) =>
                            a.is_active &&
                            a.is_posting &&
                            a.type ===
                              (side === "input" ? "asset" : "liability"),
                        )
                        .map((a) => (
                          <option key={a.id} value={a.id}>
                            {a.code} · {a.name}
                          </option>
                        ))}
                    </select>
                  </label>
                ))}
                <label>
                  SEF group
                  <select
                    value={code.sef_tax_group || ""}
                    onChange={(e) =>
                      setCode({ ...code, sef_tax_group: e.target.value })
                    }
                  >
                    <option value="">—</option>
                    {["A", "C", "D", "E"].map((x) => (
                      <option key={x}>{x}</option>
                    ))}
                  </select>
                </label>
                {["sales_enabled", "purchases_enabled"].map((k) => (
                  <label key={k}>
                    <input
                      type="checkbox"
                      checked={code[k]}
                      onChange={(e) =>
                        setCode({ ...code, [k]: e.target.checked })
                      }
                    />
                    {k.replaceAll("_", " ")}
                  </label>
                ))}
                <button disabled={busy}>
                  {t("Save new version", "Ruaj versionin")}
                </button>
                <button type="button" onClick={() => setCode(null)}>
                  {t("Cancel", "Anulo")}
                </button>
              </form>
            </section>
          )}
        </>
      )}
    </main>
  );
}
