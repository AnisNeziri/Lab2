import {
  cloneElement,
  createContext,
  useContext,
  useEffect,
  useId,
  useState,
} from "react";
import { Link } from "react-router-dom";
import { businessDate, businessMoney } from "../../utils/businessFormat";
import { getFinanceParties } from "../../api/accounting";

export const FinanceContext = createContext(null);
export const useFinance = () => useContext(FinanceContext);
export const dateOnly = (value) => (value ? String(value).slice(0, 10) : "—");
export const errorMessage = (e) =>
  e?.errors
    ? Object.values(e.errors).flat().join(" ")
    : e?.message || "Unable to complete this action.";
export function localToday(timeZone) {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: timeZone || Intl.DateTimeFormat().resolvedOptions().timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}
export function useFinanceQuery(fetcher, dependencies) {
  const queryKey = JSON.stringify(dependencies);
  const pending = { key: queryKey, data: null, loading: true, error: "" };
  const [state, setState] = useState(pending);
  useEffect(() => {
    let active = true;
    setState(pending);
    Promise.resolve()
      .then(fetcher)
      .then((data) => {
        if (active)
          setState({ key: queryKey, data, loading: false, error: "" });
      })
      .catch((e) => {
        if (active)
          setState({
            key: queryKey,
            data: null,
            loading: false,
            error: errorMessage(e),
          });
      });
    return () => {
      active = false;
    };
  }, dependencies);
  // A tab/date/entity can change the response shape before effects run.
  // Never render the previous query's payload as the new query's data.
  return state.key === queryKey ? state : pending;
}
export function QueryState({ query, children }) {
  const { t } = useFinance();
  if (query.loading)
    return (
      <p role="status">
        {t("Loading financial records…", "Duke ngarkuar të dhënat financiare…")}
      </p>
    );
  if (query.error)
    return (
      <p className="fc-error" role="alert">
        {query.error}
      </p>
    );
  return children(query.data);
}
export function Amount({ value, currency, plain = false }) {
  const f = useFinance();
  return (
    <span className={`fc-amount ${Number(value) < 0 ? "fc-negative" : ""}`}>
      {businessMoney(value, plain ? "" : currency || f.currency, f.language)}
    </span>
  );
}
export function DateValue({ value, time = false }) {
  const { language } = useFinance();
  return businessDate(value, language, time);
}
export function Badge({ value, good }) {
  return (
    <span
      className={`fc-badge ${good === true ? "fc-good" : good === false ? "fc-bad" : ""}`}
    >
      {String(value || "—").replaceAll("_", " ")}
    </span>
  );
}
export function Field({ label, children, ...input }) {
  const id = useId();
  return (
    <div className="fc-field">
      <label htmlFor={id}>{label}</label>
      {children ? cloneElement(children, { id }) : <input id={id} {...input} />}
    </div>
  );
}
export function Pager({ page, onPage }) {
  const { t, busy } = useFinance();
  if (!page || page.last_page < 2) return null;
  return (
    <div className="fc-pager">
      <span>
        {t("Page", "Faqja")} {page.current_page} / {page.last_page} ·{" "}
        {page.total} {t("records", "rreshta")}
      </span>
      <button
        disabled={busy || page.current_page <= 1}
        onClick={() => onPage(page.current_page - 1)}
      >
        {t("Previous", "Para")}
      </button>
      <button
        disabled={busy || page.current_page >= page.last_page}
        onClick={() => onPage(page.current_page + 1)}
      >
        {t("Next", "Pas")}
      </button>
    </div>
  );
}
export function CheckList({ checks }) {
  const { t } = useFinance();
  return (
    <ul className="fc-checklist">
      {checks?.map((c) => (
        <li key={c.code} className={c.passed ? "" : "fc-check-failed"}>
          <span
            aria-label={
              c.passed ? t("Passed", "Kaluar") : t("Review", "Shqyrto")
            }
          >
            {c.passed ? "✓" : "!"}
          </span>
          <div>
            <b>{c.label}</b>
            {c.count > 0 && (
              <small>
                {c.count} {t("items", "çështje")}
              </small>
            )}
            {c.difference != null && (
              <small>
                {t("Difference", "Diferenca")}: <Amount value={c.difference} />
              </small>
            )}
            {c.detail && <small>{c.detail}</small>}
            {c.required === false && (
              <small>
                {t(
                  "Review; does not block closing",
                  "Shqyrtim; nuk bllokon mbylljen",
                )}
              </small>
            )}
          </div>
          {!c.passed && c.url && (
            <Link to={c.url}>{t("Investigate", "Hetoni")}</Link>
          )}
        </li>
      ))}
    </ul>
  );
}
export function MoneyTable({ children, label }) {
  return (
    <div
      className="fc-table-scroll"
      tabIndex={0}
      role="region"
      aria-label={label}
    >
      <table>{children}</table>
    </div>
  );
}
export function Empty({ children }) {
  const { t } = useFinance();
  return (
    <p className="fc-empty">
      {children ||
        t(
          "No records for this selection.",
          "Nuk ka të dhëna për këtë përzgjedhje.",
        )}
    </p>
  );
}
export function PartySelect({ type, value, onChange, required = false }) {
  const { t } = useFinance(),
    [search, setSearch] = useState(""),
    [term, setTerm] = useState("");
  useEffect(() => {
    const timer = setTimeout(() => setTerm(search), 250);
    return () => clearTimeout(timer);
  }, [search]);
  const query = useFinanceQuery(
    () => getFinanceParties(type, term),
    [type, term],
  );
  return (
    <div className="fc-party">
      <Field
        label={
          type === "customer"
            ? t("Find customer", "Gjej klientin")
            : t("Find supplier", "Gjej furnitorin")
        }
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        placeholder={t(
          "Search name · first 30 matches",
          "Kërko emrin · 30 rezultatet e para",
        )}
      />
      <Field label={t("Selected party", "Pala e zgjedhur")}>
        <select
          required={required}
          value={value || ""}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{t("All / select", "Të gjithë / zgjidh")}</option>
          {value &&
            !query.data?.some((p) => String(p.id) === String(value)) && (
              <option value={value}>#{value}</option>
            )}
          {query.data?.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name}
            </option>
          ))}
        </select>
      </Field>
      {query.error && (
        <p className="fc-error" role="alert">
          {query.error}
        </p>
      )}
    </div>
  );
}
