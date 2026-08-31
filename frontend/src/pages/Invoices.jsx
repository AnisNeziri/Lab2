import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
} from "react";
import { createPortal } from "react-dom";
import { useLocation } from "react-router-dom";
import {
  AlertTriangle,
  BadgeCheck,
  Banknote,
  Ban,
  Building2,
  ChevronLeft,
  ChevronRight,
  Download,
  Eye,
  FileCheck2,
  FilePenLine,
  FilePlus2,
  FileSpreadsheet,
  Landmark,
  LoaderCircle,
  Pencil,
  Plus,
  ReceiptText,
  RotateCcw,
  Save,
  Search,
  Settings2,
  Trash2,
  UserRound,
  X,
} from "lucide-react";
import {
  createInvoice,
  createInvoiceCreditNote,
  downloadInvoiceExcel,
  downloadInvoicePdf,
  getInvoice,
  getInvoiceProfile,
  getInvoices,
  issueInvoice,
  updateInvoice,
  updateInvoiceProfile,
  voidInvoice,
} from "../api/invoices";
import { processPayment, reversePayment } from "../api/payments";
import { getFinancialAccounts } from "../api/advancedOperations";
import { getAllProducts } from "../api/products";
import { getCustomerDebts } from "../api/customerDebts";
import { useTranslation } from "../hooks/useTranslation";
import { useAuthStore } from "../store/authStore";
import { formatQuantity, isMeterUnit } from "../utils/formatQuantity";
import { getInventoryQuantity } from "../utils/inventoryQuantity";
import "./Invoices.css";

const todayIso = () => {
  const date = new Date();
  date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
  return date.toISOString().slice(0, 10);
};

const addDays = (dateString, days) => {
  const date = new Date(`${dateString}T12:00:00`);
  date.setDate(date.getDate() + Number(days || 0));
  return date.toISOString().slice(0, 10);
};

const daysBetween = (fromDate, toDate, fallback = 0) => {
  if (!fromDate || !toDate) return fallback;
  const from = new Date(`${String(fromDate).slice(0, 10)}T12:00:00`);
  const to = new Date(`${String(toDate).slice(0, 10)}T12:00:00`);
  const difference = Math.round((to.getTime() - from.getTime()) / 86400000);
  return Number.isFinite(difference) && difference >= 0 ? difference : fallback;
};

const createRequestId = () => {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return `payment-${Date.now()}-${Math.random().toString(16).slice(2)}`;
};

const allowedTaxTreatments = (profile) => (
  profile?.is_vat_registered
    ? ["standard", "zero_rated", "exempt", "reverse_charge"]
    : ["non_vat"]
);

const validTaxTreatment = (value, profile) => (
  allowedTaxTreatments(profile).includes(value)
    ? value
    : (profile?.is_vat_registered ? "standard" : "non_vat")
);

const validVatRate = (value, treatment, profile) => {
  if (!profile?.is_vat_registered || treatment !== "standard") return "0";
  return String(Number(value) === 8 ? 8 : 18);
};

const roundMoney = (value) => Math.round((Number(value) + Number.EPSILON) * 100) / 100;

const emptyProfile = {
  legal_name: "",
  trade_name: "",
  registered_address: "",
  municipality: "",
  postal_code: "",
  country_code: "XK",
  phone: "",
  email: "",
  business_registration_number: "",
  fiscal_number: "",
  vat_number: "",
  is_vat_registered: false,
  bank_name: "",
  bank_account: "",
  iban: "",
  swift_bic: "",
  invoice_prefix: "INV",
  credit_note_prefix: "CN",
  default_language: "bilingual",
  default_payment_terms_days: 14,
  default_payment_terms: "",
  sales_mode: "business_only",
};

const emptyItem = (profile, index = 0) => ({
  key: `invoice-line-${Date.now()}-${index}-${Math.random().toString(16).slice(2)}`,
  product_id: "",
  description: "",
  sku: "",
  unit: "pcs",
  quantity: "1",
  unit_price: "",
  discount_percent: "0",
  vat_rate: validVatRate("18", profile?.is_vat_registered ? "standard" : "non_vat", profile),
  tax_treatment: profile?.is_vat_registered ? "standard" : "non_vat",
  legal_reference: "",
  stock_available: null,
  inventory_unit: "pcs",
  conversion_mode: "none",
  conversion_factor: null,
  actual_base_quantity: "",
  warehouse_id: "",
});

function emptyInvoiceForm(profile) {
  const issued = todayIso();
  const dueDays = Number(profile?.default_payment_terms_days ?? 14);
  return {
    customer_id: "",
    buyer_name: "",
    buyer_business_name: "",
    buyer_address: "",
    buyer_business_registration_number: "",
    buyer_fiscal_number: "",
    buyer_is_vat_registered: false,
    buyer_vat_number: "",
    buyer_municipality: "",
    buyer_postal_code: "",
    buyer_country_code: "XK",
    buyer_phone: "",
    buyer_email: "",
    save_customer: false,
    issued_at: issued,
    supply_at: issued,
    due_at: addDays(issued, dueDays),
    due_days: String(dueDays),
    payment_terms: profile?.default_payment_terms || "",
    notes: "",
    tax_treatment: profile?.is_vat_registered ? "standard" : "non_vat",
    issue_now: false,
    items: [emptyItem(profile)],
  };
}

function unwrapInvoice(payload) {
  if (payload?.invoice) return payload.invoice;
  if (payload?.data && !Array.isArray(payload.data)) return payload.data;
  return payload;
}

function normalizeProfile(payload) {
  const source = payload?.profile || payload || {};
  const profile = {
    ...emptyProfile,
    ...source,
    registered_address: source.registered_address ?? source.address ?? "",
    municipality: source.municipality ?? source.city ?? "",
    default_payment_terms_days:
      source.default_payment_terms_days ?? source.default_due_days ?? 14,
    default_payment_terms:
      source.default_payment_terms ?? source.default_notes ?? "",
    sales_mode: "business_only",
  };
  const suppliedCompleteness = payload?.completeness || null;
  return { profile, completeness: suppliedCompleteness };
}

function profileMissingFields(profile, completeness) {
  if (Array.isArray(completeness?.missing)) return completeness.missing;
  if (Array.isArray(profile?.missing_fields)) return profile.missing_fields;
  const missing = [];
  if (!profile.legal_name?.trim()) missing.push("legal_name");
  if (!profile.registered_address?.trim()) missing.push("registered_address");
  if (!profile.municipality?.trim()) missing.push("municipality");
  if (!profile.business_registration_number?.trim()) {
    missing.push("business_registration_number");
  }
  if (!profile.fiscal_number?.trim()) missing.push("fiscal_number");
  if (!profile.country_code?.trim()) missing.push("country_code");
  if (!profile.invoice_prefix?.trim()) missing.push("invoice_prefix");
  if (!profile.credit_note_prefix?.trim()) missing.push("credit_note_prefix");
  if (profile.is_vat_registered && !profile.vat_number?.trim()) {
    missing.push("vat_number");
  }
  return missing;
}

function normalizeInvoiceList(payload) {
  if (Array.isArray(payload)) {
    return {
      rows: payload,
      pagination: { current_page: 1, last_page: 1, total: payload.length },
    };
  }
  const page = payload?.meta ? { ...payload.meta, ...payload } : payload || {};
  return {
    rows: Array.isArray(payload?.data) ? payload.data : payload?.invoices || [],
    pagination: {
      current_page: Number(page.current_page || 1),
      last_page: Number(page.last_page || 1),
      total: Number(page.total || payload?.data?.length || 0),
    },
  };
}

async function getAllInvoiceCustomers() {
  const customers = [];
  let page = 1;
  let lastPage = 1;
  do {
    const response = await getCustomerDebts({
      status: "all",
      sort: "recent",
      per_page: 50,
      page,
    });
    const rows = Array.isArray(response) ? response : response?.data || [];
    customers.push(...rows);
    lastPage = Number(response?.last_page || 1);
    page += 1;
  } while (page <= lastPage);
  return customers;
}

function buyerSnapshot(invoice) {
  const snapshot = invoice?.customer_snapshot || invoice?.buyer_snapshot || {};
  return {
    name:
      snapshot.name ||
      snapshot.legal_name ||
      invoice?.buyer_name ||
      invoice?.customer_name ||
      invoice?.customer?.business_name ||
      invoice?.customer?.name ||
      "",
    business_name: snapshot.trade_name || snapshot.business_name || invoice?.buyer_business_name || "",
    address: snapshot.address || invoice?.buyer_address || "",
    business_registration_number:
      snapshot.business_registration_number ||
      invoice?.buyer_business_registration_number ||
      "",
    fiscal_number:
      snapshot.fiscal_number ||
      snapshot.tax_number ||
      invoice?.buyer_fiscal_number ||
      invoice?.buyer_tax_number ||
      "",
    vat_number: snapshot.vat_number || invoice?.buyer_vat_number || "",
    is_vat_registered: Boolean(snapshot.is_vat_registered),
    municipality: snapshot.municipality || "",
    postal_code: snapshot.postal_code || "",
    country_code: snapshot.country_code || "XK",
    phone: snapshot.phone || invoice?.buyer_phone || "",
    email: snapshot.email || invoice?.buyer_email || "",
  };
}

function invoiceFormFromRecord(invoice, profile) {
  const buyer = buyerSnapshot(invoice);
  const issueDate = String(invoice.invoice_date || invoice.issued_at || todayIso()).slice(0, 10);
  const storedDueDate = invoice.due_at || invoice.due_date
    ? String(invoice.due_at || invoice.due_date).slice(0, 10)
    : "";
  const defaultDueDays = Number(profile?.default_payment_terms_days ?? 14);
  const dueDate = storedDueDate || addDays(issueDate, defaultDueDays);
  return {
    customer_id: invoice.customer_id ? String(invoice.customer_id) : "",
    buyer_name: buyer.name,
    buyer_business_name: buyer.business_name,
    buyer_address: buyer.address,
    buyer_business_registration_number: buyer.business_registration_number,
    buyer_fiscal_number: buyer.fiscal_number,
    buyer_is_vat_registered: buyer.is_vat_registered,
    buyer_vat_number: buyer.vat_number,
    buyer_municipality: buyer.municipality,
    buyer_postal_code: buyer.postal_code,
    buyer_country_code: buyer.country_code,
    buyer_phone: buyer.phone,
    buyer_email: buyer.email,
    save_customer: false,
    issued_at: issueDate,
    supply_at: String(invoice.supply_date || invoice.supply_at || invoice.invoice_date || todayIso()).slice(0, 10),
    due_at: dueDate,
    due_days: String(daysBetween(issueDate, dueDate, defaultDueDays)),
    payment_terms: String(invoice.payment_terms ?? profile?.default_payment_terms ?? ""),
    notes: invoice.notes || "",
    tax_treatment: validTaxTreatment(invoice.tax_treatment, profile),
    issue_now: false,
    items: (invoice.items || []).map((item, index) => ({
      key: `invoice-line-existing-${item.id || index}`,
      product_id: item.product_id ? String(item.product_id) : "",
      description: item.description || item.product?.name || "",
      sku: item.sku_snapshot || item.sku || item.product?.sku || "",
      unit: item.unit || item.product?.unit || "pcs",
      quantity: String(Number(item.quantity ?? 1)),
      unit_price: String(Number(item.unit_price ?? 0)),
      discount_percent: String(Number(item.discount_percent ?? 0)),
      vat_rate: validVatRate(item.vat_rate, validTaxTreatment(item.tax_treatment, profile), profile),
      tax_treatment: validTaxTreatment(item.tax_treatment, profile),
      legal_reference: item.tax_legal_reference || item.legal_reference || "",
      stock_available: item.product ? getInventoryQuantity(item.product, "available") : null,
      inventory_unit: item.product?.unit || item.unit || "pcs",
      conversion_mode: item.conversion_mode || "none",
      conversion_factor: item.conversion_factor ?? null,
      actual_base_quantity: item.conversion_mode === "variable" ? String(Number(item.base_quantity || 0)) : "",
      warehouse_id: item.warehouse_id ? String(item.warehouse_id) : "",
    })),
  };
}

function lineAmounts(item) {
  const quantity = Math.max(0, Number(item.quantity || 0));
  const price = Math.max(0, Number(item.unit_price || 0));
  const discountRate = Math.min(100, Math.max(0, Number(item.discount_percent || 0)));
  const gross = roundMoney(quantity * price);
  const discount = roundMoney(gross * (discountRate / 100));
  const taxable = roundMoney(gross - discount);
  const vatRate = ["zero_rated", "exempt", "reverse_charge", "non_vat"].includes(
    item.tax_treatment,
  )
    ? 0
    : Math.max(0, Number(item.vat_rate || 0));
  const vat = roundMoney(taxable * (vatRate / 100));
  return { gross, discount, taxable, vat, total: roundMoney(taxable + vat), vatRate };
}

function invoiceTotals(items) {
  return items.reduce(
    (totals, item) => {
      const line = lineAmounts(item);
      totals.subtotal = roundMoney(totals.subtotal + line.gross);
      totals.discount = roundMoney(totals.discount + line.discount);
      totals.taxable = roundMoney(totals.taxable + line.taxable);
      totals.vat = roundMoney(totals.vat + line.vat);
      totals.total = roundMoney(totals.total + line.total);
      const rateKey = String(line.vatRate);
      totals.byRate[rateKey] = roundMoney((totals.byRate[rateKey] || 0) + line.vat);
      return totals;
    },
    { subtotal: 0, discount: 0, taxable: 0, vat: 0, total: 0, byRate: {} },
  );
}

function apiError(error, fallback) {
  if (error?.errors) return Object.values(error.errors).flat().join(" ");
  return error?.message || fallback;
}

function Modal({ open, title, description, onClose, wide = false, drawer = false, children }) {
  const closeRef = useRef(null);
  const dialogRef = useRef(null);
  const titleId = useId();

  useEffect(() => {
    if (!open) return undefined;
    const handleKey = (event) => {
      if (event.key === "Escape") onClose?.();
      if (event.key === "Tab") {
        const focusable = dialogRef.current?.querySelectorAll(
          'button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
        );
        if (!focusable?.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    };
    document.addEventListener("keydown", handleKey);
    window.setTimeout(() => closeRef.current?.focus(), 0);
    return () => document.removeEventListener("keydown", handleKey);
  }, [open, onClose]);

  if (!open) return null;
  return createPortal(
    <div className={`invoice-overlay ${drawer ? "is-drawer" : ""}`} onMouseDown={onClose}>
      <section
        ref={dialogRef}
        className={`invoice-dialog ${wide ? "is-wide" : ""} ${drawer ? "invoice-drawer" : ""}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <header className="invoice-dialog-header">
          <div>
            <h2 id={titleId}>{title}</h2>
            {description ? <p>{description}</p> : null}
          </div>
          <button ref={closeRef} type="button" className="invoice-icon-button" onClick={onClose} aria-label="Close">
            <X size={19} />
          </button>
        </header>
        <div className="invoice-dialog-body">{children}</div>
      </section>
    </div>,
    document.body,
  );
}

function CompanyProfileModal({ open, profile, onClose, onSave, t }) {
  const [form, setForm] = useState(profile);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => setForm(profile), [profile, open]);

  const change = (field, value) => setForm((current) => ({ ...current, [field]: value }));

  const submit = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError("");
    try {
      await onSave({
        ...form,
        default_payment_terms_days: Math.max(
          0,
          Math.min(3650, Number(form.default_payment_terms_days || 0)),
        ),
        country_code: String(form.country_code || "XK").toUpperCase(),
        is_vat_registered: Boolean(form.is_vat_registered),
        vat_number: form.is_vat_registered ? form.vat_number : "",
        sales_mode: "business_only",
      });
    } catch (err) {
      setError(apiError(err, t("invoice.profileSaveError")));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      title={t("invoice.profileTitle")}
      description={t("invoice.profileDescription")}
      onClose={onClose}
      wide
    >
      <form className="invoice-profile-form" onSubmit={submit}>
        {error ? <div className="invoice-alert is-error">{error}</div> : null}
        <fieldset>
          <legend><Building2 size={17} /> {t("invoice.profileIdentity")}</legend>
          <div className="invoice-form-grid cols-2">
            <label>
              {t("invoice.legalName")}<span aria-hidden="true">*</span>
              <input required value={form.legal_name} onChange={(e) => change("legal_name", e.target.value)} />
            </label>
            <label>
              {t("invoice.tradeName")}
              <input value={form.trade_name} onChange={(e) => change("trade_name", e.target.value)} />
            </label>
            <label>
              {t("invoice.businessNumber")}<span aria-hidden="true">*</span>
              <input required value={form.business_registration_number} onChange={(e) => change("business_registration_number", e.target.value)} />
            </label>
            <label>
              {t("invoice.fiscalNumber")}<span aria-hidden="true">*</span>
              <input required value={form.fiscal_number} onChange={(e) => change("fiscal_number", e.target.value)} />
            </label>
            <label className="invoice-check-field">
              <input type="checkbox" checked={Boolean(form.is_vat_registered)} onChange={(e) => change("is_vat_registered", e.target.checked)} />
              <span>{t("invoice.vatRegistered")}</span>
            </label>
            {form.is_vat_registered ? (
              <label>
                {t("invoice.vatNumber")}<span aria-hidden="true">*</span>
                <input required value={form.vat_number} onChange={(e) => change("vat_number", e.target.value)} />
              </label>
            ) : null}
            <label>
              {t("invoice.invoicePrefix")}<span aria-hidden="true">*</span>
              <input required value={form.invoice_prefix} maxLength={20} pattern="[A-Za-z0-9_-]+" onChange={(e) => change("invoice_prefix", e.target.value.toUpperCase())} />
            </label>
            <label>
              {t("invoice.creditNotePrefix")}
              <input required value={form.credit_note_prefix} maxLength={20} pattern="[A-Za-z0-9_-]+" onChange={(e) => change("credit_note_prefix", e.target.value.toUpperCase())} />
            </label>
          </div>
        </fieldset>

        <fieldset>
          <legend><Landmark size={17} /> {t("invoice.profileAddressBank")}</legend>
          <div className="invoice-form-grid cols-2">
            <label className="span-2">
              {t("invoice.address")}<span aria-hidden="true">*</span>
              <input required value={form.registered_address} onChange={(e) => change("registered_address", e.target.value)} />
            </label>
            <label>
              {t("invoice.city")}<span aria-hidden="true">*</span>
              <input required value={form.municipality} onChange={(e) => change("municipality", e.target.value)} />
            </label>
            <label>
              {t("invoice.postalCode")}
              <input value={form.postal_code} onChange={(e) => change("postal_code", e.target.value)} />
            </label>
            <label>
              {t("invoice.countryCode")}<span aria-hidden="true">*</span>
              <input required minLength="2" maxLength="2" value={form.country_code} onChange={(e) => change("country_code", e.target.value.toUpperCase())} />
            </label>
            <label>
              {t("invoice.phone")}
              <input value={form.phone} onChange={(e) => change("phone", e.target.value)} />
            </label>
            <label>
              {t("invoice.email")}
              <input type="email" value={form.email} onChange={(e) => change("email", e.target.value)} />
            </label>
            <label>
              {t("invoice.bankName")}
              <input value={form.bank_name} onChange={(e) => change("bank_name", e.target.value)} />
            </label>
            <label>
              {t("invoice.bankAccount")}
              <input value={form.bank_account} onChange={(e) => change("bank_account", e.target.value)} />
            </label>
            <label>
              {t("invoice.iban")}
              <input value={form.iban} onChange={(e) => change("iban", e.target.value.toUpperCase())} />
            </label>
            <label>
              {t("invoice.swift")}
              <input value={form.swift_bic} onChange={(e) => change("swift_bic", e.target.value.toUpperCase())} />
            </label>
          </div>
        </fieldset>

        <fieldset>
          <legend><Settings2 size={17} /> {t("invoice.profileDefaults")}</legend>
          <div className="invoice-form-grid cols-2">
            <label>
              {t("invoice.defaultDueDays")}
              <input type="number" min="0" max="3650" value={form.default_payment_terms_days} onChange={(e) => change("default_payment_terms_days", e.target.value)} />
            </label>
            <label>
              {t("invoice.defaultLanguage")}
              <select value={form.default_language} onChange={(e) => change("default_language", e.target.value)}>
                <option value="bilingual">{t("invoice.languageBilingual")}</option>
                <option value="sq">{t("invoice.languageSq")}</option>
                <option value="en">{t("invoice.languageEn")}</option>
              </select>
            </label>
            <label>
              {t("invoice.salesMode")}
              <input readOnly value={t("invoice.salesBusinessOnly")} />
            </label>
            <label className="span-2">
              {t("invoice.defaultNotes")}
              <textarea rows="3" value={form.default_payment_terms} onChange={(e) => change("default_payment_terms", e.target.value)} />
            </label>
          </div>
          <div className="invoice-efs-warning" role="note">
            <AlertTriangle size={18} />
            <div><strong>{t("invoice.efsWarningTitle")}</strong><p>{t("invoice.efsWarningBody")}</p></div>
          </div>
        </fieldset>

        <div className="invoice-dialog-actions">
          <button type="button" className="secondary" onClick={onClose}>{t("common.cancel")}</button>
          <button type="submit" disabled={saving}>
            {saving ? <LoaderCircle className="is-spinning" size={16} /> : <Save size={16} />}
            {saving ? t("common.saving") : t("invoice.saveProfile")}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function InvoiceEditor({ open, invoice, profile, products, customers, canSaveCustomer, onClose, onSaved, t, language }) {
  const [form, setForm] = useState(() => emptyInvoiceForm(profile));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    setForm(invoice ? invoiceFormFromRecord(invoice, profile) : emptyInvoiceForm(profile));
    setError("");
  }, [invoice, open, profile]);

  const money = useCallback(
    (value) => new Intl.NumberFormat(language === "sq" ? "sq-AL" : "en-IE", { style: "currency", currency: "EUR" }).format(Number(value || 0)),
    [language],
  );
  const totals = useMemo(() => invoiceTotals(form.items), [form.items]);
  const productMap = useMemo(
    () => Object.fromEntries(products.map((product) => [String(product.id), product])),
    [products],
  );

  const setField = (field, value) => setForm((current) => ({ ...current, [field]: value }));

  const selectCustomer = (customerId) => {
    const customer = customers.find((item) => String(item.id) === String(customerId));
    if (!customer) {
      setForm((current) => ({ ...current, customer_id: "" }));
      return;
    }
    setForm((current) => ({
      ...current,
      customer_id: String(customer.id),
      buyer_name: customer.legal_name || customer.business_name || customer.name || "",
      buyer_business_name: customer.trade_name || customer.business_name || "",
      buyer_address: customer.address || "",
      buyer_business_registration_number: customer.business_registration_number || "",
      buyer_fiscal_number: customer.fiscal_number || customer.tax_number || "",
      buyer_is_vat_registered: Boolean(customer.is_vat_registered),
      buyer_vat_number: customer.vat_number || "",
      buyer_municipality: customer.municipality || "",
      buyer_postal_code: customer.postal_code || "",
      buyer_country_code: customer.country_code || "XK",
      buyer_phone: customer.phone || "",
      buyer_email: customer.email || "",
      save_customer: false,
    }));
  };

  const updateLine = (key, patch) => {
    setForm((current) => ({
      ...current,
      items: current.items.map((item) => (item.key === key ? { ...item, ...patch } : item)),
    }));
  };

  const selectProduct = (key, productId) => {
    const product = productMap[String(productId)];
    if (!product) {
      updateLine(key, { product_id: "", sku: "", stock_available: null });
      return;
    }
    const taxTreatment = validTaxTreatment(form.tax_treatment, profile);
    updateLine(key, {
      product_id: String(product.id),
      description: product.name || "",
      sku: product.sku || "",
      unit: product.unit || "pcs",
      unit_price: String(Number(product.selling_price ?? product.price ?? 0)),
      vat_rate: validVatRate(18, taxTreatment, profile),
      tax_treatment: taxTreatment,
      legal_reference: "",
      stock_available: getInventoryQuantity(product, "available"),
      inventory_unit: product.unit || "pcs",
      conversion_mode: "none",
      conversion_factor: null,
      actual_base_quantity: "",
      warehouse_id: product.default_warehouse_id ? String(product.default_warehouse_id) : "",
    });
  };

  const selectItemUnit = (key, unitCode) => {
    const line = form.items.find((item) => item.key === key);
    const product = productMap[String(line?.product_id)];
    const definition = product?.units?.find((unit) => unit.code === unitCode);
    updateLine(key, {
      unit: unitCode,
      conversion_mode: definition?.conversion_mode || "none",
      conversion_factor: definition?.factor_to_base ?? null,
      actual_base_quantity: "",
    });
  };

  const addLine = () => setForm((current) => ({
    ...current,
    items: [...current.items, emptyItem(profile, current.items.length)],
  }));

  const removeLine = (key) => setForm((current) => ({
    ...current,
    items: current.items.length === 1 ? current.items : current.items.filter((item) => item.key !== key),
  }));

  const setIssueDate = (issuedAt) => {
    setForm((current) => {
      const dueDays = Number(current.due_days || 0);
      return {
        ...current,
        issued_at: issuedAt,
        supply_at: current.supply_at || issuedAt,
        due_at: dueDays >= 0 ? addDays(issuedAt, dueDays) : current.due_at,
      };
    });
  };

  const setDueDays = (value) => setForm((current) => ({
    ...current,
    due_days: value,
    due_at: current.issued_at && value !== "" ? addDays(current.issued_at, Number(value)) : current.due_at,
  }));

  const setDueDate = (value) => setForm((current) => ({
    ...current,
    due_at: value,
    due_days: String(daysBetween(current.issued_at, value, Number(current.due_days || 0))),
  }));

  const setDocumentTaxTreatment = (requestedValue) => {
    const value = validTaxTreatment(requestedValue, profile);
    setForm((current) => ({
      ...current,
      tax_treatment: value,
      items: current.items.map((item) => ({
        ...item,
        tax_treatment: value,
        vat_rate: validVatRate(item.vat_rate, value, profile),
      })),
    }));
  };

  const validate = () => {
    if (!form.buyer_name.trim()) return t("invoice.validationBuyer");
    if (!form.buyer_address.trim()) return t("invoice.validationBuyerAddress");
    if (!form.buyer_business_registration_number.trim() && !form.buyer_fiscal_number.trim()) {
      return t("invoice.validationBuyerIdentifier");
    }
    if (form.buyer_is_vat_registered && !form.buyer_vat_number.trim()) {
      return t("invoice.validationBuyerVat");
    }
    if (!form.issued_at) return t("invoice.validationIssueDate");
    if (!form.supply_at) return t("invoice.validationSupplyDate");
    if (form.due_days === "" || !Number.isInteger(Number(form.due_days)) || Number(form.due_days) < 0 || Number(form.due_days) > 3650) {
      return t("invoice.validationDueDays");
    }
    if (!form.items.length) return t("invoice.validationItems");
    for (const item of form.items) {
      if (!item.description.trim()) return t("invoice.validationDescription");
      const quantity = Number(item.quantity);
      if (!Number.isFinite(quantity) || quantity <= 0) return t("invoice.validationQuantity");
      if (!isMeterUnit(item.unit) && !Number.isInteger(quantity)) return t("invoice.validationWholeQuantity");
      if (item.unit_price === "" || Number(item.unit_price) < 0) return t("invoice.validationPrice");
      const discount = Number(item.discount_percent || 0);
      if (discount < 0 || discount > 100) return t("invoice.validationDiscount");
      if (!allowedTaxTreatments(profile).includes(item.tax_treatment)) return t("invoice.validationTaxTreatment");
      if (item.tax_treatment === "standard" && ![8, 18].includes(Number(item.vat_rate))) {
        return t("invoice.validationVatRate");
      }
      if (["zero_rated", "exempt", "reverse_charge"].includes(item.tax_treatment) && !item.legal_reference.trim()) {
        return t("invoice.validationLegalReference");
      }
    }
    return "";
  };

  const submit = async (event) => {
    event.preventDefault();
    if (saving) return;
    const validation = validate();
    if (validation) {
      setError(validation);
      return;
    }
    setSaving(true);
    setError("");
    const payload = {
      customer_id: form.customer_id ? Number(form.customer_id) : null,
      buyer: {
        legal_name: form.buyer_name.trim(),
        trade_name: form.buyer_business_name.trim() || null,
        address: form.buyer_address.trim(),
        business_registration_number: form.buyer_business_registration_number.trim() || null,
        fiscal_number: form.buyer_fiscal_number.trim() || null,
        is_vat_registered: Boolean(form.buyer_is_vat_registered),
        vat_number: form.buyer_is_vat_registered ? (form.buyer_vat_number.trim() || null) : null,
        municipality: form.buyer_municipality.trim() || null,
        postal_code: form.buyer_postal_code.trim() || null,
        country_code: form.buyer_country_code.trim().toUpperCase() || "XK",
        phone: form.buyer_phone.trim() || null,
        email: form.buyer_email.trim() || null,
      },
      save_customer: canSaveCustomer && !form.customer_id && Boolean(form.save_customer),
      issue_now: false,
      invoice_date: form.issued_at,
      supply_date: form.supply_at || form.issued_at,
      due_date: form.due_at || null,
      payment_terms: form.payment_terms.trim() || null,
      notes: form.notes.trim() || null,
      items: form.items.map((item) => ({
        product_id: item.product_id ? Number(item.product_id) : null,
        description: item.description.trim(),
        unit: item.unit.trim() || "pcs",
        quantity: Number(item.quantity),
        unit_price: Number(item.unit_price),
        discount_percent: Number(item.discount_percent || 0),
        vat_rate: Number(item.vat_rate || 0),
        tax_treatment: item.tax_treatment,
        tax_legal_reference: item.legal_reference.trim() || null,
        warehouse_id: item.warehouse_id ? Number(item.warehouse_id) : null,
        actual_base_quantity: item.conversion_mode === "variable" ? Number(item.actual_base_quantity) : null,
      })),
    };

    try {
      if (invoice) {
        const result = await updateInvoice(invoice.id, payload);
        await onSaved(unwrapInvoice(result), false);
      } else {
        const draft = unwrapInvoice(await createInvoice(payload));
        if (!form.issue_now) {
          await onSaved(draft, false);
        } else {
          try {
            const issued = unwrapInvoice(await issueInvoice(draft.id));
            await onSaved(issued, true);
          } catch (issueError) {
            await onSaved(draft, false, {
              issueError: `${apiError(issueError, t("invoice.issueError"))} ${t("invoice.savedAsDraftAfterIssueError")}`,
            });
          }
        }
      }
    } catch (err) {
      setError(apiError(err, t("invoice.saveError")));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      title={invoice ? t("invoice.editDraft") : t("invoice.newInvoice")}
      description={t("invoice.editorDescription")}
      onClose={onClose}
      wide
    >
      <form className="invoice-editor" onSubmit={submit}>
        {error ? <div className="invoice-alert is-error"><AlertTriangle size={17} />{error}</div> : null}

        <section className="invoice-editor-section">
          <div className="invoice-editor-section-title">
            <UserRound size={18} />
            <div><h3>{t("invoice.buyer")}</h3><p>{t("invoice.buyerHint")}</p></div>
          </div>
          <div className="invoice-form-grid cols-3">
            <label className="span-3">
              {t("invoice.savedCustomer")}
              <select value={form.customer_id} onChange={(e) => selectCustomer(e.target.value)}>
                <option value="">{t("invoice.manualBuyer")}</option>
                {customers.map((customer) => (
                  <option key={customer.id} value={customer.id}>
                    {customer.business_name || customer.name}{customer.business_registration_number || customer.fiscal_number || customer.tax_number ? ` · ${customer.business_registration_number || customer.fiscal_number || customer.tax_number}` : ""}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {t("invoice.buyerName")}<span aria-hidden="true">*</span>
              <input required value={form.buyer_name} onChange={(e) => setField("buyer_name", e.target.value)} />
            </label>
            <label>
              {t("invoice.buyerBusinessNumber")}
              <input value={form.buyer_business_registration_number} onChange={(e) => setField("buyer_business_registration_number", e.target.value)} />
            </label>
            <label>
              {t("invoice.buyerFiscalNumber")}
              <input value={form.buyer_fiscal_number} onChange={(e) => setField("buyer_fiscal_number", e.target.value)} />
            </label>
            <label className="span-2">
              {t("invoice.buyerAddress")}<span aria-hidden="true">*</span>
              <input required value={form.buyer_address} onChange={(e) => setField("buyer_address", e.target.value)} />
            </label>
            <label>
              {t("invoice.buyerMunicipality")}
              <input value={form.buyer_municipality} onChange={(e) => setField("buyer_municipality", e.target.value)} />
            </label>
            <label>
              {t("invoice.buyerPostalCode")}
              <input value={form.buyer_postal_code} onChange={(e) => setField("buyer_postal_code", e.target.value)} />
            </label>
            <label>
              {t("invoice.buyerCountry")}
              <input maxLength="2" value={form.buyer_country_code} onChange={(e) => setField("buyer_country_code", e.target.value.toUpperCase())} />
            </label>
            <label className="invoice-check-field invoice-vat-registration-field">
              <input type="checkbox" checked={form.buyer_is_vat_registered} onChange={(e) => setField("buyer_is_vat_registered", e.target.checked)} />
              <span>{t("invoice.buyerVatRegistered")}</span>
            </label>
            {form.buyer_is_vat_registered ? (
              <label>
                {t("invoice.buyerVatNumber")}<span aria-hidden="true">*</span>
                <input required value={form.buyer_vat_number} onChange={(e) => setField("buyer_vat_number", e.target.value)} />
              </label>
            ) : null}
            <label>
              {t("invoice.buyerTradeName")}
              <input value={form.buyer_business_name} onChange={(e) => setField("buyer_business_name", e.target.value)} />
            </label>
            <label>
              {t("invoice.phone")}
              <input value={form.buyer_phone} onChange={(e) => setField("buyer_phone", e.target.value)} />
            </label>
            <label>
              {t("invoice.email")}
              <input type="email" value={form.buyer_email} onChange={(e) => setField("buyer_email", e.target.value)} />
            </label>
            {canSaveCustomer && !form.customer_id ? (
              <label className="invoice-check-field">
                <input type="checkbox" checked={form.save_customer} onChange={(e) => setField("save_customer", e.target.checked)} />
                <span>{t("invoice.saveBuyer")}</span>
              </label>
            ) : null}
          </div>
        </section>

        <section className="invoice-editor-section">
          <div className="invoice-editor-section-title">
            <FilePenLine size={18} />
            <div><h3>{t("invoice.documentDetails")}</h3><p>{t("invoice.documentDetailsHint")}</p></div>
          </div>
          <div className="invoice-form-grid cols-4">
            <label>
              {t("invoice.issueDate")}<span aria-hidden="true">*</span>
              <input type="date" required value={form.issued_at} onChange={(e) => setIssueDate(e.target.value)} />
            </label>
            <label>
              {t("invoice.supplyDate")}
              <input type="date" required value={form.supply_at} onChange={(e) => setField("supply_at", e.target.value)} />
            </label>
            <label>
              {t("invoice.paymentTerms")}
              <input type="number" min="0" max="3650" step="1" required value={form.due_days} onChange={(e) => setDueDays(e.target.value)} />
            </label>
            <label>
              {t("invoice.dueDate")}
              <input type="date" min={form.issued_at} value={form.due_at} onChange={(e) => setDueDate(e.target.value)} />
            </label>
            <label>
              {t("invoice.taxTreatment")}
              <select value={form.tax_treatment} disabled={!profile.is_vat_registered} onChange={(e) => setDocumentTaxTreatment(e.target.value)}>
                {profile.is_vat_registered ? (
                  <>
                    <option value="standard">{t("invoice.taxStandard")}</option>
                    <option value="zero_rated">{t("invoice.taxZeroRated")}</option>
                    <option value="exempt">{t("invoice.taxExempt")}</option>
                    <option value="reverse_charge">{t("invoice.taxReverse")}</option>
                  </>
                ) : <option value="non_vat">{t("invoice.taxNonVat")}</option>}
              </select>
            </label>
            <label className="span-3">
              {t("invoice.paymentInstructions")}
              <textarea rows="2" value={form.payment_terms} onChange={(e) => setField("payment_terms", e.target.value)} placeholder={t("invoice.paymentInstructionsPlaceholder")} />
            </label>
            <label className="span-4">
              {t("invoice.notes")}
              <textarea rows="2" value={form.notes} onChange={(e) => setField("notes", e.target.value)} />
            </label>
          </div>
        </section>

        <section className="invoice-editor-section invoice-lines-section">
          <div className="invoice-editor-section-title has-action">
            <div className="invoice-title-with-icon">
              <ReceiptText size={18} />
              <div><h3>{t("invoice.items")}</h3><p>{t("invoice.itemsHint")}</p></div>
            </div>
            <button type="button" className="secondary" onClick={addLine}><Plus size={16} />{t("invoice.addLine")}</button>
          </div>

          <div className="invoice-line-list">
            {form.items.map((item, index) => {
              const amounts = lineAmounts(item);
              const vatDisabled = item.tax_treatment !== "standard";
              return (
                <article className="invoice-line-card" key={item.key}>
                  <div className="invoice-line-number">{index + 1}</div>
                  <div className="invoice-line-fields">
                    <label className="line-product">
                      {t("invoice.product")}
                      <select value={item.product_id} onChange={(e) => selectProduct(item.key, e.target.value)}>
                        <option value="">{t("invoice.manualLine")}</option>
                        {products.map((product) => (
                          <option key={product.id} value={product.id}>
                            {product.name}{product.sku ? ` · ${product.sku}` : ""}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="line-description">
                      {t("invoice.description")}<span aria-hidden="true">*</span>
                      <input required value={item.description} onChange={(e) => updateLine(item.key, { description: e.target.value })} />
                    </label>
                    <label>
                      {t("invoice.unit")}
                      {item.product_id ? <select value={item.unit} onChange={(e) => selectItemUnit(item.key, e.target.value)}>
                        {(() => { const product = productMap[String(item.product_id)]; return [product?.unit || "pcs", ...(product?.units || []).filter((unit) => unit.is_active !== false).map((unit) => unit.code)].filter((unit,index,all) => all.indexOf(unit) === index).map((unit) => <option key={unit} value={unit}>{unit}</option>) })()}
                      </select> : <input value={item.unit} onChange={(e) => updateLine(item.key, { unit: e.target.value })} />}
                      {item.conversion_mode === "fixed" ? <small>1 {item.unit} = {formatQuantity(item.conversion_factor, item.inventory_unit, language)} {item.inventory_unit}</small> : null}
                    </label>
                    <label>
                      {t("invoice.quantity")}<span aria-hidden="true">*</span>
                      <input
                        type="number"
                        min={isMeterUnit(item.unit) ? "0.001" : "1"}
                        step={isMeterUnit(item.unit) ? "0.001" : "1"}
                        required
                        value={item.quantity}
                        onChange={(e) => updateLine(item.key, { quantity: e.target.value })}
                      />
                      {item.stock_available != null ? (
                        <small>{t("invoice.inStock")}: {formatQuantity(item.stock_available, item.inventory_unit, language)} {item.inventory_unit}</small>
                      ) : null}
                    </label>
                    {item.conversion_mode === "variable" ? <label>{t("dailySales.actualMeasured")} ({item.inventory_unit})<span aria-hidden="true">*</span><input required type="number" min="0.001" step="0.001" value={item.actual_base_quantity} onChange={(e) => updateLine(item.key, { actual_base_quantity: e.target.value })} /></label> : null}
                    <label>
                      {t("invoice.unitPrice")}<span aria-hidden="true">*</span>
                      <input type="number" min="0" step="0.01" required value={item.unit_price} onChange={(e) => updateLine(item.key, { unit_price: e.target.value })} />
                    </label>
                    <label>
                      {t("invoice.discount")}
                      <input type="number" min="0" max="100" step="0.01" value={item.discount_percent} onChange={(e) => updateLine(item.key, { discount_percent: e.target.value })} />
                    </label>
                    <details className="invoice-line-tax-settings">
                      <summary>
                        <span>{t("invoice.taxDetails")}</span>
                        <small>{t(`invoice.tax${item.tax_treatment === "standard" ? "Standard" : item.tax_treatment === "zero_rated" ? "ZeroRated" : item.tax_treatment === "reverse_charge" ? "Reverse" : item.tax_treatment === "non_vat" ? "NonVat" : "Exempt"}`)} · {Number(item.vat_rate || 0)}%</small>
                      </summary>
                      <p>{t("invoice.taxDetailsHint")}</p>
                      <div className="invoice-line-tax-grid">
                        <label>
                          {t("invoice.lineTaxTreatment")}
                          <select
                            value={item.tax_treatment}
                            onChange={(e) => updateLine(item.key, {
                              tax_treatment: validTaxTreatment(e.target.value, profile),
                              vat_rate: validVatRate(item.vat_rate, validTaxTreatment(e.target.value, profile), profile),
                            })}
                          >
                            {profile.is_vat_registered ? (
                              <>
                                <option value="standard">{t("invoice.taxStandard")}</option>
                                <option value="zero_rated">{t("invoice.taxZeroRated")}</option>
                                <option value="exempt">{t("invoice.taxExempt")}</option>
                                <option value="reverse_charge">{t("invoice.taxReverse")}</option>
                              </>
                            ) : <option value="non_vat">{t("invoice.taxNonVat")}</option>}
                          </select>
                        </label>
                        <label>
                          {t("invoice.vatRate")}
                          <select disabled={vatDisabled} value={vatDisabled ? "0" : item.vat_rate} onChange={(e) => updateLine(item.key, { vat_rate: e.target.value })}>
                            <option value="18">18%</option>
                            <option value="8">8%</option>
                          </select>
                        </label>
                        <label>
                          {t("invoice.legalReference")}
                          <input value={item.legal_reference} onChange={(e) => updateLine(item.key, { legal_reference: e.target.value })} placeholder={t("invoice.legalReferencePlaceholder")} />
                        </label>
                      </div>
                    </details>
                  </div>
                  <div className="invoice-line-total">
                    <span>{t("invoice.lineTotal")}</span>
                    <strong>{money(amounts.total)}</strong>
                    <small>{t("invoice.vatShort")} {money(amounts.vat)}</small>
                  </div>
                  <button type="button" className="invoice-remove-line" onClick={() => removeLine(item.key)} disabled={form.items.length === 1} aria-label={t("invoice.removeLine")}>
                    <Trash2 size={16} />
                  </button>
                </article>
              );
            })}
          </div>
        </section>

        <section className="invoice-editor-summary">
          <div className="invoice-vat-breakdown">
            <span>{t("invoice.vatBreakdown")}</span>
            {[18, 8, 0].map((rate) => (
              <small key={rate}>{rate}%: <strong>{money(totals.byRate[String(rate)] || 0)}</strong></small>
            ))}
          </div>
          <dl>
            <div><dt>{t("invoice.subtotal")}</dt><dd>{money(totals.subtotal)}</dd></div>
            <div><dt>{t("invoice.discountTotal")}</dt><dd>− {money(totals.discount)}</dd></div>
            <div><dt>{t("invoice.taxableTotal")}</dt><dd>{money(totals.taxable)}</dd></div>
            <div><dt>{t("invoice.vatTotal")}</dt><dd>{money(totals.vat)}</dd></div>
            <div className="grand-total"><dt>{t("invoice.grandTotal")}</dt><dd>{money(totals.total)}</dd></div>
          </dl>
        </section>

        <footer className="invoice-editor-footer">
          {!invoice ? (
            <label className="invoice-check-field issue-now-field">
              <input type="checkbox" checked={form.issue_now} onChange={(e) => setField("issue_now", e.target.checked)} />
              <span><strong>{t("invoice.issueNow")}</strong><small>{t("invoice.issueNowHint")}</small></span>
            </label>
          ) : <p className="invoice-draft-note">{t("invoice.draftEditHint")}</p>}
          <div className="invoice-dialog-actions">
            <button type="button" className="secondary" onClick={onClose}>{t("common.cancel")}</button>
            <button type="submit" disabled={saving}>
              {saving ? <LoaderCircle className="is-spinning" size={16} /> : (form.issue_now ? <FileCheck2 size={16} /> : <Save size={16} />)}
              {saving ? t("common.saving") : form.issue_now ? t("invoice.saveAndIssue") : t("invoice.saveDraft")}
            </button>
          </div>
        </footer>
      </form>
    </Modal>
  );
}

function PaymentModal({ invoice, open, onClose, onSuccess, money, t }) {
  const total = Number(invoice?.grand_total ?? invoice?.total_amount ?? 0);
  const balance = Math.max(
    0,
    Number(invoice?.remaining_balance ?? (total - Number(invoice?.total_paid || 0))),
  );
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [financialAccountId, setFinancialAccountId] = useState("");
  const [financialAccounts, setFinancialAccounts] = useState([]);
  const [paymentDate, setPaymentDate] = useState(todayIso());
  const [reference, setReference] = useState("");
  const [idempotencyKey, setIdempotencyKey] = useState(createRequestId);
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    setAmount(balance ? String(balance) : "");
    setMethod("cash");
    setFinancialAccountId("");
    setPaymentDate(todayIso());
    setReference("");
    setIdempotencyKey(createRequestId());
    setNote("");
    setError("");
  }, [invoice?.id, open]);

  useEffect(() => {
    if (!open) return undefined;
    let active = true;
    getFinancialAccounts()
      .then((response) => {
        if (!active) return;
        const accounts = response?.data || response;
        setFinancialAccounts(
          Array.isArray(accounts) ? accounts.filter((account) => account.is_active !== false) : [],
        );
      })
      .catch(() => {
        if (active) setFinancialAccounts([]);
      });
    return () => { active = false; };
  }, [open]);

  const submit = async (event) => {
    event.preventDefault();
    const value = Number(amount);
    if (!Number.isFinite(value) || value <= 0 || value > balance) {
      setError(t("invoice.paymentInvalid"));
      return;
    }
    setSaving(true);
    setError("");
    try {
      await processPayment(invoice.id, {
        amount: value,
        payment_method: method,
        financial_account_id: financialAccountId ? Number(financialAccountId) : undefined,
        payment_date: paymentDate,
        reference_number: reference.trim() || undefined,
        idempotency_key: idempotencyKey,
        note: note.trim() || undefined,
      });
      await onSuccess();
    } catch (err) {
      setError(apiError(err, t("invoice.paymentError")));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal open={open} title={t("invoice.recordPayment")} description={`${invoice?.invoice_number || ""} · ${money(balance)} ${t("invoice.remaining").toLowerCase()}`} onClose={onClose}>
      <form className="invoice-payment-form" onSubmit={submit}>
        {error ? <div className="invoice-alert is-error">{error}</div> : null}
        <label>
          {t("invoice.paymentAmount")}
          <input autoFocus type="number" min="0.01" max={balance} step="0.01" required value={amount} onChange={(e) => setAmount(e.target.value)} />
        </label>
        <label>
          {t("invoice.paymentMethod")}
          <select value={method} onChange={(e) => setMethod(e.target.value)}>
            <option value="cash">{t("invoice.methodCash")}</option>
            <option value="bank_transfer">{t("invoice.methodBank")}</option>
            <option value="card">{t("invoice.methodCard")}</option>
            <option value="cheque">{t("invoice.methodCheque")}</option>
            <option value="other">{t("invoice.methodOther")}</option>
          </select>
        </label>
        <label>
          {t("invoice.paymentDate")}
          <input type="date" max={todayIso()} required value={paymentDate} onChange={(e) => setPaymentDate(e.target.value)} />
        </label>
        <label>
          {t("payments.moneyAccount")}
          <select value={financialAccountId} onChange={(e) => setFinancialAccountId(e.target.value)}>
            <option value="">{t("payments.noMoneyAccount")}</option>
            {financialAccounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.name} · {account.type === "cashbox" ? t("finance.cash") : t("finance.bankTransfer")} ({account.currency || "EUR"})
              </option>
            ))}
          </select>
        </label>
        <label>
          {t("invoice.paymentReference")}
          <input maxLength="255" value={reference} onChange={(e) => setReference(e.target.value)} placeholder={t("invoice.paymentReferencePlaceholder")} />
        </label>
        <label className="payment-note-field">
          {t("invoice.paymentNote")}
          <textarea rows="2" value={note} onChange={(e) => setNote(e.target.value)} />
        </label>
        <div className="invoice-dialog-actions">
          <button type="button" className="secondary" onClick={onClose}>{t("common.cancel")}</button>
          <button type="submit" disabled={saving}>{saving ? <LoaderCircle className="is-spinning" size={16} /> : <Banknote size={16} />}{saving ? t("common.saving") : t("invoice.recordPayment")}</button>
        </div>
      </form>
    </Modal>
  );
}

function PaymentReversalModal({ payment, open, onClose, onConfirm, money, date, t }) {
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    setReason("");
    setError("");
  }, [payment?.id, open]);

  const submit = async (event) => {
    event.preventDefault();
    if (reason.trim().length < 3) {
      setError(t("invoice.paymentReversalReasonRequired"));
      return;
    }
    setSaving(true);
    setError("");
    try {
      await onConfirm(reason.trim());
    } catch (err) {
      setError(apiError(err, t("invoice.paymentReversalError")));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      title={t("invoice.reversePaymentTitle")}
      description={t("invoice.reversePaymentDescription")}
      onClose={onClose}
    >
      <form className="invoice-reason-form" onSubmit={submit}>
        <div className="invoice-action-warning">
          <RotateCcw size={21} />
          <span>
            <strong>{money(payment?.amount || 0)}</strong>
            <small>{date(payment?.payment_date || payment?.paid_at || payment?.created_at)} · {payment?.reference_number || payment?.transaction_ref || t("invoice.noReference")}</small>
          </span>
        </div>
        {error ? <div className="invoice-alert is-error" role="alert">{error}</div> : null}
        <label>
          {t("invoice.reversalReason")}
          <textarea autoFocus required minLength="3" maxLength="2000" rows="4" value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
        <div className="invoice-dialog-actions">
          <button type="button" className="secondary" onClick={onClose}>{t("common.cancel")}</button>
          <button type="submit" className="danger" disabled={saving}>
            {saving ? <LoaderCircle className="is-spinning" size={16} /> : <RotateCcw size={16} />}
            {saving ? t("common.saving") : t("invoice.confirmPaymentReversal")}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function ReasonModal({ action, invoice, open, onClose, onConfirm, t }) {
  const [reason, setReason] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const isCredit = action === "credit";

  useEffect(() => {
    setReason("");
    setError("");
  }, [action, invoice?.id, open]);

  const submit = async (event) => {
    event.preventDefault();
    if (reason.trim().length < 5) {
      setError(t("invoice.reasonRequired"));
      return;
    }
    setSaving(true);
    setError("");
    try {
      await onConfirm(reason.trim());
    } catch (err) {
      setError(apiError(err, t("invoice.actionError")));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={open}
      title={isCredit ? t("invoice.creditNoteTitle") : t("invoice.voidTitle")}
      description={isCredit ? t("invoice.creditNoteDescription") : t("invoice.voidDescription")}
      onClose={onClose}
    >
      <form className="invoice-reason-form" onSubmit={submit}>
        <div className={`invoice-action-warning ${isCredit ? "is-credit" : ""}`}>
          {isCredit ? <FilePenLine size={21} /> : <Ban size={21} />}
          <span><strong>{invoice?.invoice_number}</strong><small>{t("invoice.actionAuditHint")}</small></span>
        </div>
        {error ? <div className="invoice-alert is-error">{error}</div> : null}
        <label>
          {t("invoice.reason")}
          <textarea autoFocus required minLength="5" rows="4" value={reason} onChange={(e) => setReason(e.target.value)} />
        </label>
        <div className="invoice-dialog-actions">
          <button type="button" className="secondary" onClick={onClose}>{t("common.cancel")}</button>
          <button type="submit" className={isCredit ? "" : "danger"} disabled={saving}>
            {saving ? <LoaderCircle className="is-spinning" size={16} /> : isCredit ? <FilePenLine size={16} /> : <Ban size={16} />}
            {isCredit ? t("invoice.createCreditNote") : t("invoice.confirmVoid")}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function statusKey(invoice) {
  const rawStatus = String(invoice?.status || "draft").toLowerCase();
  const documentType = String(invoice?.document_type || "invoice").toLowerCase();
  const documentStatus = ["unpaid", "partially_paid", "paid", "sent", "overdue"].includes(rawStatus)
    ? "issued"
    : rawStatus;
  const total = Number(invoice?.grand_total ?? invoice?.total_amount ?? 0);
  const paid = Number(invoice?.total_paid || 0);
  const remaining = Number(invoice?.remaining_balance ?? Math.max(0, total - paid));
  let paymentStatus = String(invoice?.payment_status || "").toLowerCase();
  if (!paymentStatus && ["unpaid", "partially_paid", "paid", "overdue"].includes(rawStatus)) {
    paymentStatus = rawStatus;
  }
  if (!paymentStatus && documentStatus === "issued") {
    paymentStatus = remaining <= 0 ? "paid" : (paid > 0 ? "partially_paid" : "unpaid");
  }
  const dueDate = String(invoice?.due_date || invoice?.due_at || "").slice(0, 10);
  const isOverdue = documentType === "invoice"
    && documentStatus === "issued"
    && remaining > 0
    && Boolean(dueDate)
    && dueDate < todayIso();
  return { rawStatus, documentStatus, paymentStatus, documentType, isOverdue };
}

function StatusPills({ invoice, t }) {
  const { documentStatus, paymentStatus, documentType, isOverdue } = statusKey(invoice);
  const displayStatus = documentType === "credit_note" ? "credit_note" : documentStatus;
  const label = (status) => t(`invoice.status.${status}`);
  return (
    <span className="invoice-status-stack">
      <span className={`invoice-status status-${displayStatus}`}>{label(displayStatus)}</span>
      {documentStatus === "issued" && documentType !== "credit_note" && paymentStatus ? (
        <span className={`invoice-status payment-${paymentStatus}`}>{label(paymentStatus)}</span>
      ) : null}
      {isOverdue && paymentStatus !== "overdue" ? (
        <span className="invoice-status payment-overdue">{label("overdue")}</span>
      ) : null}
    </span>
  );
}

function InvoiceDetail({ invoice, profile, open, onClose, onEdit, onIssue, onPayment, onReversePayment, onPdf, onExcel, onReason, canProcessPayments, actionError, busy, money, date, t, language }) {
  if (!invoice) return null;
  const buyer = buyerSnapshot(invoice);
  const seller = invoice.company_snapshot || invoice.seller_snapshot || profile;
  const total = Number(invoice.total_amount ?? invoice.grand_total ?? 0);
  const paid = Number(invoice.total_paid || 0);
  const remaining = Number(invoice.remaining_balance ?? Math.max(0, total - paid));
  const { documentStatus, documentType } = statusKey(invoice);
  const isCreditNote = documentType === "credit_note";
  const signedMoney = (value) => money(isCreditNote ? -Math.abs(Number(value || 0)) : Number(value || 0));
  const isDraft = documentStatus === "draft";
  const terminal = documentType === "credit_note"
    || ["void", "voided", "cancelled", "credited", "credit_note"].includes(documentStatus);
  const canCredit = documentType === "invoice" && documentStatus === "issued" && paid <= 0;

  return (
    <Modal open={open} title={invoice.invoice_number || t("invoice.draftInvoice")} description={buyer.name} onClose={onClose} drawer>
      <div className="invoice-detail">
        <div className="invoice-detail-status"><StatusPills invoice={invoice} t={t} /></div>
        {actionError ? <div className="invoice-alert is-error" role="alert"><AlertTriangle size={16} />{actionError}</div> : null}
        <div className="invoice-detail-parties">
          <article>
            <span>{t("invoice.seller")}</span>
            <strong>{seller?.legal_name || seller?.name || "—"}</strong>
            <p>{seller?.registered_address || seller?.address || ""}{seller?.municipality || seller?.city ? `, ${seller.municipality || seller.city}` : ""}</p>
            <small>{seller?.fiscal_number ? `${t("invoice.fiscalNumber")}: ${seller.fiscal_number}` : ""}</small>
            <small>{seller?.vat_number ? `${t("invoice.vatNumber")}: ${seller.vat_number}` : ""}</small>
          </article>
          <article>
            <span>{t("invoice.buyer")}</span>
            <strong>{buyer.name || "—"}</strong>
            <p>{buyer.address || ""}</p>
            <small>{buyer.fiscal_number ? `${t("invoice.fiscalNumber")}: ${buyer.fiscal_number}` : ""}</small>
            <small>{buyer.vat_number ? `${t("invoice.vatNumber")}: ${buyer.vat_number}` : ""}</small>
          </article>
        </div>

        <dl className="invoice-detail-dates">
          <div><dt>{t("invoice.issueDate")}</dt><dd>{date(invoice.issued_at || invoice.invoice_date)}</dd></div>
          <div><dt>{t("invoice.supplyDate")}</dt><dd>{date(invoice.supply_at || invoice.supply_date)}</dd></div>
          <div><dt>{t("invoice.dueDate")}</dt><dd>{date(invoice.due_at || invoice.due_date)}</dd></div>
        </dl>

        <div className="invoice-detail-items">
          <table>
            <thead><tr><th>{t("invoice.description")}</th><th>{t("invoice.quantity")}</th><th>{t("invoice.unitPrice")}</th><th>{t("invoice.vatShort")}</th><th>{t("invoice.total")}</th></tr></thead>
            <tbody>
              {(invoice.items || []).map((item) => (
                <tr key={item.id || `${item.description}-${item.product_id}`}>
                  <td><strong>{item.description || item.product?.name}</strong><small>{item.sku_snapshot || item.sku || item.product?.sku || ""}</small></td>
                  <td>{formatQuantity(item.quantity, item.unit, language)} {item.unit}</td>
                  <td>{signedMoney(item.unit_price)}</td>
                  <td>{Number(item.vat_rate || 0)}%</td>
                  <td>{signedMoney(item.line_total ?? lineAmounts(item).total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="invoice-detail-totals">
          <dl>
            <div><dt>{t("invoice.subtotal")}</dt><dd>{signedMoney(invoice.subtotal ?? total)}</dd></div>
            <div><dt>{t("invoice.discountTotal")}</dt><dd>{signedMoney(invoice.discount_total || 0)}</dd></div>
            <div><dt>{t("invoice.vatTotal")}</dt><dd>{signedMoney(invoice.vat_total || 0)}</dd></div>
            <div className="grand-total"><dt>{t("invoice.grandTotal")}</dt><dd>{money(invoice.signed_total ?? (isCreditNote ? -total : total))}</dd></div>
            <div><dt>{t("invoice.paid")}</dt><dd>{money(paid)}</dd></div>
            <div className="remaining-total"><dt>{t("invoice.remaining")}</dt><dd>{money(remaining)}</dd></div>
          </dl>
        </div>

        {invoice.payment_terms ? <div className="invoice-detail-notes"><strong>{t("invoice.paymentInstructions")}</strong><p>{invoice.payment_terms}</p></div> : null}
        {invoice.notes ? <div className="invoice-detail-notes"><strong>{t("invoice.notes")}</strong><p>{invoice.notes}</p></div> : null}

        {invoice.payments?.length ? (
          <div className="invoice-payment-history">
            <h3>{t("invoice.paymentHistory")}</h3>
            {invoice.payments.map((payment) => {
              const paymentStatus = payment.reversed_at || payment.status === "reversed" ? "reversed" : "completed";
              const canReverse = canProcessPayments && paymentStatus === "completed" && !payment.reversed_at && !terminal;
              return (
                <div className={`invoice-payment-entry is-${paymentStatus}`} key={payment.id}>
                  <span>
                    <Banknote size={15} />
                    <strong>{money(payment.amount)}</strong>
                    <small>{date(payment.payment_date || payment.paid_at || payment.created_at)} · {payment.payment_method}</small>
                  </span>
                  <span className={`invoice-payment-state state-${paymentStatus}`}>{t(`invoice.paymentStatus.${paymentStatus}`)}</span>
                  <small>{payment.reference_number || payment.transaction_ref || t("invoice.noReference")}</small>
                  {payment.note ? <small>{payment.note}</small> : null}
                  {payment.reversed_at ? (
                    <small className="invoice-payment-reversal-note">
                      {t("invoice.reversedOn")} {date(payment.reversed_at)} · {payment.reversal_reason || t("invoice.noReason")}
                    </small>
                  ) : null}
                  {canReverse ? (
                    <button type="button" className="secondary invoice-reverse-payment" onClick={() => onReversePayment(payment)}>
                      <RotateCcw size={14} />{t("invoice.reversePayment")}
                    </button>
                  ) : null}
                </div>
              );
            })}
          </div>
        ) : null}

        <footer className="invoice-detail-actions">
          {isDraft ? <button type="button" className="secondary" onClick={onEdit}><Pencil size={16} />{t("invoice.editDraft")}</button> : null}
          {isDraft ? <button type="button" onClick={onIssue} disabled={Boolean(busy)}>{busy === "issue" ? <LoaderCircle className="is-spinning" size={16} /> : <FileCheck2 size={16} />}{t("invoice.issueInvoice")}</button> : null}
          {canProcessPayments && !isDraft && !terminal && remaining > 0 ? <button type="button" onClick={onPayment}><Banknote size={16} />{t("invoice.recordPayment")}</button> : null}
          {!isDraft ? <button type="button" className="secondary" onClick={onPdf} disabled={Boolean(busy)}>{busy === `pdf-${invoice.id}` ? <LoaderCircle className="is-spinning" size={16} /> : <Download size={16} />}{t("invoice.downloadPdf")}</button> : null}
          {!isDraft ? <button type="button" className="secondary" onClick={onExcel} disabled={Boolean(busy)}>{busy === `excel-${invoice.id}` ? <LoaderCircle className="is-spinning" size={16} /> : <FileSpreadsheet size={16} />}{t("invoice.downloadExcel")}</button> : null}
          {canCredit ? <button type="button" className="secondary" onClick={() => onReason("credit")}><FilePenLine size={16} />{t("invoice.creditNote")}</button> : null}
          {isDraft ? <button type="button" className="danger secondary-danger" onClick={() => onReason("void")}><Ban size={16} />{t("invoice.voidInvoice")}</button> : null}
        </footer>
      </div>
    </Modal>
  );
}

export default function Invoices() {
  const { t, language } = useTranslation();
  const location = useLocation();
  const permissions = useAuthStore((state) => state.permissions);
  const canManageInvoiceProfile = permissions.includes("invoice_profile.manage");
  const canManageCustomers = permissions.includes("customers.manage");
  const canProcessPayments = permissions.includes("payments.process");
  const [profileState, setProfileState] = useState(() => normalizeProfile({}));
  const [profileOpen, setProfileOpen] = useState(false);
  const [products, setProducts] = useState([]);
  const [customers, setCustomers] = useState([]);
  const [rows, setRows] = useState([]);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [filters, setFilters] = useState({ search: "", status: "", payment_status: "", date_from: "", date_to: "" });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [initializing, setInitializing] = useState(true);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [editor, setEditor] = useState({ open: false, invoice: null });
  const [selected, setSelected] = useState(null);
  const [detailOpen, setDetailOpen] = useState(false);
  const [paymentInvoice, setPaymentInvoice] = useState(null);
  const [reversingPayment, setReversingPayment] = useState(null);
  const [reasonAction, setReasonAction] = useState(null);
  const [actionBusy, setActionBusy] = useState("");
  const firstFilterRun = useRef(true);
  const createRequestHandled = useRef("");

  const money = useCallback(
    (value) => new Intl.NumberFormat(language === "sq" ? "sq-AL" : "en-IE", { style: "currency", currency: "EUR" }).format(Number(value || 0)),
    [language],
  );
  const date = useCallback(
    (value) => value ? new Intl.DateTimeFormat(language === "sq" ? "sq-AL" : "en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(String(value).slice(0, 10) + "T12:00:00")) : "—",
    [language],
  );

  const profileMissing = useMemo(
    () => profileMissingFields(profileState.profile, profileState.completeness),
    [profileState],
  );
  const profileComplete = profileState.completeness
    ? profileState.completeness.complete === true
    : profileMissing.length === 0;
  const overlayOpen = profileOpen || editor.open || detailOpen || Boolean(paymentInvoice) || Boolean(reversingPayment) || Boolean(reasonAction);

  useEffect(() => {
    if (!overlayOpen) return undefined;
    const oldOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { document.body.style.overflow = oldOverflow; };
  }, [overlayOpen]);

  const loadList = useCallback(async (targetPage = page, showLoading = true) => {
    if (showLoading) setLoading(true);
    try {
      const payload = await getInvoices({ ...filters, page: targetPage, per_page: 20 });
      const normalized = normalizeInvoiceList(payload);
      setRows(normalized.rows);
      setPagination(normalized.pagination);
      setError("");
    } catch (err) {
      setError(apiError(err, t("invoice.loadError")));
    } finally {
      if (showLoading) setLoading(false);
    }
  }, [filters, page, t]);

  useEffect(() => {
    let active = true;
    Promise.allSettled([
      getInvoiceProfile(),
      getAllProducts(),
      getAllInvoiceCustomers(),
    ]).then(([profileResult, productResult, customerResult]) => {
      if (!active) return;
      if (profileResult.status === "fulfilled") setProfileState(normalizeProfile(profileResult.value));
      else setError(apiError(profileResult.reason, t("invoice.profileLoadError")));
      if (productResult.status === "fulfilled") {
        setProducts(Array.isArray(productResult.value) ? productResult.value : productResult.value?.data || []);
      } else setError(apiError(productResult.reason, t("invoice.productLoadError")));
      if (customerResult.status === "fulfilled") {
        setCustomers(customerResult.value);
      } else setError(apiError(customerResult.reason, t("invoice.customerLoadError")));
      setInitializing(false);
    });
    return () => { active = false; };
  }, [t]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setPage(1);
      loadList(1, true);
      firstFilterRun.current = false;
    }, firstFilterRun.current ? 0 : 300);
    return () => window.clearTimeout(timer);
  }, [filters.search, filters.status, filters.payment_status, filters.date_from, filters.date_to]);

  useEffect(() => {
    if (page !== 1) void loadList(page);
  }, [page]);

  useEffect(() => {
    const refresh = () => {
      if (overlayOpen) return;
      void loadList(page, false);
    };
    window.addEventListener("database-refresh", refresh);
    return () => window.removeEventListener("database-refresh", refresh);
  }, [loadList, overlayOpen, page]);

  useEffect(() => {
    const invoiceId = new URLSearchParams(location.search).get("invoice");
    if (!invoiceId) return;
    getInvoice(invoiceId).then((result) => {
      setSelected(unwrapInvoice(result));
      setDetailOpen(true);
    }).catch(() => {});
  }, [location.search]);

  const reloadSelected = async (id = selected?.id) => {
    if (!id) return null;
    const invoice = unwrapInvoice(await getInvoice(id));
    setSelected(invoice);
    return invoice;
  };

  const openDetail = async (invoice) => {
    setActionBusy(`detail-${invoice.id}`);
    setError("");
    try {
      const detail = unwrapInvoice(await getInvoice(invoice.id));
      setSelected(detail);
      setDetailOpen(true);
    } catch (err) {
      setError(apiError(err, t("invoice.detailError")));
    } finally {
      setActionBusy("");
    }
  };

  const saveProfile = async (payload) => {
    const result = await updateInvoiceProfile(payload);
    setProfileState(normalizeProfile(result));
    setProfileOpen(false);
    setMessage(t("invoice.profileSaved"));
  };

  const startCreate = () => {
    if (!profileComplete) {
      if (canManageInvoiceProfile) setProfileOpen(true);
      else setError(t("invoice.profileRequiresAdministrator"));
      return;
    }
    setEditor({ open: true, invoice: null });
  };

  useEffect(() => {
    const requested = new URLSearchParams(location.search).get("new") === "1";
    if (!requested || initializing || createRequestHandled.current === location.search) return;
    if (!profileComplete) {
      if (canManageInvoiceProfile) setProfileOpen(true);
      else {
        createRequestHandled.current = location.search;
        setError(t("invoice.profileRequiresAdministrator"));
      }
      return;
    }
    createRequestHandled.current = location.search;
    setEditor({ open: true, invoice: null });
  }, [canManageInvoiceProfile, initializing, location.search, profileComplete, t]);

  const afterInvoiceSaved = async (created, issuedNow, options = {}) => {
    setEditor({ open: false, invoice: null });
    if (options.issueError) {
      setError(options.issueError);
      setMessage(t("invoice.draftSaved"));
    } else {
      setMessage(issuedNow ? t("invoice.createdIssued") : t("invoice.draftSaved"));
    }
    await loadList(1, false);
    if (canManageCustomers) {
      getAllInvoiceCustomers().then(setCustomers).catch(() => {});
    }
    if (created?.id) {
      const detail = await reloadSelected(created.id).catch(() => created);
      setSelected(detail || created);
      setDetailOpen(true);
    }
    if (issuedNow) {
      window.dispatchEvent(new CustomEvent("stock-refresh"));
      window.dispatchEvent(new CustomEvent("dashboard-refresh"));
    }
  };

  const handleIssue = async () => {
    if (!selected || actionBusy) return;
    setActionBusy("issue");
    setError("");
    try {
      await issueInvoice(selected.id);
      await Promise.all([loadList(page, false), reloadSelected(selected.id)]);
      setMessage(t("invoice.issuedSuccess"));
      window.dispatchEvent(new CustomEvent("stock-refresh"));
      window.dispatchEvent(new CustomEvent("dashboard-refresh"));
    } catch (err) {
      setError(apiError(err, t("invoice.issueError")));
    } finally {
      setActionBusy("");
    }
  };

  const handlePdf = async (invoice = selected) => {
    if (!invoice || actionBusy) return;
    setActionBusy(`pdf-${invoice.id}`);
    setError("");
    try {
      await downloadInvoicePdf(
        invoice.id,
        invoice.invoice_number,
        profileState.profile.default_language || language,
      );
    } catch (err) {
      setError(apiError(err, t("invoice.pdfError")));
    } finally {
      setActionBusy("");
    }
  };

  const handleExcel = async (invoice = selected) => {
    if (!invoice || actionBusy) return;
    setActionBusy(`excel-${invoice.id}`);
    setError("");
    try {
      await downloadInvoiceExcel(
        invoice.id,
        invoice.invoice_number,
        profileState.profile.default_language || language,
      );
    } catch (err) {
      setError(apiError(err, t("invoice.excelError")));
    } finally {
      setActionBusy("");
    }
  };

  const afterPayment = async () => {
    const id = paymentInvoice?.id;
    setPaymentInvoice(null);
    await Promise.all([loadList(page, false), id ? reloadSelected(id) : Promise.resolve()]);
    if (id) setDetailOpen(true);
    setMessage(t("invoice.paymentSaved"));
  };

  const closePayment = () => {
    setPaymentInvoice(null);
    if (selected) setDetailOpen(true);
  };

  const openPaymentReversal = (payment) => {
    setDetailOpen(false);
    setReversingPayment(payment);
  };

  const closePaymentReversal = () => {
    setReversingPayment(null);
    if (selected) setDetailOpen(true);
  };

  const confirmPaymentReversal = async (reason) => {
    if (!reversingPayment?.id) return;
    const invoiceId = selected?.id || reversingPayment.invoice_id;
    await reversePayment(reversingPayment.id, reason);
    await loadList(page, false);
    if (invoiceId) await reloadSelected(invoiceId).catch(() => null);
    setReversingPayment(null);
    if (invoiceId) setDetailOpen(true);
    setMessage(t("invoice.paymentReversedSuccess"));
    window.dispatchEvent(new CustomEvent("dashboard-refresh"));
  };

  const closeReasonAction = () => {
    setReasonAction(null);
    if (selected) setDetailOpen(true);
  };

  const confirmReasonAction = async (reason) => {
    if (!reasonAction?.invoice) return;
    const target = reasonAction.invoice;
    if (reasonAction.type === "credit") await createInvoiceCreditNote(target.id, reason);
    else await voidInvoice(target.id, reason);
    setReasonAction(null);
    setDetailOpen(false);
    setSelected(null);
    await loadList(page, false);
    setMessage(reasonAction.type === "credit" ? t("invoice.creditCreated") : t("invoice.voidedSuccess"));
    window.dispatchEvent(new CustomEvent("stock-refresh"));
    window.dispatchEvent(new CustomEvent("dashboard-refresh"));
  };

  const changeFilter = (field, value) => setFilters((current) => ({ ...current, [field]: value }));
  const clearFilters = () => setFilters({ search: "", status: "", payment_status: "", date_from: "", date_to: "" });

  const missingLabel = (field) => t(`invoice.profileField.${field}`);

  return (
    <main className="invoices-page page-stack">
      <section className="invoice-page-hero">
        <div className="invoice-page-heading">
          <span className="invoice-page-icon"><ReceiptText size={24} /></span>
          <div>
            <span className="invoice-eyebrow">{t("invoice.financeWorkspace")}</span>
            <h1>{t("invoice.title")}</h1>
            <p>{t("invoice.subtitle")}</p>
          </div>
        </div>
        <div className="invoice-page-actions">
          {canManageInvoiceProfile ? <button type="button" className="secondary" onClick={() => setProfileOpen(true)}><Settings2 size={17} />{t("invoice.companyProfile")}</button> : null}
          <button type="button" onClick={startCreate} disabled={initializing}><FilePlus2 size={17} />{t("invoice.newInvoice")}</button>
        </div>
      </section>

      {!profileComplete ? (
        <section className="invoice-profile-gate">
          <div className="invoice-profile-gate-icon"><AlertTriangle size={22} /></div>
          <div>
            <h2>{t("invoice.profileIncomplete")}</h2>
            <p>{t("invoice.profileIncompleteHint")}</p>
            <div className="invoice-missing-fields">
              {profileMissing.map((field) => <span key={field}>{missingLabel(field)}</span>)}
            </div>
          </div>
          {canManageInvoiceProfile ? (
            <button type="button" onClick={() => setProfileOpen(true)}>{t("invoice.completeProfile")}</button>
          ) : (
            <small className="invoice-profile-admin-note">{t("invoice.profileRequiresAdministrator")}</small>
          )}
        </section>
      ) : (
        <section className="invoice-profile-ready">
          <span><BadgeCheck size={18} /></span>
          <div><strong>{profileState.profile.legal_name}</strong><small>{t("invoice.profileReady")}</small></div>
          <dl>
            <div><dt>{t("invoice.fiscalNumber")}</dt><dd>{profileState.profile.fiscal_number}</dd></div>
            {profileState.profile.is_vat_registered ? <div><dt>{t("invoice.vatNumber")}</dt><dd>{profileState.profile.vat_number}</dd></div> : null}
          </dl>
        </section>
      )}

      <section className="invoice-efs-warning invoice-page-efs-warning" role="note">
        <AlertTriangle size={18} />
        <div><strong>{t("invoice.efsWarningTitle")}</strong><p>{t("invoice.efsWarningBody")}</p></div>
      </section>

      {error ? <div className="invoice-alert is-error"><AlertTriangle size={17} />{error}<button type="button" onClick={() => setError("")}><X size={15} /></button></div> : null}
      {message ? <div className="invoice-alert is-success"><BadgeCheck size={17} />{message}<button type="button" onClick={() => setMessage("")}><X size={15} /></button></div> : null}

      <section className="card invoice-list-card">
        <div className="invoice-list-header">
          <div><h2>{t("invoice.register")}</h2><p>{t("invoice.registerHint")}</p></div>
          <span>{pagination.total} {t("invoice.documents")}</span>
        </div>
        <div className="invoice-filters">
          <label className="invoice-search-field">
            <Search size={17} />
            <input type="search" value={filters.search} onChange={(e) => changeFilter("search", e.target.value)} placeholder={t("invoice.searchPlaceholder")} />
          </label>
          <label>
            <span>{t("invoice.documentStatus")}</span>
            <select value={filters.status} onChange={(e) => changeFilter("status", e.target.value)}>
              <option value="">{t("invoice.allStatuses")}</option>
              <option value="draft">{t("invoice.status.draft")}</option>
              <option value="issued">{t("invoice.status.issued")}</option>
              <option value="void">{t("invoice.status.void")}</option>
              <option value="credited">{t("invoice.status.credited")}</option>
            </select>
          </label>
          <label>
            <span>{t("invoice.paymentStatus")}</span>
            <select value={filters.payment_status} onChange={(e) => changeFilter("payment_status", e.target.value)}>
              <option value="">{t("invoice.allStatuses")}</option>
              <option value="unpaid">{t("invoice.status.unpaid")}</option>
              <option value="partially_paid">{t("invoice.status.partially_paid")}</option>
              <option value="paid">{t("invoice.status.paid")}</option>
              <option value="overdue">{t("invoice.status.overdue")}</option>
            </select>
          </label>
          <label><span>{t("invoice.dateFrom")}</span><input type="date" value={filters.date_from} onChange={(e) => changeFilter("date_from", e.target.value)} /></label>
          <label><span>{t("invoice.dateTo")}</span><input type="date" min={filters.date_from} value={filters.date_to} onChange={(e) => changeFilter("date_to", e.target.value)} /></label>
          <button type="button" className="secondary invoice-clear-filters" onClick={clearFilters}>{t("invoice.clearFilters")}</button>
        </div>

        <div className="invoice-table-wrap">
          <table className="invoice-table">
            <thead><tr><th>{t("invoice.number")}</th><th>{t("invoice.buyer")}</th><th>{t("invoice.issueDate")}</th><th>{t("invoice.statusLabel")}</th><th>{t("invoice.total")}</th><th>{t("invoice.remaining")}</th><th>{t("invoice.actions")}</th></tr></thead>
            <tbody>
              {loading ? (
                Array.from({ length: 5 }).map((_, index) => <tr className="invoice-skeleton-row" key={index}><td colSpan="7"><span /></td></tr>)
              ) : rows.length === 0 ? (
                <tr><td colSpan="7"><div className="invoice-empty"><ReceiptText size={34} /><strong>{t("invoice.empty")}</strong><p>{t("invoice.emptyHint")}</p><button type="button" onClick={startCreate}><Plus size={16} />{t("invoice.newInvoice")}</button></div></td></tr>
              ) : rows.map((invoice) => {
                const buyer = buyerSnapshot(invoice);
                const total = Number(invoice.total_amount ?? invoice.grand_total ?? 0);
                const displayedTotal = Number(invoice.signed_total ?? total);
                const remaining = Number(invoice.remaining_balance ?? Math.max(0, total - Number(invoice.total_paid || 0)));
                return (
                  <tr key={invoice.id}>
                    <td><strong className="invoice-number">{invoice.invoice_number || t("invoice.draftInvoice")}</strong>{invoice.original_invoice_id ? <small>{t("invoice.correctionDocument")}</small> : null}</td>
                    <td><strong>{buyer.name || "—"}</strong><small>{buyer.fiscal_number || buyer.vat_number || ""}</small></td>
                    <td>{date(invoice.issued_at || invoice.invoice_date)}</td>
                    <td><StatusPills invoice={invoice} t={t} /></td>
                    <td><strong>{money(displayedTotal)}</strong></td>
                    <td><strong className={remaining > 0 ? "amount-due" : "amount-paid"}>{money(remaining)}</strong></td>
                    <td>
                      <div className="invoice-row-actions">
                        <button type="button" className="secondary" disabled={actionBusy === `detail-${invoice.id}`} onClick={() => openDetail(invoice)}>
                          {actionBusy === `detail-${invoice.id}` ? <LoaderCircle className="is-spinning" size={15} /> : <Eye size={15} />}{t("invoice.view")}
                        </button>
                        {String(invoice.status).toLowerCase() !== "draft" ? (
                          <>
                            <button type="button" className="secondary invoice-download-button" disabled={Boolean(actionBusy)} onClick={() => handlePdf(invoice)}>
                              {actionBusy === `pdf-${invoice.id}` ? <LoaderCircle className="is-spinning" size={15} /> : <Download size={15} />}
                              <span>PDF</span>
                            </button>
                            <button type="button" className="secondary invoice-download-button" disabled={Boolean(actionBusy)} onClick={() => handleExcel(invoice)}>
                              {actionBusy === `excel-${invoice.id}` ? <LoaderCircle className="is-spinning" size={15} /> : <FileSpreadsheet size={15} />}
                              <span>XLSX</span>
                            </button>
                          </>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {pagination.last_page > 1 ? (
          <div className="invoice-pagination">
            <button type="button" className="secondary" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}><ChevronLeft size={16} />{t("common.previous")}</button>
            <span>{t("common.page")} <strong>{pagination.current_page}</strong> / {pagination.last_page}</span>
            <button type="button" className="secondary" disabled={page >= pagination.last_page || loading} onClick={() => setPage((value) => value + 1)}>{t("common.next")}<ChevronRight size={16} /></button>
          </div>
        ) : null}
      </section>

      {canManageInvoiceProfile ? <CompanyProfileModal open={profileOpen} profile={profileState.profile} onClose={() => setProfileOpen(false)} onSave={saveProfile} t={t} /> : null}
      <InvoiceEditor open={editor.open} invoice={editor.invoice} profile={profileState.profile} products={products} customers={customers} canSaveCustomer={canManageCustomers} onClose={() => setEditor({ open: false, invoice: null })} onSaved={afterInvoiceSaved} t={t} language={language} />
      <InvoiceDetail
        invoice={selected}
        profile={profileState.profile}
        open={detailOpen}
        onClose={() => setDetailOpen(false)}
        onEdit={() => { setDetailOpen(false); setEditor({ open: true, invoice: selected }); }}
        onIssue={handleIssue}
        onPayment={() => { setDetailOpen(false); setPaymentInvoice(selected); }}
        onReversePayment={openPaymentReversal}
        onPdf={() => handlePdf(selected)}
        onExcel={() => handleExcel(selected)}
        onReason={(type) => { setDetailOpen(false); setReasonAction({ type, invoice: selected }); }}
        canProcessPayments={canProcessPayments}
        actionError={error}
        busy={actionBusy}
        money={money}
        date={date}
        t={t}
        language={language}
      />
      <PaymentModal invoice={paymentInvoice} open={Boolean(paymentInvoice)} onClose={closePayment} onSuccess={afterPayment} money={money} t={t} />
      <PaymentReversalModal payment={reversingPayment} open={Boolean(reversingPayment)} onClose={closePaymentReversal} onConfirm={confirmPaymentReversal} money={money} date={date} t={t} />
      <ReasonModal action={reasonAction?.type} invoice={reasonAction?.invoice} open={Boolean(reasonAction)} onClose={closeReasonAction} onConfirm={confirmReasonAction} t={t} />
    </main>
  );
}
