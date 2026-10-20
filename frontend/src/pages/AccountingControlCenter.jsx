import { useCallback, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
  RefreshCw,
  BookOpen,
  Landmark,
  CalendarCheck,
  FileChartColumn,
  Users,
  Building2,
  Scale,
  Settings2,
} from "lucide-react";
import { useAuthStore } from "../store/authStore";
import { NextStep } from "../components/WorkflowSummary";
import { useSessionState } from "../hooks/useSessionState";
import { useSettingsStore } from "../store/settingsStore";
import * as api from "../api/accounting";
import {
  Amount,
  Badge,
  FinanceContext,
  Field,
  QueryState,
  localToday,
  useFinance,
  useFinanceQuery,
  errorMessage,
} from "../components/finance/FinanceShared";
import FinanceJournals from "../components/finance/FinanceJournals";
import FinanceReports, {
  FinanceAccounts,
} from "../components/finance/FinanceReports";
import FinanceReceivables, {
  FinancePayables,
} from "../components/finance/FinanceSubledgers";
import FinanceLanded from "../components/finance/FinanceLanded";
import FinanceBank from "../components/finance/FinanceBank";
import FinancePeriods from "../components/finance/FinancePeriods";
import FinanceRecovery from "../components/finance/FinanceRecovery";
import { AccountingSetup } from "./AccountingSetup";
import "./AccountingControlCenter.css";

export default function AccountingControlCenter() {
  const language = useSettingsStore((s) => s.language);
  const user = useAuthStore((s) => s.user),
    permissions = useAuthStore((s) => s.permissions);
  const can = useCallback((p) => permissions.includes(p), [permissions]);
  const t = (en, sq) => (language === "sq" ? sq || en : en);
  const [params, setParams] = useSearchParams();
  const today = localToday(user?.company?.timezone);
  const [dates, setDates] = useSessionState("accounting.dates", {
    from: `${today.slice(0, 7)}-01`,
    to: today,
  });
  const [refresh, setRefresh] = useState(0),
    [busy, setBusy] = useState(false),
    [notice, setNotice] = useState(null);
  const summary = useFinanceQuery(
    () => api.getFinancialControl(dates),
    [dates.from, dates.to, refresh],
  );
  const chart = useFinanceQuery(
    () => (can("accounting.journal.view") ? api.getAccountingAccounts() : []),
    [permissions.join("|"), refresh],
  );
  const periods = useFinanceQuery(
    () => (can("accounting.periods.manage") ? api.getAccountingPeriods() : []),
    [permissions.join("|"), refresh],
  );
  const currency =
    summary.data?.currency || user?.company?.base_currency || "EUR";
  const run = async (action, message) => {
    if (busy) return null;
    setBusy(true);
    setNotice(null);
    try {
      const result = await action();
      setNotice({
        text:
          message ||
          t(
            "Saved. Financial controls refreshed.",
            "U ruajt. Kontrollet financiare u rifreskuan.",
          ),
      });
      setRefresh((x) => x + 1);
      return result;
    } catch (e) {
      setNotice({ text: errorMessage(e), error: true });
      return null;
    } finally {
      setBusy(false);
    }
  };
  const tabs = [
    ["overview", Landmark, t("Control Center", "Qendra e kontrollit")],
    ["owner", Landmark, t("Owner view", "Pamja e pronarit")],
    [
      "journal",
      BookOpen,
      t("General Ledger", "Libri kryesor"),
      "accounting.journal.view",
    ],
    [
      "accounts",
      BookOpen,
      t("Accounts", "Llogaritë"),
      "accounting.journal.view",
    ],
    ["ar", Users, t("Receivables", "Të arkëtueshmet"), "debts.view"],
    [
      "ap",
      Building2,
      t("Payables & bills", "Detyrimet dhe faturat"),
      "finance.view",
    ],
    [
      "bank",
      Landmark,
      t("Cash & bank", "Arka dhe banka"),
      "financial_accounts.view",
    ],
    [
      "landed",
      Scale,
      t("Landed cost", "Kostoja e importit"),
      "landed_costs.manage",
    ],
    [
      "reconciliation",
      Scale,
      t("Reconciliation", "Rakordimi"),
      "accounting.integrity.view",
    ],
    [
      "reports",
      FileChartColumn,
      t("Statements", "Pasqyrat"),
      "accounting.reports.view",
    ],
    [
      "periods",
      CalendarCheck,
      t("Month close", "Mbyllja mujore"),
      "accounting.periods.manage",
    ],
    [
      "settings",
      Settings2,
      t("Setup", "Konfigurimi"),
      "accounting.settings.manage",
    ],
  ].filter((x) => !x[3] || can(x[3]));
  const requested =
    params.get("tab") || (params.has("journal") ? "journal" : "overview");
  const tab =
    requested === "integrity"
      ? "reconciliation"
      : requested === "opening"
        ? "settings"
        : tabs.some((x) => x[0] === requested)
          ? requested
          : "overview";
  const go = (next, extra = {}) => setParams({ tab: next, ...extra });
  const value = {
    language,
    t,
    can,
    dates,
    currency,
    today,
    busy,
    refresh,
    run,
    accounts: chart.data || [],
    periods: periods.data || [],
    availableTabs: tabs.map(([id])=>id),
    go,
  };
  return (
    <FinanceContext.Provider value={value}>
      <main className="fc-page" data-density="dense" aria-busy={busy}>
        <header className="fc-header">
          <div>
            <span className="fc-eyebrow">
              AIMS / {t("ACCOUNTING", "KONTABILITETI")}
            </span>
            <h1>
              {t("Finance Control Center", "Qendra e kontrollit financiar")}
            </h1>
            <p>
              {t(
                "Company books, open obligations and the next action in one workspace.",
                "Librat e kompanisë, detyrimet e hapura dhe veprimi i radhës në një hapësirë.",
              )}
            </p>
          </div>
          <div className="fc-toolbar">
            <Field
              label={t("From", "Nga")}
              type="date"
              value={dates.from}
              onChange={(e) => setDates({ ...dates, from: e.target.value })}
            />
            <Field
              label={t("To", "Deri")}
              type="date"
              min={dates.from}
              value={dates.to}
              onChange={(e) => setDates({ ...dates, to: e.target.value })}
            />
            <button
              disabled={busy}
              onClick={() => setRefresh((x) => x + 1)}
              aria-label={t("Refresh", "Rifresko")}
            >
              <RefreshCw size={17} />
            </button>
          </div>
        </header>
        <nav
          className="fc-tabs"
          aria-label={t("Finance workspace", "Hapësira financiare")}
        >
          {tabs.map(([id, Icon, label]) => (
            <button
              key={id}
              aria-current={id === tab ? "page" : undefined}
              onClick={() => go(id)}
            >
              <Icon size={16} />
              {label}
            </button>
          ))}
        </nav>
        {notice && (
          <div
            role={notice.error ? "alert" : "status"}
            className={notice.error ? "fc-error" : "fc-success"}
          >
            {notice.text}
          </div>
        )}
        {chart.error && (
          <p role="alert" className="fc-error">
            {chart.error}
          </p>
        )}
        {periods.error && (
          <p role="alert" className="fc-error">
            {periods.error}
          </p>
        )}
        {["overview", "owner"].includes(tab) && (
          <QueryState query={summary}>
            {(data) => <ControlOverview data={data} owner={tab === "owner"} />}
          </QueryState>
        )}
        {tab === "journal" && <FinanceJournals />}
        {tab === "accounts" && <FinanceAccounts />}
        {tab === "ar" && <FinanceReceivables />}
        {tab === "ap" && <FinancePayables />}
        {tab === "bank" && <FinanceBank />}
        {tab === "landed" && <FinanceLanded />}
        {tab === "reports" && <FinanceReports />}
        {tab === "periods" && <FinancePeriods />}
        {tab === "reconciliation" && (
          <QueryState query={summary}>
            {(data) => <Reconciliation data={data} />}
          </QueryState>
        )}
        {tab === "settings" && (
          <AccountingSetup
            accounts={chart.data || []}
            run={run}
            refresh={refresh}
          />
        )}
        {tab === "reconciliation" && <FinanceRecovery />}
      </main>
    </FinanceContext.Provider>
  );
}

function ControlOverview({ data, owner }) {
  const { t, go, can, availableTabs } = useFinance();
  const labels = {
    cash: ["Cash", "Arka", "bank"],
    bank: ["Bank", "Banka", "bank"],
    accounts_receivable: ["Customers owe us", "Borxhet e klientëve", "ar"],
    customer_advances: ["Customer advances", "Paradhëniet e klientëve", "ar"],
    accounts_payable: ["We owe suppliers", "Detyrimet ndaj furnitorëve", "ap"],
    supplier_advances: ["Supplier advances", "Paradhëniet e furnitorëve", "ap"],
    inventory_value: [
      "Inventory value",
      "Vlera e inventarit",
      "reconciliation",
    ],
    revenue: ["Revenue", "Të ardhurat", "reports"],
    gross_profit: ["Gross profit", "Fitimi bruto", "reports"],
    expenses: ["Operating expenses", "Shpenzimet operative", "reports"],
    net_profit: ["Net result", "Rezultati neto", "reports"],
  };
  const requestedNext=data.integrity_status==='BLOCKED'?'reconciliation':data.attention.length?new URLSearchParams(data.attention[0].url.split('?')[1]).get('tab')||'reconciliation':'periods';
  const nextTab=({integrity:'reconciliation',opening:'settings'})[requestedNext]||requestedNext;
  const canContinue=availableTabs.includes(nextTab);
  return (
    <>
      <NextStep title={data.integrity_status==='BLOCKED'?t('Investigate accounting differences','Heto diferencat kontabël'):data.attention.length?t('Review financial exceptions','Shqyrto përjashtimet financiare'):t('Review month close','Shqyrto mbylljen mujore')} tone={data.attention.length?'attention':'complete'} action={canContinue?<button onClick={()=>go(nextTab)}>{data.integrity_status==='BLOCKED'?t('Review reconciliation','Shqyrto rakordimin'):data.attention.length?t('Review first issue','Shqyrto çështjen e parë'):t('Open month close','Hap mbylljen mujore')}</button>:can('tasks.view')?<Link to="/action-center?area=finance">{t('Open finance attention','Hap çështjet financiare')}</Link>:null}>
        {data.integrity_status==='BLOCKED'?t('One or more control balances differ. Open reconciliation and follow the source links before closing.','Një ose më shumë llogari kontrolli kanë diferenca. Hap rakordimin dhe ndiq burimin para mbylljes.'):data.attention.length?t('Review the prioritized exceptions below. Posted balances and the open accounting period are shown here.','Shqyrto përjashtimet sipas përparësisë më poshtë. Gjendjet e postuara dhe periudha e hapur shfaqen këtu.'):t('No configured finance exception is currently detected. Complete the period checklist before closing.','Nuk u zbulua përjashtim financiar. Plotëso listën e kontrollit para mbylljes.')}
      </NextStep>
      <div className="fc-statusbar">
        <span>
          <Badge
            value={data.integrity_status}
            good={data.integrity_status === "READY"}
          />{" "}
          {t("Books balanced", "Librat balancohen")}:{" "}
          <b>{data.books_balanced ? t("Yes", "Po") : t("No", "Jo")}</b>
        </span>
        <span>
          {t("Current period", "Periudha aktuale")}:{" "}
          <b>
            {data.current_period?.name ||
              t("No period configured", "Nuk është konfiguruar periudhë")}
          </b>{" "}
          <Badge value={data.current_period?.status} />
        </span>
      </div>
      <p className="fc-caption">
        {t(
          "Posted General Ledger balances through",
          "Gjendjet e librit kryesor të postuar deri më",
        )}{" "}
        {data.to}.{" "}
        {t(
          "Revenue and results use the selected date range.",
          "Të ardhurat dhe rezultati përdorin intervalin e zgjedhur.",
        )}
      </p>
      <div className="fc-inline-totals fc-summary-counts">
        <button onClick={() => go("bank")}>
          {t("Unreconciled bank rows", "Rreshtat bankarë të parakorduar")}:{" "}
          {data.counts.unreconciled_bank_items}
        </button>
        <button onClick={() => go("ap")}>
          {t(
            "Supplier bills awaiting review",
            "Faturat e furnitorëve në pritje të shqyrtimit",
          )}
          : {data.counts.unreviewed_supplier_bills}
        </button>
        <button onClick={() => go("journal")}>
          {t("Draft journals", "Ditarët draft")}: {data.counts.draft_journals}
        </button>
        <button disabled={!availableTabs.includes("periods")} onClick={() => go("periods")}>
          {t("Review month close", "Shqyrto mbylljen mujore")} ↗
        </button>
      </div>
      <div className="fc-metrics">
        {Object.entries(labels)
          .filter(
            ([key]) =>
              !owner ||
              !["customer_advances", "supplier_advances"].includes(key),
          )
          .map(([key, label]) => (
            <button
              key={key}
              className="fc-metric"
              disabled={!availableTabs.includes(label[2])}
              onClick={() => go(label[2])}
            >
              <span>{t(label[0], label[1])}</span>
              <strong>
                <Amount value={data.summary[key]} />
              </strong>
              <small>{t("View detail", "Shiko hollësitë")} ↗</small>
            </button>
          ))}
      </div>
      <div className="fc-two">
        <section className="fc-panel">
          <h2>{t("Requires attention", "Kërkon vëmendje")}</h2>
          {data.attention.length ? (
            <ul className="fc-attention">
              {data.attention.map((a, i) => (
                <li key={`${a.code}-${i}`}>
                  <Badge
                    value={a.severity}
                    good={a.severity === "critical" ? false : undefined}
                  />
                  <div>
                    <b>{a.label}</b>
                    {a.difference != null && (
                      <small>
                        <Amount value={a.difference} />
                      </small>
                    )}
                    {a.amount != null && (
                      <small>
                        <Amount value={a.amount} />
                      </small>
                    )}
                  </div>
                  <Link to={a.url}>{a.count || 1} ↗</Link>
                </li>
              ))}
            </ul>
          ) : (
            <p>
              {t(
                "No finance exception was detected by the configured controls.",
                "Kontrollet e konfiguruara nuk zbuluan përjashtime financiare.",
              )}
            </p>
          )}
        </section>
        <section className="fc-panel">
          <h2>{t("Working capital", "Kapitali qarkullues")}</h2>
          {Object.entries(data.working_capital)
            .filter(([key]) => key !== "explanation")
            .map(([key, amount]) => (
              <div className="fc-total" key={key}>
                <span>
                  {t(
                    {
                      cash_bank: "Cash + bank",
                      receivables: "Receivables",
                      inventory: "Inventory",
                      payables: "Less payables",
                      total: "Working capital",
                      customer_advances: "Customer advances",
                      supplier_advances: "Supplier advances",
                    }[key],
                    {
                      cash_bank: "Arka + banka",
                      receivables: "Të arkëtueshmet",
                      inventory: "Inventari",
                      payables: "Minus detyrimet",
                      total: "Kapitali qarkullues",
                      customer_advances: "Paradhëniet e klientëve",
                      supplier_advances: "Paradhëniet e furnitorëve",
                    }[key],
                  )}
                </span>
                <Amount value={amount} />
              </div>
            ))}
          <p className="fc-caption">
            {t(
              "Advances are shown separately and remain their own assets or liabilities.",
              "Paradhëniet shfaqen veçmas si aktive ose detyrime.",
            )}
          </p>
        </section>
      </div>
      <section className="fc-panel">
        <h2>
          {t("Cash outlook · advisory", "Parashikimi i mjeteve · këshillues")}
        </h2>
        {data.cash_outlook?.state === "ready" ? (
          <>
            <div className="fc-inline-totals">
              <span>
                {t("Expected collections", "Arkëtimet e pritshme")}{" "}
                <Amount value={data.cash_outlook.expected_collections} />
              </span>
              <span>
                {t("Scheduled outflows", "Daljet e planifikuara")}{" "}
                <Amount value={data.cash_outlook.scheduled_outflows} />
              </span>
              <span>
                {t("Expected closing cash", "Mjetet e pritshme në fund")}{" "}
                <Amount value={data.cash_outlook.expected_closing_cash} />
              </span>
            </div>
            <p className="fc-caption">
              {data.cash_outlook.qualification}{" "}
              {data.cash_outlook.stale &&
                t(
                  "Refresh the forecast before relying on it.",
                  "Rifreskoni parashikimin para përdorimit.",
                )}
            </p>
          </>
        ) : (
          <p>
            {t(
              "No authorized cash forecast is available yet.",
              "Ende nuk ka parashikim të autorizuar të mjeteve.",
            )}
          </p>
        )}
        <Link to="/financial-intelligence">
          {t("Open Financial Intelligence", "Hap inteligjencën financiare")} ↗
        </Link>
      </section>
      {!owner && (
        <section className="fc-panel">
          <h2>
            {t(
              "Configured tax control balances",
              "Gjendjet e llogarive tatimore të konfiguruara",
            )}
          </h2>
          <div className="fc-inline-totals">
            <span>
              {t("Input tax control", "Llogaria e tatimit hyrës")}{" "}
              <Amount value={data.tax_controls.input_vat} />
            </span>
            <span>
              {t("Output tax control", "Llogaria e tatimit dalës")}{" "}
              <Amount value={data.tax_controls.output_vat} />
            </span>
          </div>
          <p className="fc-caption">{data.tax_controls.qualification}</p>
          {can('tax.view')&&<Link to="/tax">VAT reconciliation / Rakordimi i TVSH ↗</Link>}{' '}
          {can('fiscalization.view')&&<Link to="/fiscalization">Fiscalization / Fiskalizimi ↗</Link>}
        </section>
      )}
      {can("accounting.settings.manage") && (
        <button onClick={() => go("settings")}>
          {t(
            "Accounting setup and mappings",
            "Konfigurimi dhe lidhjet kontabël",
          )}
        </button>
      )}
    </>
  );
}
function Reconciliation({ data }) {
  const { dates, refresh } = useFinance();
  const query = useFinanceQuery(
    () => api.getAccountingReconciliation({ to: dates.to }),
    [dates.to, refresh],
  );
  return (
    <QueryState query={query}>
      {(r) => (
        <ReconciliationDetail
          data={{ ...data, reconciliation: r, reconciliation_basis: r.basis }}
        />
      )}
    </QueryState>
  );
}
function ReconciliationDetail({ data }) {
  const { t } = useFinance();
  return (
    <section className="fc-panel">
      <h2>{t("Subledgers ↔ General Ledger", "Nënlibrat ↔ libri kryesor")}</h2>
      <p className="fc-caption">{data.reconciliation_basis}</p>
      <div className="fc-table-scroll">
        <table>
          <thead>
            <tr>
              {[
                "Subsystem",
                "Operational total",
                "GL total",
                "Difference",
                "Status",
                "",
              ].map((x) => (
                <th key={x}>{t(x)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {Object.values(data.reconciliation.rows).map((r) => (
              <tr key={r.control}>
                <td>
                  {r.control.replaceAll("_", " ")}
                  {r.evidence_issues?.map((issue, i) => (
                    <small key={i}>{issue}</small>
                  ))}
                </td>
                <td className="num">
                  <Amount value={r.operational_amount} />
                </td>
                <td className="num">
                  <Amount value={r.gl_amount} />
                </td>
                <td className="num">
                  <Amount value={r.difference} />
                </td>
                <td>
                  <Badge value={r.status} good={r.reconciled} />
                </td>
                <td>
                  <Link to={r.drilldown_url}>
                    {t("Investigate", "Hetoni")} ↗
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <h3>{t("Integrity exceptions", "Përjashtimet e integritetit")}</h3>
      {data.attention
        .filter((x) => x.severity === "critical")
        .map((x) => (
          <p key={x.code}>
            <Badge value="critical" good={false} /> {x.label} ({x.count}){" "}
            <Link to={x.url}>{t("Review", "Shqyrto")}</Link>
          </p>
        ))}
      <p className="fc-caption">
        {t(
          "Investigate source records and use audited corrections. Differences cannot be cleared by editing a balance.",
          "Hetoni burimet dhe përdorni korrigjime të audituara. Diferencat nuk pastrohen duke ndryshuar gjendjen.",
        )}
      </p>
    </section>
  );
}
