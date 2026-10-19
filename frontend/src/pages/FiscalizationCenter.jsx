import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useAuthStore } from "../store/authStore";
import { useTranslation } from "../hooks/useTranslation";
import * as api from "../api/tax";
import qrcode from "../vendor/qrcode.mjs";
import { TaxStatus, cash } from "./TaxWorkspace";
import "./TaxWorkspace.css";
export default function FiscalizationCenter() {
  const { language } = useTranslation(),
    t = (en, sq) => (language === "sq" ? sq : en),
    permissions = useAuthStore((s) => s.permissions),
    can = (p) => permissions.includes("fiscalization." + p);
  const [params, setParams] = useSearchParams(),
    [summary, setSummary] = useState(null),
    [list, setList] = useState(null),
    [mapping, setMapping] = useState(null),
    [detail, setDetail] = useState(null),
    [config, setConfig] = useState(null),
    [map, setMap] = useState({ kind: "unit", local_key: "", official_id: "" }),
    [reason, setReason] = useState("");
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [tick, setTick] = useState(0),
    [confirmation, setConfirmation] = useState(null),
    [search, setSearch] = useState("");
  async function run(fn) {
    setError("");
    setBusy(true);
    try {
      const r = await fn();
      setTick((x) => x + 1);
      return r;
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    let alive = true;
    Promise.all([
      api.fiscalSummary(),
      api.fiscalInvoices({ search, invoice:params.get("invoice")||undefined, page: params.get("page") || 1 }),
      api.fiscalMappings(),
    ])
      .then(([s, l, m]) => {
        if (alive) {
          setSummary(s);
          setList(l);
          setMapping(m);
        }
      })
      .catch((e) => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [tick, search, params]);
  useEffect(() => {
    if (!params.get("receipt")) {
      setDetail(null);
      return;
    }
    let alive = true;
    api
      .fiscalReceipt(params.get("receipt"))
      .then((d) => alive && setDetail(d))
      .catch((e) => alive && setError(e.message));
    return () => {
      alive = false;
    };
  }, [params, tick]);
  const qr = useMemo(() => {
    if (!detail?.qr_content) return null;
    try {
      const c = qrcode(0, "M");
      c.addData(detail.qr_content);
      c.make();
      return c.createDataURL(4, 16);
    } catch {
      return null;
    }
  }, [detail]);
  const show = (id) => setParams({ receipt: String(id) });
  function configure() {
    setConfig(
      summary.configuration || {
        environment: "test",
        branch_id: "",
        pos_id: "",
        application_id: "",
        location: "",
        activity_policy: "needs_review",
        policy_basis: "",
        signature_format: "ieee_p1363",
        starting_coupon_no: "",
        enabled: false,
      },
    );
  }
  return (
    <main className="tax-workspace">
      <header className="tax-heading">
        <div>
          <p className="tax-eyebrow">AIMS · ATK TEST</p>
          <h1>{t("Fiscalization", "Fiskalizimi")}</h1>
          <p>
            {t(
              "Delivery status, immutable evidence and certification preparation.",
              "Gjendja e dërgimit, provat e pandryshueshme dhe përgatitja.",
            )}
          </p>
        </div>
        <Link to="/tax">{t("Tax & VAT", "Tatimet dhe TVSH")}</Link>
        <Link to="/invoices">{t("Invoices", "Faturat")}</Link>
      </header>
      {error && (
        <p role="alert" className="tax-error">
          {error}
        </p>
      )}
      {!summary ? (
        <p role="status">
          {t("Loading fiscal controls…", "Duke ngarkuar kontrollet…")}
        </p>
      ) : (
        <>
          <div className="tax-notice">
            {t(
              "ATK TEST only · Official certification: NO · Official API interoperability: ",
              "Vetëm ATK TEST · Certifikimi zyrtar: JO · Ndërveprimi zyrtar: ",
            )}
            <strong>{summary.readiness.official_api_result}</strong>
          </div>
          <div className="tax-kpis">
            {[
              ["Today", summary.today_fiscalized],
              ["Fiscalized", summary.counts.fiscalized || 0],
              ["Pending", summary.counts.pending || 0],
              ["Failed", summary.counts.failed || 0],
              ["Unknown result", summary.counts.unknown || 0],
            ].map(([label, n]) => (
              <div key={label}>
                {label}
                <strong>{n}</strong>
              </div>
            ))}
          </div>
          {summary.recent_failures.length>0&&<section className="tax-panel"><h2>{t('Recent deliveries requiring review','Dërgimet e fundit për shqyrtim')}</h2>{summary.recent_failures.map(r=><p key={r.id}><button onClick={()=>show(r.id)}>#{r.coupon_no} · {r.status}</button> {r.operator_message}</p>)}</section>}
          <section className="tax-panel">
            <h2>{t("Configuration status", "Gjendja e konfigurimit")}</h2>
            <p>
              <TaxStatus
                value={
                  summary.configuration?.enabled ? "ENABLED TEST" : "DISABLED"
                }
              />{" "}
              · {t("Signing credential", "Kredenciali i nënshkrimit")}:{" "}
              {summary.signing_ready
                ? t("Installed / valid locally", "I instaluar / lokal")
                : t("Required", "Kërkohet")}
            </p>
            {summary.configuration && (
              <p className="tax-caption">
                NUI {summary.configuration.taxpayer_nui} · Branch{" "}
                {summary.configuration.branch_id} · POS{" "}
                {summary.configuration.pos_id} · Certificate SHA256{" "}
                {summary.configuration.credential_fingerprint || "—"} · Expires{" "}
                {summary.configuration.certificate_valid_to || "—"}
              </p>
            )}
            {can("configure") && (
              <button onClick={configure}>
                {t("Configure ATK TEST", "Konfiguro ATK TEST")}
              </button>
            )}
            <p className="tax-caption">
              {t(
                "Private keys are installed on the server with sef:credentials. No private key is accepted in the browser.",
                "Çelësat privatë instalohen në server me sef:credentials. Shfletuesi nuk pranon çelësa privatë.",
              )}
            </p>
          </section>
          <details className="tax-panel" open>
            <summary>
              {t(
                "Certification readiness — NOT READY",
                "Gatishmëria për certifikim — JO GATI",
              )}
            </summary>
            <ul>
              {summary.readiness.gaps.map((g) => (
                <li key={g}>{g}</li>
              ))}
            </ul>
            <a
              href="https://apps.atk-ks.org/sefaplikimi/"
              target="_blank"
              rel="noreferrer"
            >
              {t("Official ATK documentation", "Dokumentacioni zyrtar ATK")}
            </a>
          </details>
          {config && (
            <section className="tax-panel">
              <h2>{t("TEST registration", "Regjistrimi TEST")}</h2>
              <form
                className="tax-form"
                onSubmit={(e) => {
                  e.preventDefault();
                  run(async () => {
                    await api.saveFiscalConfig({
                      ...config,
                      environment: "test",
                      branch_id: String(config.branch_id),
                      pos_id: String(config.pos_id),
                      application_id: String(config.application_id),
                    });
                    setConfig(null);
                  });
                }}
              >
                {[
                  "branch_id",
                  "pos_id",
                  "application_id",
                  "location",
                  ...(!config.id ? ["starting_coupon_no"] : []),
                  "policy_basis",
                ].map((k) => (
                  <label key={k}>
                    {k.replaceAll("_", " ")}
                    <input
                      required={k !== "policy_basis"}
                      value={config[k] || ""}
                      onChange={(e) =>
                        setConfig({ ...config, [k]: e.target.value })
                      }
                    />
                  </label>
                ))}
                <label>
                  {t("Reviewed activity policy", "Politika e veprimtarisë")}
                  <select
                    value={config.activity_policy}
                    onChange={(e) =>
                      setConfig({ ...config, activity_policy: e.target.value })
                    }
                  >
                    {["needs_review", "business_only", "retail"].map((x) => (
                      <option key={x}>{x}</option>
                    ))}
                  </select>
                </label>
                <label>
                  {t("Published signing profile", "Profili i nënshkrimit")}
                  <select
                    value={config.signature_format}
                    onChange={(e) =>
                      setConfig({ ...config, signature_format: e.target.value })
                    }
                  >
                    <option value="ieee_p1363">C# · P1363</option>
                    <option value="der">Go · ASN.1 DER</option>
                  </select>
                </label>
                <label>
                  <input
                    type="checkbox"
                    checked={config.enabled}
                    onChange={(e) =>
                      setConfig({ ...config, enabled: e.target.checked })
                    }
                  />
                  {t(
                    "Enable explicit TEST submissions",
                    "Aktivizo dërgimet TEST",
                  )}
                </label>
                <button disabled={busy}>
                  {t("Save TEST configuration", "Ruaj konfigurimin TEST")}
                </button>
                <button type="button" onClick={() => setConfig(null)}>
                  {t("Cancel", "Anulo")}
                </button>
              </form>
            </section>
          )}
          <section className="tax-panel">
            <h2>{t("Issued invoices", "Faturat e lëshuara")}</h2>
            <input
              aria-label={t("Find invoice", "Gjej faturë")}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t("Invoice number…", "Numri i faturës…")}
            />
            <table>
              <thead>
                <tr>
                  <th>{t("Invoice", "Fatura")}</th>
                  <th className="money">Gross</th>
                  <th>{t("Policy / status", "Politika / gjendja")}</th>
                  <th>{t("Action", "Veprimi")}</th>
                </tr>
              </thead>
              <tbody>
                {list?.data.map((i) => (
                  <tr key={i.id}>
                    <th>
                      <Link to={i.url}>{i.invoice_number}</Link>
                      <small>{String(i.invoice_date).slice(0, 10)}</small>
                    </th>
                    <td className="money">{cash(i.grand_total)}</td>
                    <td>
                      <TaxStatus
                        value={i.receipt?.status || i.policy.decision}
                      />
                      <small>{i.policy.reason}</small>
                    </td>
                    <td>
                      {i.receipt ? (
                        <button onClick={() => show(i.receipt.id)}>
                          {t("Open receipt", "Hap kuponin")}
                        </button>
                      ) : can("prepare") && i.policy.decision === "required" ? (
                        <button
                          disabled={busy}
                          onClick={() =>
                            run(async () => {
                              const r = await api.prepareFiscal(i.id);
                              show(r.id);
                            })
                          }
                        >
                          {t(
                            "Prepare signed TEST receipt",
                            "Përgatit kuponin TEST",
                          )}
                        </button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="tax-toolbar">
              <button
                disabled={!list || list.current_page <= 1}
                onClick={() =>
                  setParams({ page: String(list.current_page - 1) })
                }
              >
                {t("Previous", "Prapa")}
              </button>
              <span>
                {list?.current_page || 1} / {list?.last_page || 1}
              </span>
              <button
                disabled={!list || list.current_page >= list.last_page}
                onClick={() =>
                  setParams({ page: String(list.current_page + 1) })
                }
              >
                {t("Next", "Tjetra")}
              </button>
            </div>
          </section>
          {detail && (
            <section className="tax-panel" aria-label="Fiscal receipt detail">
              <h2>
                {t("Fiscal receipt", "Kuponi fiskal")} #
                {detail.receipt.coupon_no} · ATK TEST
              </h2>
              <TaxStatus value={detail.receipt.status} />{detail.evidence_warning&&<p role="alert" className="tax-error">{detail.evidence_warning}</p>}
              <p>{detail.receipt.operator_message}</p>
              <p>
                {t("ATK transaction", "Transaksioni ATK")}:{" "}
                {detail.receipt.provider_transaction_id || "—"}
              </p>
              <Link to={detail.source_url}>
                {detail.receipt.invoice.invoice_number}
              </Link>{" "}
              ·{" "}
              <Link to={detail.documents_url}>
                {t("Supporting documents", "Dokumentet mbështetëse")}
              </Link>
              {qr && (
                <div>
                  <img
                    width="240"
                    height="240"
                    src={qr}
                    alt="ATK TEST fiscal receipt QR"
                  />
                  <p className="tax-caption">
                    {t(
                      "TEST receipt — not a certified commercial receipt.",
                      "Kupon TEST — jo kupon komercial i certifikuar.",
                    )}
                  </p>
                </div>
              )}
              <p className="tax-evidence">
                SHA256 {detail.evidence.unsigned_request_sha256}
              </p>
              <table>
                <thead>
                  <tr>
                    <th>#</th>
                    <th>{t("Started", "Filluar")}</th>
                    <th>{t("State", "Gjendja")}</th>
                    <th>HTTP</th>
                    <th>{t("Response fingerprint", "Gjurmë përgjigjeje")}</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.receipt.attempts.map((a) => (
                    <tr key={a.id}>
                      <td>{a.number}</td>
                      <td>{a.started_at}</td>
                      <td>
                        <TaxStatus value={a.state} />
                      </td>
                      <td>{a.http_status || "—"}</td>
                      <td className="tax-evidence">
                        {a.response_fingerprint || "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {can("submit") &&
                ["ready", "failed"].includes(detail.receipt.status) && (
                  <button
                    disabled={
                      busy ||
                      !summary.configuration?.enabled ||
                      !summary.signing_ready
                    }
                    onClick={() => setConfirmation(detail.receipt.id)}
                  >
                    {detail.receipt.status === "failed"
                      ? t("Review and retry TEST", "Shqyrto dhe riprovo TEST")
                      : t("Review TEST submission", "Shqyrto dërgimin TEST")}
                  </button>
                )}
              {confirmation === detail.receipt.id && (
                <div className="tax-confirm">
                  <p>
                    {t(
                      "Send this frozen, signed coupon to the official ATK TEST endpoint? A documented rejection can be retried. An unknown outcome cannot be resent.",
                      "Dërgo kuponin e ngrirë dhe të nënshkruar në ATK TEST? Refuzimi i qartë mund të riprovohet. Rezultati i panjohur nuk ridërgohet.",
                    )}
                  </p>
                  <button
                    disabled={busy}
                    onClick={() =>
                      run(async () => {
                        await api.submitFiscal(confirmation);
                        setConfirmation(null);
                      })
                    }
                  >
                    {t("Submit to ATK TEST", "Dërgo në ATK TEST")}
                  </button>
                  <button onClick={() => setConfirmation(null)}>
                    {t("Cancel", "Anulo")}
                  </button>
                </div>
              )}
              {["pending", "unknown"].includes(detail.receipt.status) && (
                <p className="tax-notice">
                  {t(
                    "Do not resend. Obtain ATK confirmation and preserve the case reference. Recording a review never unlocks a retry.",
                    "Mos ridërgo. Kërko konfirmim nga ATK dhe ruaj referencën. Shqyrtimi nuk aktivizon riprovimin.",
                  )}
                </p>
              )}
              {can("prepare") && (
                <form
                  className="tax-toolbar"
                  onSubmit={(e) => {
                    e.preventDefault();
                    run(async () => {
                      await api.reviewFiscal(detail.receipt.id, reason);
                      setReason("");
                    });
                  }}
                >
                  <label>
                    {t("ATK case / review reason", "Rasti ATK / arsyeja")}
                    <input
                      value={reason}
                      minLength={10}
                      required
                      onChange={(e) => setReason(e.target.value)}
                    />
                  </label>
                  <button disabled={busy}>
                    {t("Record review", "Regjistro shqyrtimin")}
                  </button>
                </form>
              )}
            </section>
          )}
          {mapping && (
            <details className="tax-panel">
              <summary>
                {t(
                  "Official measurement units & categories",
                  "Njësitë dhe kategoritë zyrtare",
                )}{" "}
                · {mapping.mappings.length}
              </summary>
              <table>
                <tbody>
                  {mapping.mappings.map((m) => (
                    <tr key={m.id}>
                      <th>
                        {m.kind} · {m.local_key}
                      </th>
                      <td>ATK #{m.official_id}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {can("configure") && (
                <form
                  className="tax-form"
                  onSubmit={(e) => {
                    e.preventDefault();
                    run(() =>
                      api.saveFiscalMapping({
                        ...map,
                        official_id: Number(map.official_id),
                      }),
                    );
                  }}
                >
                  <label>
                    {t("Mapping kind", "Lloji")}
                    <select
                      value={map.kind}
                      onChange={(e) =>
                        setMap({
                          ...map,
                          kind: e.target.value,
                          official_id: "",
                        })
                      }
                    >
                      <option value="unit">Unit</option>
                      <option value="category">Product category</option>
                    </select>
                  </label>
                  <label>
                    {t("AIMS unit / category ID", "Njësia AIMS / ID kategorie")}
                    <input
                      required
                      value={map.local_key}
                      onChange={(e) =>
                        setMap({ ...map, local_key: e.target.value })
                      }
                    />
                  </label>
                  <label>
                    ATK
                    <select
                      required
                      value={map.official_id}
                      onChange={(e) =>
                        setMap({ ...map, official_id: e.target.value })
                      }
                    >
                      <option value="">—</option>
                      {mapping.catalog[
                        map.kind === "unit" ? "units" : "categories"
                      ]
                        .filter(
                          (c) =>
                            !mapping.catalog.ambiguous_category_codes?.[
                              c.code
                            ] || map.kind === "unit",
                        )
                        .map((c) => (
                          <option key={c.id} value={c.id}>
                            {c.id} · {c.code} ·{" "}
                            {c.name || c.description || c.label}
                          </option>
                        ))}
                    </select>
                  </label>
                  <button disabled={busy}>
                    {t("Save mapping", "Ruaj lidhjen")}
                  </button>
                </form>
              )}
            </details>
          )}
        </>
      )}
    </main>
  );
}
