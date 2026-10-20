import { useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import * as api from "../../api/advancedOperations";
import EntityDocuments from "../EntityDocuments";
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
} from "./FinanceShared";

export default function FinanceLanded() {
  const { t, refresh } = useFinance();
  const [params] = useSearchParams();
  const [id, setId] = useState(params.get("landed_cost") || "");
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const list = useFinanceQuery(
    () => api.getLandedCosts({ page, status, per_page: 50 }),
    [page, status, refresh],
  );
  const detail = useFinanceQuery(
    () => (id ? api.getLandedCost(id) : Promise.resolve(null)),
    [id, refresh],
  );
  const costLabel = (key) =>
    ({
      freight: t("Freight", "Transporti"),
      customs: t("Customs", "Dogana"),
      insurance: t("Insurance", "Sigurimi"),
      port: t("Port fees", "Tarifat portuale"),
      forwarding: t("Broker / forwarding", "Agjenti / spedicioneri"),
      inland_transport: t("Inland transport", "Transporti i brendshëm"),
      handling: t("Handling", "Trajtimi"),
      other: t("Other costs", "Kosto të tjera"),
    })[key] || key;
  return (
    <>
      <section className="fc-panel">
        <div className="fc-section-head">
          <h2>
            {t("Landed-cost accounting", "Kontabiliteti i kostos së importit")}
          </h2>
          <Link to="/operations-center?tab=landed">
            {t(
              "Prepare / post landed cost",
              "Përgatit / regjistro koston e importit",
            )}
          </Link>
        </div>
        <Field label={t("Status", "Statusi")}>
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">{t("All", "Të gjitha")}</option>
            {["draft", "posted", "reversed"].map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </Field>
        <QueryState query={list}>
          {(d) => (
            <>
              {d.data.length ? (
                <MoneyTable label={t("Landed costs", "Kostot e importit")}>
                  <thead>
                    <tr>
                      <th>{t("Reference", "Referenca")}</th>
                      <th>{t("Receipt / PO", "Pranimi / porosia")}</th>
                      <th>{t("Cost / method", "Kostoja / metoda")}</th>
                      <th>{t("Status", "Statusi")}</th>
                      <th className="num">{t("Base cost", "Kostoja bazë")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.data.map((c) => (
                      <tr key={c.id}>
                        <td>
                          <button
                            className="fc-link"
                            onClick={() => setId(c.id)}
                          >
                            {c.reference_number || `#${c.id}`}
                          </button>
                        </td>
                        <td>
                          {c.goods_receipt?.receipt_number || "—"}
                          <small>{c.purchase_order?.po_number}</small>
                        </td>
                        <td>
                          {costLabel(c.cost_type)}
                          <small>{c.allocation_method}</small>
                        </td>
                        <td>
                          <Badge value={c.status} />
                        </td>
                        <td className="num">
                          <Amount
                            value={c.base_currency_amount}
                            currency={c.base_currency}
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
              ) : (
                <Empty />
              )}
              <Pager page={d} onPage={setPage} />
            </>
          )}
        </QueryState>
      </section>
      {id && (
        <QueryState query={detail}>
          {(c) =>
            c && (
              <section className="fc-panel">
                <div className="fc-section-head">
                  <h2>
                    {c.reference_number || `#${c.id}`} ·{" "}
                    {costLabel(c.cost_type)}
                  </h2>
                  <button onClick={() => setId("")}>
                    {t("Close detail", "Mbyll hollësitë")}
                  </button>
                </div>
                <p>
                  {c.goods_receipt?.receipt_number} ·{" "}
                  {c.purchase_order?.po_number || "—"} ·{" "}
                  {c.shipment?.tracking_number || "—"} ·{" "}
                  <DateValue value={c.posted_at} /> · <Badge value={c.status} />
                </p>
                {c.source_expense_id&&<p><Link to={'/finance?expense='+c.source_expense_id}>{t('Source bill','Fatura burimore')} #{c.source_expense_id}</Link> · {t('Recoverable VAT excluded','TVSH e zbritshme e përjashtuar')}: <Amount value={c.tax_basis_snapshot?.deductible_vat_eur}/></p>}
                <div className="fc-inline-totals">
                  <span>
                    {t("Goods value", "Vlera e mallrave")}{" "}
                    <Amount
                      value={c.accounting_presentation.goods_value}
                      currency={c.base_currency}
                    />
                  </span>
                  <span>
                    {t(
                      "Posted import costs",
                      "Kostot e regjistruara të importit",
                    )}{" "}
                    <Amount
                      value={c.accounting_presentation.posted_landed_cost_total}
                      currency={c.base_currency}
                    />
                  </span>
                  <span>
                    {t(
                      "Total receipt cost basis",
                      "Baza e plotë e kostos së pranimit",
                    )}{" "}
                    <Amount
                      value={c.accounting_presentation.total_receipt_basis}
                      currency={c.base_currency}
                    />
                  </span>
                </div>
                <div className="fc-inline-totals">
                  {c.accounting_presentation.posted_costs.map((r) => (
                    <span key={r.cost_type}>
                      {costLabel(r.cost_type)}{" "}
                      <Amount value={r.amount} currency={c.base_currency} />
                    </span>
                  ))}
                </div>
                <p className="fc-caption">
                  {t("Allocation method", "Metoda e alokimit")}:{" "}
                  {c.allocation_method}.{" "}
                  {c.accounting_presentation.qualification}
                </p>
                <MoneyTable
                  label={t("Allocation evidence", "Provat e alokimit")}
                >
                  <thead>
                    <tr>
                      <th>{t("Product", "Produkti")}</th>
                      <th>{t("Basis", "Baza")}</th>
                      <th className="num">
                        {t("Goods unit cost", "Kostoja njësore e mallit")}
                      </th>
                      <th className="num">
                        {t("This allocation", "Ky alokim")}
                      </th>
                      <th className="num">
                        {t(
                          "Unit cost after allocation",
                          "Kostoja njësore pas alokimit",
                        )}
                      </th>
                      <th className="num">
                        {t(
                          "Inventory / COGS posting",
                          "Regjistrimi në inventar / KMSH",
                        )}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {c.allocations.map((a) => (
                      <tr key={a.id}>
                        <td>
                          {a.product?.name}
                          <small>{a.product?.sku}</small>
                        </td>
                        <td>{a.allocation_basis}</td>
                        <td className="num">
                          {Number(
                            a.base_purchase_unit_cost_snapshot || 0,
                          ).toFixed(6)}
                        </td>
                        <td className="num">
                          <Amount
                            value={a.allocated_amount}
                            currency={c.base_currency}
                          />
                        </td>
                        <td className="num">
                          {Number(a.final_unit_cost || 0).toFixed(6)}
                        </td>
                        <td className="num">
                          <Amount
                            value={
                              a.accounting_entry?.inventory_adjustment_amount ||
                              0
                            }
                            currency={c.base_currency}
                          />{" "}
                          /{" "}
                          <Amount
                            value={
                              a.accounting_entry?.cogs_adjustment_amount || 0
                            }
                            currency={c.base_currency}
                          />
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </MoneyTable>
                <Link to={`/operations-center?tab=landed&landed_cost=${c.id}`}>
                  {t(
                    "Open receipt and operational cost record",
                    "Hap pranimin dhe regjistrimin operacional të kostos",
                  )}
                </Link>
                <EntityDocuments entityType="landed-cost" entityId={c.id} />
              </section>
            )
          }
        </QueryState>
      )}
    </>
  );
}
