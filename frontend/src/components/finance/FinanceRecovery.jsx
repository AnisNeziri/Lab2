import { useState } from "react";
import * as api from "../../api/accounting";
import WorkspaceModal from "../WorkspaceModal";
import {
  Badge,
  Field,
  Pager,
  QueryState,
  useFinance,
  useFinanceQuery,
} from "./FinanceShared";

export default function FinanceRecovery() {
  const { t, refresh, can, run, busy, accounts } = useFinance(),
    [page, setPage] = useState(1),
    [selected, setSelected] = useState(null),
    [counter, setCounter] = useState("");
  const query = useFinanceQuery(
    () => api.getAccountingExceptions({ page }),
    [page, refresh],
  );
  const supported = [
    "daily_sale",
    "invoice",
    "stock_movement",
    "landed_cost",
    "expense",
    "financial_account_transaction",
  ];
  return (
    <section className="fc-panel">
      <div className="fc-section-head">
        <h2>
          {t("Source posting exceptions", "Përjashtimet e postimit burimor")}
        </h2>
        <button
          disabled={busy}
          onClick={() =>
            run(
              api.getAccountingIntegrity,
              t(
                "Source exceptions scanned. Existing financial history was preserved.",
                "Përjashtimet burimore u kontrolluan. Historiku financiar u ruajt.",
              ),
            )
          }
        >
          {t(
            "Scan source posting exceptions",
            "Kontrollo përjashtimet burimore",
          )}
        </button>
      </div>
      <p className="fc-caption">
        {t(
          "Recovery retries a supported source posting under the original period and company rules. Control mismatches require investigation and an explicit correction.",
          "Rikuperimi riprovon postimin burimor sipas periudhës dhe kompanisë origjinale. Diferencat kërkojnë hetim dhe korrigjim të shprehur.",
        )}
      </p>
      <QueryState query={query}>
        {(d) => (
          <>
            {d.data.map((e) => (
              <article className="fc-audit" key={e.id}>
                <b>
                  <Badge
                    value={e.severity}
                    good={e.severity === "critical" ? false : undefined}
                  />{" "}
                  {e.message}
                </b>
                {e.details?.source_id && (
                  <small>
                    {t("Source record", "Regjistrimi burimor")} #
                    {e.details.source_id}
                  </small>
                )}
                {e.attempts?.map((a) => (
                  <small key={a.id}>
                    {a.user?.name} · {a.status} · {a.message}
                  </small>
                ))}
                {can("accounting.integrity.retry") &&
                  supported.includes(e.details?.source_type) &&
                  e.details?.recoverable !== false && (
                    <button
                      disabled={busy}
                      onClick={() => {
                        setCounter("");
                        setSelected(e);
                      }}
                    >
                      {t(
                        "Review source posting recovery",
                        "Shqyrto rikuperimin e postimit burimor",
                      )}
                    </button>
                  )}
              </article>
            ))}
            {!d.data.length && (
              <p>
                {t(
                  "No open source posting exceptions are recorded.",
                  "Nuk ka përjashtime të hapura të regjistruara.",
                )}
              </p>
            )}
            <Pager page={d} onPage={setPage} />
          </>
        )}
      </QueryState>
      {selected && (
        <WorkspaceModal
          title={t("Review posting recovery", "Shqyrto rikuperimin e postimit")}
          busy={busy}
          onClose={() => setSelected(null)}
        >
          <form
            className="fc-form"
            onSubmit={async (e) => {
              e.preventDefault();
              const result = await run(() =>
                api.retryAccountingException(
                  selected.id,
                  counter
                    ? { counter_accounting_account_id: Number(counter) }
                    : {},
                ),
              );
              if (result) setSelected(null);
            }}
          >
            <p>{selected.message}</p>
            {selected.details?.source_type ===
              "financial_account_transaction" && (
              <Field
                label={t(
                  "Reviewed counter account",
                  "Llogaria përkatëse e shqyrtuar",
                )}
              >
                <select
                  required
                  value={counter}
                  onChange={(e) => setCounter(e.target.value)}
                >
                  <option value="">
                    {t("Select account", "Zgjidh llogarinë")}
                  </option>
                  {accounts
                    .filter((a) => a.is_active && a.is_posting)
                    .map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.code} · {a.name}
                      </option>
                    ))}
                </select>
              </Field>
            )}
            <p>
              {t(
                "A closed period remains blocked. Missing source evidence must be corrected before recovery can succeed.",
                "Periudha e mbyllur mbetet e bllokuar. Provat që mungojnë duhet të korrigjohen para rikuperimit.",
              )}
            </p>
            <button disabled={busy}>
              {t(
                "Retry reviewed source posting",
                "Riprovo postimin burimor të shqyrtuar",
              )}
            </button>
          </form>
        </WorkspaceModal>
      )}
    </section>
  );
}
