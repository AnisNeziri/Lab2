import { useEffect, useState } from "react";
import TaxCodePicker,{useTaxCodes} from "./TaxCodePicker";
import WorkspaceModal from "../WorkspaceModal";
import * as api from "../../api/advancedOperations";
import { recordExpensePayment } from "../../api/finance";
import { getPurchaseOrders, getPurchaseOrder } from "../../api/purchaseOrders";
import {
  Amount,
  Field,
  PartySelect,
  QueryState,
  useFinance,
  useFinanceQuery,
  dateOnly,
} from "./FinanceShared";

export default function FinanceBillForm({ original, onClose, onSaved }) {
  const { t, today, busy, run } = useFinance();
  const {codes:taxCodes,error:taxCodeError}=useTaxCodes();
  const [form, setForm] = useState(() => ({
    tax_code_id:original?.tax_code_id||"",
    supplier_id: original?.supplier_id || "",
    purchase_order_id: original?.purchase_order_id || "",
    vendor_name: original?.vendor_name || "",
    vendor_vat_number: original?.vendor_vat_number || "",
    vendor_business_number: original?.vendor_business_number || "",
    tax_legal_reference: original?.tax_legal_reference || "",
    document_number: original?.document_number || "",
    invoice_date: dateOnly(original?.invoice_date || today),
    received_date: dateOnly(original?.received_date || today),
    due_date: original?.due_date ? dateOnly(original.due_date) : "",
    currency: original?.currency || "EUR",
    exchange_rate: original?.exchange_rate || "",
    exchange_rate_date: original?.exchange_rate_date
      ? dateOnly(original.exchange_rate_date)
      : today,
    exchange_rate_source: original?.exchange_rate_source || "",
    category: original?.category || "professional_services",
    business_purpose: original?.business_purpose || "",
    vat_rate: Number(original?.vat_rate || 0),
    vat_treatment: original?.vat_treatment || "non_vat",
    input_vat_eligible: original?.input_vat_eligible || false,
    notes: original?.notes || "",
    items: original?.supplier_invoice_items?.map((l) => ({ ...l })) || [
      { description: "", quantity: 1, unit: "service", unit_price: "" },
    ],
    goods_receipt_ids: original?.goods_receipts?.map((r) => r.id) || [],
  }));
  const [dirty, setDirty] = useState(false);
  const orders = useFinanceQuery(
    () =>
      form.supplier_id
        ? getPurchaseOrders({ supplier_id: form.supplier_id, per_page: 50 })
        : { data: [] },
    [form.supplier_id],
  );
  const order = useFinanceQuery(
    () =>
      form.purchase_order_id ? getPurchaseOrder(form.purchase_order_id) : null,
    [form.purchase_order_id],
  );
  const [adopted, setAdopted] = useState(
    String(original?.purchase_order_id || ""),
  );
  useEffect(() => {
    const p = order.data;
    if (!p || String(p.id) === adopted) return;
    setAdopted(String(p.id));
    const receipts = p.goods_receipts || [];
    setForm((v) => ({
      ...v,
      category: "inventory",
      currency: p.currency,
      goods_receipt_ids: receipts.map((r) => r.id),
      items: p.items.map((l) => ({
        purchase_order_item_id: l.id,
        goods_receipt_item_id:
          receipts
            .flatMap((r) => r.items || [])
            .find((i) => i.purchase_order_item_id === l.id)?.id || null,
        product_id: l.product_id,
        description: l.description,
        quantity: l.quantity,
        unit: l.unit,
        unit_price: l.unit_price,
      })),
    }));
  }, [order.data, adopted]);
  const field = (key, value) => {
    setDirty(true);
    setForm({ ...form, [key]: value });
  };
  const line = (i, key, value) =>
    field(
      "items",
      form.items.map((l, n) => (n === i ? { ...l, [key]: value } : l)),
    );
  const net =
    form.items.reduce(
      (sum, l) =>
        sum +
        Math.round(Number(l.quantity || 0) * Number(l.unit_price || 0) * 100),
      0,
    ) / 100;
  const tax = form.vat_treatment==='reverse_charge'?0:
    form.items.reduce(
      (sum, l) =>
        sum +
        Math.round(
          Number(l.quantity || 0) *
            Number(l.unit_price || 0) *
            Number(form.vat_rate),
        ),
      0,
    ) / 100;
  const submit = async (e) => {
    e.preventDefault();
    const p = {
      ...form,
      tax_code_id:form.tax_code_id?Number(form.tax_code_id):null,
      supplier_id: Number(form.supplier_id),
      purchase_order_id: form.purchase_order_id
        ? Number(form.purchase_order_id)
        : null,
      vendor_name:
        form.vendor_name ||
        order.data?.supplier?.name ||
        `Supplier #${form.supplier_id}`,
      document_type: "purchase_invoice",
      source_type: "domestic",
      asset_treatment: "ordinary",
      description: form.items.map((l) => l.description).join("; "),
      business_purpose:
        form.business_purpose || (form.category === "inventory" ? null : ""),
      net_amount: net,
      vat_amount: tax,
      due_date: form.due_date || null,
      exchange_rate: form.currency === "EUR" ? 1 : form.exchange_rate,
      items: form.items.map((l) => ({
        description: l.description,
        quantity: l.quantity,
        unit: l.unit,
        unit_price: l.unit_price,
        vat_rate: form.vat_treatment==='reverse_charge'?0:form.vat_rate,
        product_id: l.product_id || null,
        purchase_order_item_id: l.purchase_order_item_id || null,
        goods_receipt_item_id: l.goods_receipt_item_id || null,
      })),
    };
    const result = await run(
      () =>
        original
          ? api.updateSupplierInvoice(original.id, p)
          : api.createSupplierInvoice(p),
      t(
        "Supplier draft saved; no money was posted.",
        "Drafti i furnitorit u ruajt; nuk u postuan mjete.",
      ),
    );
    if (result) onSaved(result.id);
  };
  return (
    <WorkspaceModal
      title={
        original
          ? t("Edit supplier draft", "Redakto draftin e furnitorit")
          : t("New supplier invoice / vendor bill", "Faturë e re e furnitorit")
      }
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={dirty}
    >
      <form className="fc-form" onSubmit={submit}>
        <PartySelect
          type="supplier"
          required
          value={form.supplier_id}
          onChange={(value) => {
            setDirty(true);
            setAdopted("");
            setForm({
              ...form,
              supplier_id: value,
              purchase_order_id: "",
              goods_receipt_ids: [],
            });
          }}
        />
        <Field
          label={t("Vendor name on document", "Emri i furnitorit në dokument")}
          value={form.vendor_name}
          maxLength={255}
          onChange={(e) => field("vendor_name", e.target.value)}
        />
        <div className="fc-form-grid">
          <Field
            label={t(
              "Supplier business number",
              "Numri i biznesit të furnitorit",
            )}
            value={form.vendor_business_number}
            maxLength={100}
            onChange={(e) => field("vendor_business_number", e.target.value)}
          />
          <Field
            label={t("Supplier VAT number", "Numri i TVSH-së së furnitorit")}
            value={form.vendor_vat_number}
            required={form.input_vat_eligible && form.vat_rate > 0}
            maxLength={100}
            onChange={(e) => field("vendor_vat_number", e.target.value)}
          />
        </div>
        <QueryState query={orders}>
          {(d) => (
            <Field
              label={t(
                "Purchase Order · optional for service bills",
                "Porosia · opsionale për shërbime",
              )}
            >
              <select
                value={form.purchase_order_id}
                onChange={(e) => {
                  setDirty(true);
                  if (!e.target.value) {
                    setAdopted("");
                    setForm({
                      ...form,
                      purchase_order_id: "",
                      category: "professional_services",
                      goods_receipt_ids: [],
                      items: [
                        {
                          description: "",
                          quantity: 1,
                          unit: "service",
                          unit_price: "",
                        },
                      ],
                    });
                  } else field("purchase_order_id", e.target.value);
                }}
              >
                <option value="">
                  {t("No PO / service bill", "Pa porosi / faturë shërbimi")}
                </option>
                {(d.data || d).map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.po_number} · {p.currency}
                  </option>
                ))}
              </select>
            </Field>
          )}
        </QueryState>
        <div className="fc-form-grid">
          <Field
            label={t("Invoice number", "Numri i faturës")}
            required
            maxLength={100}
            value={form.document_number}
            onChange={(e) => field("document_number", e.target.value)}
          />
          <Field
            label={t("Invoice date", "Data e faturës")}
            type="date"
            required
            value={form.invoice_date}
            onChange={(e) => field("invoice_date", e.target.value)}
          />
          <Field
            label={t("Received date", "Data e pranimit")}
            type="date"
            required
            min={form.invoice_date}
            value={form.received_date}
            onChange={(e) => field("received_date", e.target.value)}
          />
          <Field
            label={t(
              "Due date · leave empty if unknown",
              "Afati · lëre bosh nëse nuk dihet",
            )}
            type="date"
            min={form.invoice_date}
            value={form.due_date}
            onChange={(e) => field("due_date", e.target.value)}
          />
          <Field label={t("Currency", "Monedha")}>
            <select
              value={form.currency}
              onChange={(e) => field("currency", e.target.value)}
            >
              {["EUR", "USD", "GBP", "CHF", "ALL", "RSD", "TRY", "CNY"].map(
                (x) => (
                  <option key={x}>{x}</option>
                ),
              )}
            </select>
          </Field>
        </div>
        {form.currency !== "EUR" && (
          <div className="fc-form-grid">
            <Field
              label={t("Historical rate to EUR", "Kursi historik në EUR")}
              required
              type="number"
              step=".00000001"
              min=".00000001"
              value={form.exchange_rate}
              onChange={(e) => field("exchange_rate", e.target.value)}
            />
            <Field
              label={t("Rate date", "Data e kursit")}
              type="date"
              required
              max={form.invoice_date}
              value={form.exchange_rate_date}
              onChange={(e) => field("exchange_rate_date", e.target.value)}
            />
            <Field
              label={t(
                "Authorized rate source",
                "Burimi i autorizuar i kursit",
              )}
              required
              value={form.exchange_rate_source}
              onChange={(e) => field("exchange_rate_source", e.target.value)}
            />
          </div>
        )}
        <Field
          label={t(
            "Expense category / inventory treatment",
            "Kategoria e shpenzimit / trajtimi i inventarit",
          )}
        >
          <select
            value={form.category}
            onChange={(e) => field("category", e.target.value)}
          >
            {[
              "inventory",
              "rent",
              "utilities",
              "transport",
              "professional_services",
              "bank_fees",
              "insurance",
              "maintenance",
              "office",
              "other",
            ].map((x) => (
              <option value={x} key={x}>
                {x.replaceAll("_", " ")}
              </option>
            ))}
          </select>
        </Field>
        {form.category !== "inventory" && (
          <Field
            label={t("Business purpose", "Qëllimi i biznesit")}
            required
            maxLength={2000}
            value={form.business_purpose}
            onChange={(e) => field("business_purpose", e.target.value)}
          />
        )}
        {form.purchase_order_id && (
          <QueryState query={order}>
            {(p) =>
              p && (
                <fieldset>
                  <legend>
                    {t("Receipt evidence", "Dëshmitë e pranimit")}
                  </legend>
                  {p.goods_receipts?.map((r) => (
                    <label className="fc-check" key={r.id}>
                      <input
                        type="checkbox"
                        checked={form.goods_receipt_ids.includes(r.id)}
                        onChange={(e) =>
                          field(
                            "goods_receipt_ids",
                            e.target.checked
                              ? [...form.goods_receipt_ids, r.id]
                              : form.goods_receipt_ids.filter(
                                  (id) => id !== r.id,
                                ),
                          )
                        }
                      />
                      {r.receipt_number || `#${r.id}`} ·{" "}
                      {dateOnly(r.received_at)}
                    </label>
                  ))}
                </fieldset>
              )
            }
          </QueryState>
        )}
        {form.items.map((l, i) => (
          <fieldset className="fc-form-grid" key={i}>
            <legend>
              {t("Invoice line", "Rreshti i faturës")} {i + 1}
            </legend>
            <Field
              label={t("Description", "Përshkrimi")}
              required
              maxLength={1000}
              value={l.description}
              onChange={(e) => line(i, "description", e.target.value)}
            />
            <Field
              label={t("Invoiced quantity", "Sasia e faturuar")}
              required
              type="number"
              min=".001"
              step=".001"
              value={l.quantity}
              onChange={(e) => line(i, "quantity", e.target.value)}
            />
            <Field
              label={t("Unit", "Njësia")}
              required
              maxLength={30}
              value={l.unit}
              onChange={(e) => line(i, "unit", e.target.value)}
            />
            <Field
              label={t("Invoice unit price", "Çmimi për njësi i faturës")}
              required
              type="number"
              min="0"
              step=".0001"
              value={l.unit_price}
              onChange={(e) => line(i, "unit_price", e.target.value)}
            />
            {form.items.length > 1 && (
              <button
                type="button"
                onClick={() =>
                  field(
                    "items",
                    form.items.filter((_, n) => n !== i),
                  )
                }
              >
                {t("Remove", "Hiq")}
              </button>
            )}
          </fieldset>
        ))}
        <button
          type="button"
          onClick={() =>
            field("items", [
              ...form.items,
              { description: "", quantity: 1, unit: "service", unit_price: "" },
            ])
          }
        >
          {t("Add invoice line", "Shto rresht fature")}
        </button>
        {taxCodeError&&<p role="alert">{taxCodeError}</p>}
        <TaxCodePicker codes={taxCodes.filter(c=>c.purchase_treatment!=='import_vat')} value={form.tax_code_id} direction="purchases" date={form.invoice_date} label={t('Tax code','Kodi tatimor')} onChange={c=>{setDirty(true);setForm({...form,tax_code_id:c?.id||'',vat_rate:c?.rate||0,vat_treatment:c?.purchase_treatment||'non_vat',tax_legal_reference:c?.legal_reference||''})}}/>
        <div className="fc-form-grid">
          <Field
            label={t("Existing tax treatment", "Trajtimi tatimor ekzistues")}
          >
            <select
              disabled={Boolean(form.tax_code_id)} value={form.vat_treatment}
              onChange={(e) => {
                const value = e.target.value;
                setDirty(true);
                setForm({
                  ...form,
                  vat_treatment: value,
                  vat_rate:
                    value === "standard" ? 18 : value === "reduced" ? 8 : 0,
                });
              }}
            >
              {[
                "non_vat",
                "zero_rated",
                "exempt",
                "out_of_scope",
                "standard",
                "reduced",
              ].map((x) => (
                <option key={x}>{x}</option>
              ))}
            </select>
          </Field>
          <label className="fc-check">
            <input
              type="checkbox"
              checked={form.input_vat_eligible}
              onChange={(e) => field("input_vat_eligible", e.target.checked)}
            />
            {t(
              "Input tax eligible under configured policy",
              "Tatimi hyrës i pranueshëm sipas politikës së konfiguruar",
            )}
          </label>
        </div>
        {form.vat_treatment === "exempt" && (
          <Field
            label={t("Tax treatment basis", "Baza e trajtimit tatimor")}
            required
            maxLength={2000}
            value={form.tax_legal_reference}
            onChange={(e) => field("tax_legal_reference", e.target.value)}
          />
        )}
        <p className="fc-caption">
          {t(
            "Tax treatment uses the existing company foundation. Review it with your accountant; this is not a certified tax filing.",
            "Trajtimi tatimor përdor bazën ekzistuese të kompanisë. Shqyrtojeni me kontabilistin; kjo nuk është deklaratë tatimore e certifikuar.",
          )}
        </p>
        <Field label={t("Notes", "Shënimet")}>
          <textarea
            maxLength={5000}
            value={form.notes}
            onChange={(e) => field("notes", e.target.value)}
          />
        </Field>
        <div className="fc-inline-totals">
          <span>
            {t("Subtotal", "Nëntotali")}{" "}
            <Amount value={net} currency={form.currency} />
          </span>
          <span>
            {t("Tax", "Tatimi")} <Amount value={tax} currency={form.currency} />
          </span>
          <span>
            {t("Total", "Gjithsej")}{" "}
            <Amount value={net + tax} currency={form.currency} />
          </span>
        </div>
        <button disabled={busy || order.loading || net <= 0}>
          {t("Save supplier draft", "Ruaj draftin e furnitorit")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
export function FinancePaymentForm({ bill, onClose }) {
  const { t, today, busy, run } = useFinance();
  const [form, setForm] = useState({
    amount: "",
    payment_date: today,
    payment_method: "bank_transfer",
    financial_account_id: "",
    reference_number: "",
    note: "",
    exchange_rate: "",
    exchange_rate_date: today,
    exchange_rate_source: "",
    idempotency_key: crypto.randomUUID(),
  });
  const accounts = useFinanceQuery(api.getFinancialAccounts, []);
  const field = (k, v) => setForm({ ...form, [k]: v });
  return (
    <WorkspaceModal
      title={t("Record supplier payment", "Regjistro pagesën e furnitorit")}
      busy={busy}
      onClose={onClose}
      warnOnUnsaved={!!form.amount}
    >
      <form
        className="fc-form"
        onSubmit={async (e) => {
          e.preventDefault();
          const p = {
            ...form,
            financial_account_id: form.financial_account_id
              ? Number(form.financial_account_id)
              : null,
          };
          if (bill.currency === "EUR") {
            delete p.exchange_rate;
            delete p.exchange_rate_date;
            delete p.exchange_rate_source;
          }
          const result = await run(() => recordExpensePayment(bill.id, p));
          if (result) onClose();
        }}
      >
        <p>
          {bill.document_number} · {t("Outstanding", "Mbetja")}:{" "}
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
          label={t("Payment amount", "Shuma e pagesës")}
          type="number"
          required
          min=".01"
          step=".01"
          value={form.amount}
          onChange={(e) => field("amount", e.target.value)}
        />
        <Field
          label={t("Payment date", "Data e pagesës")}
          type="date"
          required
          value={form.payment_date}
          onChange={(e) => field("payment_date", e.target.value)}
        />
        <Field label={t("Method", "Mënyra")}>
          <select
            value={form.payment_method}
            onChange={(e) => field("payment_method", e.target.value)}
          >
            {["bank_transfer", "cash", "card", "cheque", "other"].map((x) => (
              <option key={x}>{x}</option>
            ))}
          </select>
        </Field>
        <QueryState query={accounts}>
          {(rows) => (
            <Field label={t("Payment account", "Llogaria e pagesës")}>
              <select
                required
                value={form.financial_account_id}
                onChange={(e) => field("financial_account_id", e.target.value)}
              >
                <option value="">
                  {t(
                    "Select cash / bank account",
                    "Zgjidh arkën / llogarinë bankare",
                  )}
                </option>
                {rows
                  .filter((a) => a.is_active && a.currency === bill.currency)
                  .map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.name} · {a.currency} · {a.current_balance ?? a.balance}
                    </option>
                  ))}
              </select>
            </Field>
          )}
        </QueryState>
        {bill.currency !== "EUR" && (
          <>
            <Field
              label={t(
                "Historical payment rate to EUR",
                "Kursi historik i pagesës në EUR",
              )}
              required
              type="number"
              min=".00000001"
              step=".00000001"
              value={form.exchange_rate}
              onChange={(e) => field("exchange_rate", e.target.value)}
            />
            <Field
              label={t("Rate date", "Data e kursit")}
              required
              type="date"
              max={form.payment_date}
              value={form.exchange_rate_date}
              onChange={(e) => field("exchange_rate_date", e.target.value)}
            />
            <Field
              label={t("Rate source", "Burimi i kursit")}
              required
              value={form.exchange_rate_source}
              onChange={(e) => field("exchange_rate_source", e.target.value)}
            />
          </>
        )}
        <Field
          label={t("Bank / payment reference", "Referenca bankare / e pagesës")}
          maxLength={255}
          value={form.reference_number}
          onChange={(e) => field("reference_number", e.target.value)}
        />
        <Field
          label={t("Note", "Shënimi")}
          maxLength={2000}
          value={form.note}
          onChange={(e) => field("note", e.target.value)}
        />
        <button disabled={busy}>
          {t("Confirm recorded payment", "Konfirmo pagesën e regjistruar")}
        </button>
      </form>
    </WorkspaceModal>
  );
}
