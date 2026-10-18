import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { getSourceJournals } from "../api/accounting";
import { useAuthStore } from "../store/authStore";
import { useSettingsStore } from "../store/settingsStore";
import { businessDate, businessMoney } from "../utils/businessFormat";

const sources = new Set([
  "invoice",
  "return",
  "payment",
  "expense",
  "customer",
  "purchase-order",
  "goods-receipt",
  "daily-sale",
  "customer-transaction",
  "supplier-payment",
  "supplier-allocation",
  "landed-cost",
  "stock-movement",
  "financial-account",
  "financial-transaction",
  "financial-transfer",
]);
export default function EntityJournals({ entityType, entityId }) {
  const can = useAuthStore((s) =>
      s.permissions.includes("accounting.journal.view"),
    ),
    language = useSettingsStore((s) => s.language);
  const [data, setData] = useState([]),
    [error, setError] = useState("");
  useEffect(() => {
    let active = true;
    setData([]);
    setError("");
    if (can && sources.has(entityType) && entityId)
      getSourceJournals(entityType, entityId)
        .then((rows) => {
          if (active) setData(rows);
        })
        .catch((e) => {
          if (active) setError(e.message);
        });
    return () => {
      active = false;
    };
  }, [can, entityType, entityId]);
  if (!can || !sources.has(entityType)) return null;
  return (
    <section className="entity-context">
      <h3>{language === "sq" ? "Ditarët e lidhur" : "Linked journals"}</h3>
      {error && <p role="alert">{error}</p>}
      {data.map((j) => (
        <p key={j.id}>
          <Link to={j.url}>{j.journal_number}</Link> ·{" "}
          {businessDate(j.posting_date, language)} · {j.source_label} ·{" "}
          {j.status} · {businessMoney(j.total_debit, "", language)}
        </p>
      ))}
      {data.length === 20 && (
        <p>
          {language === "sq"
            ? "20 ditarët më të fundit. Përdorni filtrat e librit kryesor për historikun e plotë."
            : "Latest 20 linked journals. Use General Ledger filters for the complete history."}
        </p>
      )}
      {!error && !data.length && (
        <p>
          {language === "sq"
            ? "Nuk ka ditarë të lidhur të postuar."
            : "No linked journals found."}
        </p>
      )}
    </section>
  );
}
