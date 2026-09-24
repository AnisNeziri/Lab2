import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { Plus, Truck } from "lucide-react";
import { getAllProducts } from "../api/products";
import { getSuppliers } from "../api/suppliers";
import {
  cancelPurchaseOrder,
  changePurchaseOrderStatus,
  createPurchaseOrder,
  deletePurchaseOrder,
  downloadPurchaseOrderStatement,
  getPurchaseOrder,
  getPurchaseOrders,
  receivePurchaseOrder,
  recordPurchaseOrderPayment,
  reversePurchaseOrderPayment,
  updatePurchaseOrder,
} from "../api/purchaseOrders";
import { useTranslation } from "../hooks/useTranslation";
import SuccessAnimation from "../components/SuccessAnimation";
import { getWarehouses, getWarehouseLocations } from "../api/warehouseOperations";
import { getFinancialAccounts } from "../api/advancedOperations";
import { useAuthStore } from "../store/authStore";
import EntityContext from "../components/EntityContext";

const today = () => new Date().toISOString().slice(0, 10);
const dateOnly = (value) => (value ? String(value).slice(0, 10) : "—");
const money = (value, currency = "EUR") =>
  new Intl.NumberFormat("de-DE", { style: "currency", currency }).format(
    Number(value || 0),
  );
const emptyLine = () => ({
  id: null,
  product_id: "",
  description: "",
  unit: "pcs",
  quantity: 1,
  unit_price: 0,
});
const emptyOrder = () => ({
  supplier_id: "",
  ordered_at: today(),
  expected_at: "",
  due_at: "",
  currency: "EUR",
  warehouse_id: "",
  exchange_rate: "",
  exchange_rate_date: today(),
  exchange_rate_source: "",
  status: "draft",
  notes: "",
  change_reason: "",
  items: [emptyLine()],
});
const emptyPayment = () => ({
  amount: "",
  expense_id: "",
  payment_date: today(),
  payment_method: "bank_transfer",
  financial_account_id: "",
  reference_number: "",
  note: "",
  idempotency_key: crypto.randomUUID(),
});
const errorText = (error) =>
  error?.errors ? Object.values(error.errors).flat().join(" ") : error?.message;
const emptyTrace = () => ({
  lot_number: "",
  serial_numbers: "",
  supplier_batch: "",
  manufactured_at: "",
  expiry_at: "",
});
const receiptBaseQuantity = (line, state) => {
  const quantity = Number(line[`${state}_quantity`] || 0);
  if (line.conversion_mode === "variable") {
    return Number(line[`${state}_base_quantity`] || 0);
  }
  if (line.conversion_mode === "fixed") {
    return quantity * Number(line.conversion_factor || 1);
  }
  return quantity;
};
const traceAllocationsFor = (line, state) => {
  const quantity = receiptBaseQuantity(line, state);
  if (quantity <= 0 || line.tracking_mode === "none") return [];
  const trace = line[`${state}_trace`] || emptyTrace();
  if (line.tracking_mode === "serial") {
    return String(trace.serial_numbers || "")
      .split(/[\n,;]+/)
      .map((serial) => serial.trim())
      .filter(Boolean)
      .map((serial_number) => ({
        stock_state: state === "accepted" ? "available" : "damaged",
        serial_number,
        manufactured_at: trace.manufactured_at || null,
        expiry_at: trace.expiry_at || null,
        quantity: 1,
      }));
  }
  return [{
    stock_state: state === "accepted" ? "available" : "damaged",
    lot_number: trace.lot_number || null,
    supplier_batch: trace.supplier_batch || null,
    manufactured_at: trace.manufactured_at || null,
    expiry_at: trace.expiry_at || null,
    quantity,
  }];
};

export default function PurchaseOrders() {
  const { t } = useTranslation();
  const permissions = useAuthStore((state) => state.permissions);
  const canOverrideExpiredReceipt = permissions.includes("inventory.expired.override");
  const [orders, setOrders] = useState([]);
  const [pagination, setPagination] = useState({
    current_page: 1,
    last_page: 1,
  });
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [locations, setLocations] = useState([]);
  const [financialAccounts, setFinancialAccounts] = useState([]);
  const [selected, setSelected] = useState(null);
  const [mode, setMode] = useState("");
  const [form, setForm] = useState(emptyOrder());
  const [payment, setPayment] = useState(emptyPayment());
  const [receipt, setReceipt] = useState([]);
  const [receiptKey, setReceiptKey] = useState("");
  const [receiptMeta, setReceiptMeta] = useState({ warehouse_id: "", location_id: "", received_at: today(), supplier_document_number: "", notes: "", allow_expired_receipt: false, expired_receipt_reason: "" });
  const [statusForm, setStatusForm] = useState({
    status: "ordered",
    reason: "",
  });
  const [search, setSearch] = useState("");
  const [supplierFilter, setSupplierFilter] = useState("");
  const [orderStatus, setOrderStatus] = useState("");
  const [paymentStatus, setPaymentStatus] = useState("");
  const [orderedDate, setOrderedDate] = useState("");
  const [dueDate, setDueDate] = useState("");
  const [sort, setSort] = useState("recent");
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [shipmentSuccess, setShipmentSuccess] = useState(null);

  const exportStatement = async () => {
    setError("");
    try {
      await downloadPurchaseOrderStatement(selected.id);
    } catch {
      setError(t("po.exportError"));
    }
  };

  const loadList = useCallback(
    async (requestedPage = page, showLoading = true) => {
      if (showLoading) setLoading(true);
      try {
        const response = await getPurchaseOrders({
          search,
          supplier_id: supplierFilter || undefined,
          status: orderStatus || undefined,
          payment_status: paymentStatus || undefined,
          ordered_at: orderedDate || undefined,
          due_at: dueDate || undefined,
          sort,
          page: requestedPage,
          per_page: 20,
        });
        setOrders(Array.isArray(response.data) ? response.data : []);
        setPagination({
          current_page: response.current_page || 1,
          last_page: response.last_page || 1,
        });
        setError("");
      } catch (e) {
        setError(errorText(e) || t("po.loadError"));
      } finally {
        if (showLoading) setLoading(false);
      }
    },
    [
      page,
      search,
      supplierFilter,
      orderStatus,
      paymentStatus,
      orderedDate,
      dueDate,
      sort,
      t,
    ],
  );

  useEffect(() => {
    Promise.all([getSuppliers(), getAllProducts(), getWarehouses(), getWarehouseLocations()])
      .then(([supplierRows, productRows, warehouseRows, locationRows]) => {
        const supplierList = supplierRows.data || supplierRows;
        const productList = productRows.data || productRows;
        setSuppliers(Array.isArray(supplierList) ? supplierList : []);
        setProducts(Array.isArray(productList) ? productList : []);
        setWarehouses(Array.isArray(warehouseRows) ? warehouseRows : []);
        setLocations(Array.isArray(locationRows) ? locationRows : []);
      })
      .catch((e) => setError(errorText(e)));
  }, []);

  useEffect(() => {
    let active = true;
    getFinancialAccounts()
      .then((response) => {
        if (!active) return;
        const accounts = response?.data || response;
        setFinancialAccounts(Array.isArray(accounts) ? accounts.filter((account) => account.is_active !== false) : []);
      })
      .catch(() => { if (active) setFinancialAccounts([]); });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    setPage(1);
    loadList(1);
  }, [
    search,
    supplierFilter,
    orderStatus,
    paymentStatus,
    orderedDate,
    dueDate,
    sort,
  ]);

  useEffect(() => {
    const refresh = async () => {
      await loadList(page, false);
      if (selected?.id) setSelected(await getPurchaseOrder(selected.id));
    };
    window.addEventListener("database-refresh", refresh);
    return () => window.removeEventListener("database-refresh", refresh);
  }, [loadList, page, selected?.id]);

  const formTotal = useMemo(
    () =>
      form.items.reduce(
        (sum, line) =>
          sum + Number(line.quantity || 0) * Number(line.unit_price || 0),
        0,
      ),
    [form.items],
  );
  const paymentRemaining = useMemo(
    () =>
      Math.max(
        0,
        Number(selected?.remaining_balance || 0) - Number(payment.amount || 0),
      ),
    [selected?.remaining_balance, payment.amount],
  );
  const paymentTargetInvoice = useMemo(
    () => (selected?.supplier_invoices || []).find((invoice) => String(invoice.id) === String(payment.expense_id)),
    [selected?.supplier_invoices, payment.expense_id],
  );
  const paymentMaximum = paymentTargetInvoice
    ? Math.min(Number(selected?.remaining_balance || 0), Number(paymentTargetInvoice.remaining_amount || 0))
    : Number(selected?.remaining_balance || 0);

  const refreshSelected = async (id = selected?.id) => {
    await loadList(page);
    if (id) setSelected(await getPurchaseOrder(id));
  };

  const openOrder = async (id) => {
    setLoading(true);
    try {
      setSelected(await getPurchaseOrder(id));
      setMode("");
      setError("");
    } catch (e) {
      setError(errorText(e));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const requestedOrder = Number(new URLSearchParams(window.location.search).get("po"));
    if (Number.isInteger(requestedOrder) && requestedOrder > 0) openOrder(requestedOrder);
  }, []);

  const beginNew = () => {
    setSelected(null);
    const primary = warehouses.find((warehouse) => warehouse.is_default);
    setForm({ ...emptyOrder(), warehouse_id: primary ? String(primary.id) : "" });
    setMode("order");
    setError("");
    setMessage("");
  };
  const beginEdit = () => {
    setForm({
      supplier_id: String(selected.supplier_id),
      ordered_at: dateOnly(selected.ordered_at),
      expected_at: selected.expected_at || "",
      due_at: selected.due_at || "",
      currency: selected.currency,
      warehouse_id: selected.warehouse_id ? String(selected.warehouse_id) : "",
      exchange_rate: selected.exchange_rate || "",
      exchange_rate_date: dateOnly(selected.exchange_rate_date) === "â€”" ? today() : dateOnly(selected.exchange_rate_date),
      exchange_rate_source: selected.exchange_rate_source || "",
      status: ["draft", "confirmed", "ordered"].includes(selected.status)
        ? selected.status
        : "ordered",
      notes: selected.notes || "",
      change_reason: "",
      items: selected.items.map((line) => ({
        id: line.id,
        product_id: line.product_id ? String(line.product_id) : "",
        description: line.description,
        unit: line.unit,
        quantity: line.quantity,
        unit_price: line.unit_price,
      })),
    });
    setMode("order");
  };
  const beginPayment = () => {
    setPayment(emptyPayment());
    setMode("payment");
  };
  const beginReceipt = () => {
    setReceiptKey(crypto.randomUUID());
    setReceiptMeta({ warehouse_id: selected.warehouse_id ? String(selected.warehouse_id) : String(warehouses.find((warehouse) => warehouse.is_default)?.id || ""), location_id: "", received_at: today(), supplier_document_number: "", notes: "", allow_expired_receipt: false, expired_receipt_reason: "" });
    setReceipt(
      selected.items
        .filter((item) => Number(item.remaining_quantity) > 0)
        .map((item) => ({
          id: item.id,
          description: item.description,
          remaining: item.remaining_quantity,
          unit: item.unit,
          inventory_unit: item.inventory_unit,
          conversion_mode: item.conversion_mode,
          conversion_factor: item.conversion_factor,
          tracking_mode: item.product?.tracking_mode || "none",
          expiration_controlled: Boolean(item.product?.expiration_controlled || item.product?.tracking_mode === "batch_expiry"),
          default_shelf_life_days: item.product?.default_shelf_life_days || null,
          shelf_life_basis: item.product?.shelf_life_basis || "manufacture_date",
          accepted_quantity: item.remaining_quantity,
          damaged_quantity: 0,
          rejected_quantity: 0,
          accepted_base_quantity: item.conversion_mode === "variable" ? "" : null,
          damaged_base_quantity: item.conversion_mode === "variable" ? "" : null,
          accepted_trace: emptyTrace(),
          damaged_trace: emptyTrace(),
          notes: "",
        })),
    );
    setMode("receive");
  };
  const beginStatus = () => {
    setStatusForm({
      status: selected.status === "received" ? "completed" : selected.status,
      reason: "",
    });
    setMode("status");
  };

  const updateLine = (index, field, value) => {
    setForm((current) => ({
      ...current,
      items: current.items.map((line, lineIndex) => {
        if (lineIndex !== index) return line;
        const next = { ...line, [field]: value };
        if (field === "product_id") {
          const product = products.find(
            (candidate) => String(candidate.id) === value,
          );
          if (product) {
            next.description = product.name;
            next.unit = product.unit || "pcs";
            next.unit_price = product.purchase_price || product.price || 0;
          }
        }
        return next;
      }),
    }));
  };

  const submitOrder = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const payload = {
        ...form,
        supplier_id: Number(form.supplier_id),
        expected_at: form.expected_at || null,
        due_at: form.due_at || null,
        warehouse_id: form.warehouse_id ? Number(form.warehouse_id) : null,
        exchange_rate: form.currency === "EUR" ? null : Number(form.exchange_rate),
        exchange_rate_date: form.currency === "EUR" ? null : form.exchange_rate_date,
        exchange_rate_source: form.currency === "EUR" ? null : form.exchange_rate_source,
        notes: form.notes || null,
        change_reason: form.change_reason || null,
        items: form.items.map((line) => ({
          id: line.id || undefined,
          product_id: line.product_id ? Number(line.product_id) : null,
          description: line.description,
          unit: line.unit,
          quantity: Number(line.quantity),
          unit_price: Number(line.unit_price),
        })),
      };
      const saved = selected
        ? await updatePurchaseOrder(selected.id, payload)
        : await createPurchaseOrder(payload);
      setSelected(saved);
      setMode("");
      setMessage(t("po.saved"));
      await refreshSelected(saved.id);
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const submitPayment = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      await recordPurchaseOrderPayment(selected.id, {
        ...payment,
        amount: Number(payment.amount),
        expense_id: payment.expense_id ? Number(payment.expense_id) : null,
        financial_account_id: payment.financial_account_id ? Number(payment.financial_account_id) : undefined,
        reference_number: payment.reference_number || null,
        note: payment.note || null,
        idempotency_key: payment.idempotency_key,
      });
      setMode("");
      setMessage(t("po.paymentSaved"));
      await refreshSelected();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const reverseRecordedPayment = async (row) => {
    const reason = window.prompt(t("po.paymentReversalPrompt"));
    if (!reason || reason.trim().length < 3 || busy) return;
    setBusy(true);
    setError("");
    try {
      await reversePurchaseOrderPayment(row.id, reason.trim());
      setMessage(t("po.paymentReversed"));
      await refreshSelected();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const submitReceipt = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      await receivePurchaseOrder(selected.id, {
        idempotency_key: receiptKey,
        warehouse_id: receiptMeta.warehouse_id ? Number(receiptMeta.warehouse_id) : null,
        location_id: receiptMeta.location_id ? Number(receiptMeta.location_id) : null,
        received_at: receiptMeta.received_at || null,
        supplier_document_number: receiptMeta.supplier_document_number || null,
        notes: receiptMeta.notes || null,
        allow_expired_receipt: canOverrideExpiredReceipt && Boolean(receiptMeta.allow_expired_receipt),
        expired_receipt_reason: canOverrideExpiredReceipt && receiptMeta.allow_expired_receipt ? receiptMeta.expired_receipt_reason.trim() : null,
        items: receipt
          .filter((line) => Number(line.accepted_quantity) + Number(line.damaged_quantity) + Number(line.rejected_quantity) > 0)
          .map((line) => ({
            id: line.id,
            accepted_quantity: Number(line.accepted_quantity || 0),
            damaged_quantity: Number(line.damaged_quantity || 0),
            rejected_quantity: Number(line.rejected_quantity || 0),
            accepted_base_quantity: line.accepted_base_quantity === null || line.accepted_base_quantity === "" ? null : Number(line.accepted_base_quantity),
            damaged_base_quantity: line.damaged_base_quantity === null || line.damaged_base_quantity === "" ? null : Number(line.damaged_base_quantity),
            trace_allocations: [
              ...traceAllocationsFor(line, "accepted"),
              ...traceAllocationsFor(line, "damaged"),
            ],
            notes: line.notes || null,
          })),
      });
      setShipmentSuccess({ reference: selected.po_number });
      setMode("");
      setMessage(t("po.receivedSaved"));
      await refreshSelected();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const submitStatus = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    try {
      await changePurchaseOrderStatus(selected.id, statusForm);
      setMode("");
      setMessage(t("po.statusSaved"));
      await refreshSelected();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const cancelOrder = async () => {
    if (!window.confirm(t("po.cancelConfirm"))) return;
    const reason = window.prompt(t("po.cancelReason")) || "";
    setBusy(true);
    setError("");
    try {
      await cancelPurchaseOrder(selected.id, reason);
      setMessage(t("po.cancelled"));
      await refreshSelected();
    } catch (e) {
      setError(errorText(e));
    } finally {
      setBusy(false);
    }
  };

  const removeCompletedOrder = async () => {
    if (!window.confirm(t("po.deleteConfirm"))) return;
    setBusy(true);
    setError("");
    try {
      await deletePurchaseOrder(selected.id);
      setSelected(null);
      setMode("");
      setMessage(t("po.deleted"));
      await loadList(page);
    } catch (e) {
      setError(errorText(e) || t("po.deleteError"));
    } finally {
      setBusy(false);
    }
  };

  const statusText = (value) => t(`po.status.${value}`);
  const paymentStatusText = (value) => t(`po.paymentStatus.${value}`);
  const paymentMethodText = (value) => t(`po.method.${value}`);

  return (
    <main className="page-stack">
      <SuccessAnimation
        open={Boolean(shipmentSuccess)}
        variant="shipment"
        title={t("animations.shipmentAcceptedTitle")}
        message={t("animations.shipmentAcceptedMessage")}
        reference={shipmentSuccess?.reference}
        onClose={() => setShipmentSuccess(null)}
      />
      <section className="card">
        <div className="section-header">
          <h2>
            <Truck size={20} />
            {t("po.title")}
          </h2>
          <button onClick={beginNew}>
            <Plus size={16} />
            {t("po.new")}
          </button>
        </div>
        <p className="page-intro">{t("po.subtitle")}</p>
        {error && <div className="form-error-banner">{error}</div>}
        {message && <div className="success-banner">{message}</div>}
      </section>

      {mode === "order" && (
        <section className="card">
          <h3>{selected ? t("po.edit") : t("po.new")}</h3>
          <form className="form-grid" onSubmit={submitOrder}>
            <label>
              {t("po.supplier")}
              <select
                required
                value={form.supplier_id}
                onChange={(e) =>
                  setForm({ ...form, supplier_id: e.target.value })
                }
              >
                <option value="">{t("po.selectSupplier")}</option>
                {suppliers.map((supplier) => (
                  <option key={supplier.id} value={supplier.id}>
                    {supplier.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              {t("po.orderDate")}
              <input
                required
                type="date"
                value={form.ordered_at}
                onChange={(e) =>
                  setForm({ ...form, ordered_at: e.target.value })
                }
              />
            </label>
            <label>
              {t("po.expectedDate")}
              <input
                type="date"
                min={form.ordered_at}
                value={form.expected_at}
                onChange={(e) =>
                  setForm({ ...form, expected_at: e.target.value })
                }
              />
            </label>
            <label>
              {t("warehouseOps.destinationWarehouse")}
              <select required value={form.warehouse_id} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })}>
                <option value="">{t("common.select")}</option>
                {warehouses.filter((warehouse) => warehouse.is_active).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name} ({warehouse.code})</option>)}
              </select>
            </label>
            <label>
              {t("po.dueDate")}
              <input
                type="date"
                min={form.ordered_at}
                value={form.due_at}
                onChange={(e) => setForm({ ...form, due_at: e.target.value })}
              />
            </label>
            <label>
              {t("po.currency")}
              <select
                value={form.currency}
                onChange={(e) => setForm({ ...form, currency: e.target.value })}
              >
                {["EUR", "USD", "ALL", "GBP", "CNY"].map((code) => (
                  <option key={code}>{code}</option>
                ))}
              </select>
            </label>
            <label>
              {t("po.orderStatus")}
              <select
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value })}
              >
                <option value="draft">{statusText("draft")}</option>
                <option value="confirmed">{statusText("confirmed")}</option>
                <option value="ordered">{statusText("ordered")}</option>
              </select>
            </label>
            {form.currency !== "EUR" && <>
              <label>{t("po.exchangeRate")}<input required type="number" min="0.000001" step="0.000001" value={form.exchange_rate} onChange={(e) => setForm({ ...form, exchange_rate: e.target.value })} /></label>
              <label>{t("po.exchangeRateDate")}<input required type="date" value={form.exchange_rate_date} onChange={(e) => setForm({ ...form, exchange_rate_date: e.target.value })} /></label>
              <label>{t("po.exchangeRateSource")}<input required value={form.exchange_rate_source} onChange={(e) => setForm({ ...form, exchange_rate_source: e.target.value })} placeholder="CBK / Bank / Customs" /></label>
            </>}
            <label>
              {t("po.notes")}
              <textarea
                value={form.notes}
                onChange={(e) => setForm({ ...form, notes: e.target.value })}
              />
            </label>
            {selected && (
              <label>
                {t("po.changeReason")}
                <textarea
                  value={form.change_reason}
                  onChange={(e) =>
                    setForm({ ...form, change_reason: e.target.value })
                  }
                />
              </label>
            )}
            <div className="table-wrap">
              <table className="product-table">
                <thead>
                  <tr>
                    <th>{t("po.product")}</th>
                    <th>{t("po.description")}</th>
                    <th>{t("po.unit")}</th>
                    <th>{t("po.quantity")}</th>
                    <th>{t("po.unitPrice")}</th>
                    <th>{t("po.lineTotal")}</th>
                    <th>{t("po.action")}</th>
                  </tr>
                </thead>
                <tbody>
                  {form.items.map((line, index) => (
                    <tr key={line.id || `new-${index}`}>
                      <td>
                        <select
                          value={line.product_id}
                          onChange={(e) =>
                            updateLine(index, "product_id", e.target.value)
                          }
                        >
                          <option value="">{t("po.selectProduct")}</option>
                          {products.map((product) => (
                            <option key={product.id} value={product.id}>
                              {product.name}
                            </option>
                          ))}
                        </select>
                      </td>
                      <td>
                        <input
                          required
                          value={line.description}
                          onChange={(e) =>
                            updateLine(index, "description", e.target.value)
                          }
                        />
                      </td>
                      <td>
                        {line.product_id ? <select required value={line.unit} onChange={(e) => updateLine(index, "unit", e.target.value)}>
                          {(() => {
                            const product = products.find((candidate) => String(candidate.id) === String(line.product_id));
                            return [product?.unit || "pcs", ...(product?.units || []).filter((unit) => unit.is_active !== false).map((unit) => unit.code)]
                              .filter((value, unitIndex, all) => all.indexOf(value) === unitIndex)
                              .map((unit) => <option key={unit} value={unit}>{unit}</option>);
                          })()}
                        </select> : <input required value={line.unit} onChange={(e) => updateLine(index, "unit", e.target.value)} />}
                      </td>
                      <td>
                        <input
                          required
                          type="number"
                          min="0.001"
                          step="0.001"
                          value={line.quantity}
                          onChange={(e) =>
                            updateLine(index, "quantity", e.target.value)
                          }
                        />
                      </td>
                      <td>
                        <input
                          required
                          type="number"
                          min="0"
                          step="0.01"
                          value={line.unit_price}
                          onChange={(e) =>
                            updateLine(index, "unit_price", e.target.value)
                          }
                        />
                      </td>
                      <td>
                        {money(
                          Number(line.quantity) * Number(line.unit_price),
                          form.currency,
                        )}
                      </td>
                      <td>
                        <button
                          type="button"
                          className="danger"
                          disabled={form.items.length === 1}
                          onClick={() =>
                            setForm({
                              ...form,
                              items: form.items.filter(
                                (_, itemIndex) => itemIndex !== index,
                              ),
                            })
                          }
                        >
                          {t("po.remove")}
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p>
              <strong>
                {t("po.total")}: {money(formTotal, form.currency)}
              </strong>
            </p>
            <div className="form-actions">
              <button
                type="button"
                className="secondary"
                onClick={() =>
                  setForm({ ...form, items: [...form.items, emptyLine()] })
                }
              >
                {t("po.addProduct")}
              </button>
              <button disabled={busy}>
                {busy ? t("po.saving") : t("po.save")}
              </button>
              <button
                type="button"
                className="secondary"
                onClick={() => setMode("")}
              >
                {t("po.close")}
              </button>
            </div>
          </form>
        </section>
      )}

      {selected && mode === "payment" && (
        <section className="card">
          <h3>{t("po.recordPayment")}</h3>
          <form className="form-grid" onSubmit={submitPayment}>
            <p>
              {t("po.total")}:{" "}
              <strong>{money(selected.total_amount, selected.currency)}</strong>
              <br />
              {t("po.paid")}:{" "}
              <strong>{money(selected.total_paid, selected.currency)}</strong>
              <br />
              {t("po.remaining")}:{" "}
              <strong>
                {money(selected.remaining_balance, selected.currency)}
              </strong>
            </p>
            <label>
              {t("po.paymentAmount")}
              <input
                required
                type="number"
                min="0.01"
                max={paymentMaximum}
                step="0.01"
                value={payment.amount}
                onChange={(e) =>
                  setPayment({ ...payment, amount: e.target.value })
                }
              />
            </label>
            <p>
              {t("po.afterPayment")}:{" "}
              <strong>{money(paymentRemaining, selected.currency)}</strong>
            </p>
            <label>
              {t("po.paymentTarget")}
              <select
                value={payment.expense_id}
                onChange={(e) => {
                  const invoice = (selected.supplier_invoices || []).find((row) => String(row.id) === e.target.value);
                  const maximum = invoice ? Math.min(Number(selected.remaining_balance || 0), Number(invoice.remaining_amount || 0)) : Number(selected.remaining_balance || 0);
                  setPayment({ ...payment, expense_id: e.target.value, amount: Number(payment.amount || 0) > maximum ? String(maximum) : payment.amount });
                }}
              >
                <option value="">{t("po.unallocatedAdvance")}</option>
                {(selected.supplier_invoices || []).map((invoice) => (
                  <option
                    key={invoice.id}
                    value={invoice.id}
                    disabled={Number(invoice.remaining_amount || 0) <= 0}
                  >
                    {invoice.document_number} · {money(invoice.remaining_amount, invoice.currency || selected.currency)} {t("po.invoiceRemaining")}
                  </option>
                ))}
              </select>
              <small>{t("po.paymentTargetHint")}</small>
            </label>
            <label>
              {t("po.paymentDate")}
              <input
                required
                type="date"
                value={payment.payment_date}
                onChange={(e) =>
                  setPayment({ ...payment, payment_date: e.target.value })
                }
              />
            </label>
            <label>
              {t("po.paymentMethod")}
              <select
                value={payment.payment_method}
                onChange={(e) =>
                  setPayment({ ...payment, payment_method: e.target.value })
                }
              >
                {["cash", "bank_transfer", "card", "cheque", "other"].map(
                  (method) => (
                    <option key={method} value={method}>
                      {paymentMethodText(method)}
                    </option>
                  ),
                )}
              </select>
            </label>
            <label>
              {t("payments.moneyAccount")}
              <select
                value={payment.financial_account_id}
                onChange={(e) =>
                  setPayment({ ...payment, financial_account_id: e.target.value })
                }
              >
                <option value="">{t("payments.noMoneyAccount")}</option>
                {financialAccounts.map((account) => (
                  <option key={account.id} value={account.id}>
                    {account.name} · {account.type === "cashbox" ? t("finance.cash") : t("finance.bankTransfer")} ({account.currency || "EUR"})
                  </option>
                ))}
              </select>
            </label>
            <label>
              {t("po.reference")}
              <input
                value={payment.reference_number}
                onChange={(e) =>
                  setPayment({ ...payment, reference_number: e.target.value })
                }
              />
            </label>
            <label>
              {t("po.notes")}
              <textarea
                value={payment.note}
                onChange={(e) =>
                  setPayment({ ...payment, note: e.target.value })
                }
              />
            </label>
            <div className="form-actions">
              <button disabled={busy}>{t("po.savePayment")}</button>
              <button
                type="button"
                className="secondary"
                onClick={() => setMode("")}
              >
                {t("po.close")}
              </button>
            </div>
          </form>
        </section>
      )}

      {selected && mode === "receive" && (
        <section className="card">
          <h3>{t("po.receiveProducts")}</h3>
          <form className="form-grid" onSubmit={submitReceipt}>
            <label>{t("warehouseOps.destinationWarehouse")}<select required value={receiptMeta.warehouse_id} onChange={(e) => setReceiptMeta({ ...receiptMeta, warehouse_id: e.target.value, location_id: "" })}><option value="">{t("common.select")}</option>{warehouses.filter((warehouse) => warehouse.is_active).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></label>
            <label>{t("warehouseOps.destinationLocation")}<select value={receiptMeta.location_id} onChange={(e) => setReceiptMeta({ ...receiptMeta, location_id: e.target.value })}><option value="">{t("warehouseOps.anyLocation")}</option>{locations.filter((location) => String(location.warehouse_id) === String(receiptMeta.warehouse_id)).map((location) => <option key={location.id} value={location.id}>{location.path} · {location.name}</option>)}</select></label>
            <label>{t("warehouseOps.receivedAt")}<input required type="date" value={receiptMeta.received_at} onChange={(e) => setReceiptMeta({ ...receiptMeta, received_at: e.target.value })} /></label>
            <label>{t("po.supplierDocument")}<input value={receiptMeta.supplier_document_number} onChange={(e) => setReceiptMeta({ ...receiptMeta, supplier_document_number: e.target.value })} /></label>
            {receipt.map((line, index) => (
              <div className="po-receipt-line" key={line.id}>
                {line.description} — {t("po.remainingQuantity")}:{" "}
                {line.remaining}
                <span>{t("warehouseOps.accepted")}</span>
                <input
                  type="number"
                  min="0"
                  max={line.remaining}
                  step="0.001"
                  value={line.accepted_quantity}
                  onChange={(e) =>
                    setReceipt(
                      receipt.map((row, rowIndex) =>
                        rowIndex === index
                          ? { ...row, accepted_quantity: e.target.value }
                          : row,
                      ),
                    )
                  }
                />
                <span className="po-receipt-state-fields">
                  <label>{t("warehouseOps.damaged")}<input type="number" min="0" max={line.remaining} step="0.001" value={line.damaged_quantity} onChange={(e) => setReceipt((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, damaged_quantity: e.target.value } : row))} /></label>
                  <label>{t("warehouseOps.rejected")}<input type="number" min="0" max={line.remaining} step="0.001" value={line.rejected_quantity} onChange={(e) => setReceipt((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, rejected_quantity: e.target.value } : row))} /></label>
                </span>
                {line.conversion_mode === "variable" ? <span className="po-receipt-state-fields variable-base-fields"><small>{t("po.actualBaseQuantity")} ({line.inventory_unit})</small><label>{t("warehouseOps.accepted")}<input required={Number(line.accepted_quantity) > 0} type="number" min="0" step="0.001" value={line.accepted_base_quantity} onChange={(e) => setReceipt((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, accepted_base_quantity: e.target.value } : row))} /></label><label>{t("warehouseOps.damaged")}<input required={Number(line.damaged_quantity) > 0} type="number" min="0" step="0.001" value={line.damaged_base_quantity} onChange={(e) => setReceipt((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, damaged_base_quantity: e.target.value } : row))} /></label></span> : <small className="table-subtext">{t("po.automaticConversion")}</small>}
                {line.tracking_mode !== "none" && ["accepted", "damaged"].map((state) => {
                  const stateQuantity = receiptBaseQuantity(line, state);
                  if (stateQuantity <= 0) return null;
                  const trace = line[`${state}_trace`];
                  const updateTrace = (field, value) => setReceipt((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, [`${state}_trace`]: { ...row[`${state}_trace`], [field]: value } } : row));
                  return (
                    <fieldset className="po-receipt-trace" key={state}>
                      <legend>{state === "accepted" ? t("warehouseOps.accepted") : t("warehouseOps.damaged")} · {t("po.traceDetails")}</legend>
                      {line.tracking_mode === "serial" ? (
                        <>
                          <label>{t("po.serialNumbers")}<textarea required value={trace.serial_numbers} onChange={(e) => updateTrace("serial_numbers", e.target.value)} placeholder={t("po.serialNumbersHelp")} /><small>{t("po.serialCount", { count: stateQuantity })}</small></label>
                          {line.expiration_controlled ? <>
                            <label>{t("po.manufacturedAt")}<input type="date" value={trace.manufactured_at} required={Boolean(line.default_shelf_life_days) && line.shelf_life_basis === "manufacture_date" && !trace.expiry_at} onChange={(e) => updateTrace("manufactured_at", e.target.value)} /></label>
                            <label>{t("po.expiryAt")}<input type="date" value={trace.expiry_at} required={!line.default_shelf_life_days} onChange={(e) => updateTrace("expiry_at", e.target.value)} /></label>
                          </> : null}
                        </>
                      ) : (
                        <>
                          <label>{t("po.lotNumber")}<input required value={trace.lot_number} onChange={(e) => updateTrace("lot_number", e.target.value)} /></label>
                          <label>{t("po.supplierBatch")}<input value={trace.supplier_batch} onChange={(e) => updateTrace("supplier_batch", e.target.value)} /></label>
                          <label>{t("po.manufacturedAt")}<input type="date" value={trace.manufactured_at} required={line.expiration_controlled && Boolean(line.default_shelf_life_days) && line.shelf_life_basis === "manufacture_date" && !trace.expiry_at} onChange={(e) => updateTrace("manufactured_at", e.target.value)} /></label>
                          {line.expiration_controlled && <label>{t("po.expiryAt")}<input required={!line.default_shelf_life_days} type="date" value={trace.expiry_at} onChange={(e) => updateTrace("expiry_at", e.target.value)} /></label>}
                        </>
                      )}
                    </fieldset>
                  );
                })}
              </div>
            ))}
            <label>{t("po.notes")}<textarea value={receiptMeta.notes} onChange={(e) => setReceiptMeta({ ...receiptMeta, notes: e.target.value })} /></label>
            {canOverrideExpiredReceipt ? <div className="po-expired-receipt-override">
              <label className="inventory-settings-check"><input type="checkbox" checked={Boolean(receiptMeta.allow_expired_receipt)} onChange={(e) => setReceiptMeta({ ...receiptMeta, allow_expired_receipt: e.target.checked, expired_receipt_reason: e.target.checked ? receiptMeta.expired_receipt_reason : "" })} /><span>{t("po.expiredReceiptOverride")}</span></label>
              {receiptMeta.allow_expired_receipt ? <label>{t("po.expiredReceiptReason")}<textarea required minLength={5} value={receiptMeta.expired_receipt_reason} onChange={(e) => setReceiptMeta({ ...receiptMeta, expired_receipt_reason: e.target.value })} /></label> : null}
            </div> : null}
            <div className="form-actions">
              <button disabled={busy || !receipt.length}>
                {t("po.confirmReceipt")}
              </button>
              <button
                type="button"
                className="secondary"
                onClick={() => setMode("")}
              >
                {t("po.close")}
              </button>
            </div>
          </form>
        </section>
      )}

      {selected && mode === "status" && (
        <section className="card">
          <h3>{t("po.changeStatus")}</h3>
          <form className="form-grid" onSubmit={submitStatus}>
            <label>
              {t("po.orderStatus")}
              <select
                value={statusForm.status}
                onChange={(e) =>
                  setStatusForm({ ...statusForm, status: e.target.value })
                }
              >
                {selected.status === "received" ? (
                  <option value="completed">{statusText("completed")}</option>
                ) : (
                  <>
                    <option value="draft">{statusText("draft")}</option>
                    <option value="confirmed">{statusText("confirmed")}</option>
                    <option value="ordered">{statusText("ordered")}</option>
                  </>
                )}
              </select>
            </label>
            <label>
              {t("po.changeReason")}
              <textarea
                value={statusForm.reason}
                onChange={(e) =>
                  setStatusForm({ ...statusForm, reason: e.target.value })
                }
              />
            </label>
            <div className="form-actions">
              <button disabled={busy}>{t("po.save")}</button>
              <button
                type="button"
                className="secondary"
                onClick={() => setMode("")}
              >
                {t("po.close")}
              </button>
            </div>
          </form>
        </section>
      )}

      {selected && mode !== "order" ? (
        <section className="card table-wrap">
          <div className="section-header">
            <div>
              <h2>{selected.po_number}</h2>
              <p>
                {selected.supplier?.name} · {statusText(selected.status)} ·{" "}
                {paymentStatusText(selected.payment_status)}
              </p>
            </div>
            <div className="table-actions">
              <button
                className="secondary"
                disabled={["completed", "cancelled"].includes(selected.status)}
                onClick={beginEdit}
              >
                {t("po.edit")}
              </button>
              <button
                disabled={
                  Number(selected.remaining_balance) <= 0 ||
                  selected.status === "cancelled"
                }
                onClick={beginPayment}
              >
                {t("po.recordPayment")}
              </button>
              <button
                className="secondary"
                disabled={
                  ["completed", "cancelled"].includes(selected.status) ||
                  !selected.items.some(
                    (item) => Number(item.remaining_quantity) > 0,
                  )
                }
                onClick={beginReceipt}
              >
                {t("po.receiveProducts")}
              </button>
              <button
                className="secondary"
                disabled={
                  selected.status === "partially_received" ||
                  selected.status === "cancelled" ||
                  selected.status === "completed"
                }
                onClick={beginStatus}
              >
                {t("po.changeStatus")}
              </button>
              <button className="secondary" onClick={exportStatement}>
                {t("po.export")}
              </button>
              <button
                className="danger"
                disabled={
                  busy ||
                  [
                    "received",
                    "partially_received",
                    "completed",
                    "cancelled",
                  ].includes(selected.status) ||
                  Number(selected.total_paid) > 0
                }
                onClick={cancelOrder}
              >
                {t("po.cancelOrder")}
              </button>
              {selected.status === "completed" && (
                <button
                  className="danger"
                  disabled={busy}
                  onClick={removeCompletedOrder}
                >
                  {t("po.deleteOrder")}
                </button>
              )}
              <button
                className="secondary"
                onClick={() => {
                  setSelected(null);
                  setMode("");
                }}
              >
                {t("po.back")}
              </button>
            </div>
          </div>
          <div className="form-row">
            <p>
              {t("po.orderDate")}:{" "}
              <strong>{dateOnly(selected.ordered_at)}</strong>
            </p>
            <p>
              {t("po.expectedDate")}:{" "}
              <strong>{dateOnly(selected.expected_at)}</strong>
            </p>
            <p>
              {t("po.dueDate")}: <strong>{dateOnly(selected.due_at)}</strong>
            </p>
            <p>
              {t("po.total")}:{" "}
              <strong>{money(selected.total_amount, selected.currency)}</strong>
            </p>
            <p>
              {t("po.paid")}:{" "}
              <strong>{money(selected.total_paid, selected.currency)}</strong>
            </p>
            <p>
              {t("po.remaining")}:{" "}
              <strong>
                {money(selected.remaining_balance, selected.currency)}
              </strong>
            </p>
          </div>
          <p>{selected.notes || ""}</p>
          <EntityContext entityType="purchase-order" entityId={selected.id} />
          {selected.shipments?.length ? (
            <section className="po-linked-shipments">
              <h3>{t("po.linkedVessels")}</h3>
              <div className="shipment-container-chips">
                {selected.shipments.map((shipment) => (
                  <span key={shipment.id}>
                    <strong>{shipment.vessel_name || `MMSI ${shipment.mmsi}`}</strong>
                    <small>{shipment.imo ? `IMO ${shipment.imo} · ` : ""}{shipment.status?.replace(/_/g, " ")}</small>
                    <Link className="btn-secondary" to={`/shipments/my-shipments?shipment=${shipment.id}`}>{t("po.openTracking")}</Link>
                  </span>
                ))}
              </div>
            </section>
          ) : null}
          <h3>{t("po.items")}</h3>
          <table className="product-table">
            <thead>
              <tr>
                <th>{t("po.product")}</th>
                <th>{t("po.unit")}</th>
                <th>{t("po.quantity")}</th>
                <th>{t("po.received")}</th>
                <th>{t("po.unitPrice")}</th>
                <th>{t("po.lineTotal")}</th>
              </tr>
            </thead>
            <tbody>
              {selected.items.map((item) => (
                <tr key={item.id}>
                  <td>
                    {item.description}
                    <br />
                    <small>{item.product?.sku || ""}</small>
                  </td>
                  <td>{item.unit}</td>
                  <td>{item.quantity}</td>
                  <td>{item.received_quantity}</td>
                  <td>{money(item.unit_price, selected.currency)}</td>
                  <td>{money(item.line_total, selected.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <h3>{t("po.paymentHistory")}</h3>
          <table className="product-table">
            <thead>
              <tr>
                <th>{t("po.paymentDate")}</th>
                <th>{t("po.paymentAmount")}</th>
                <th>{t("po.paymentMethod")}</th>
                <th>{t("po.reference")}</th>
                <th>{t("po.notes")}</th>
                <th>{t("po.user")}</th>
                <th>{t("po.status")}</th>
                <th>{t("po.action")}</th>
              </tr>
            </thead>
            <tbody>
              {selected.payments.length ? (
                selected.payments.map((row) => (
                  <tr key={row.id}>
                    <td>{dateOnly(row.payment_date)}</td>
                    <td>{money(row.amount, selected.currency)}</td>
                    <td>{paymentMethodText(row.payment_method)}</td>
                    <td>{row.reference_number || "—"}</td>
                    <td>{row.note || "—"}</td>
                    <td>{row.user?.name || "—"}</td>
                    <td>{row.status === "reversed" ? t("po.paymentReversedStatus") : t("po.paymentCompletedStatus")}</td>
                    <td>{row.status !== "reversed" ? <button type="button" className="danger" disabled={busy} onClick={() => reverseRecordedPayment(row)}>{t("po.reversePayment")}</button> : row.reversal_reason || "—"}</td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan="8">{t("po.noPayments")}</td>
                </tr>
              )}
            </tbody>
          </table>
          <h3>{t("po.changeHistory")}</h3>
          <table className="product-table">
            <thead>
              <tr>
                <th>{t("po.date")}</th>
                <th>{t("po.action")}</th>
                <th>{t("po.user")}</th>
                <th>{t("po.changeReason")}</th>
              </tr>
            </thead>
            <tbody>
              {selected.changes.length ? (
                selected.changes.map((row) => (
                  <tr key={row.id}>
                    <td>{dateOnly(row.created_at)}</td>
                    <td>{t(`po.action.${row.action}`)}</td>
                    <td>{row.user?.name || "—"}</td>
                    <td>{row.reason || "—"}</td>
                  </tr>
                ))
              ) : (
                <tr>
                  <td colSpan="4">{t("po.noChanges")}</td>
                </tr>
              )}
            </tbody>
          </table>
        </section>
      ) : (
        !selected &&
        mode !== "order" && (
          <section className="card">
            <div className="filter-toolbar">
              <input
                placeholder={t("po.search")}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
              <select
                value={supplierFilter}
                onChange={(e) => setSupplierFilter(e.target.value)}
              >
                <option value="">{t("po.allSuppliers")}</option>
                {suppliers.map((supplier) => (
                  <option key={supplier.id} value={supplier.id}>
                    {supplier.name}
                  </option>
                ))}
              </select>
              <select
                value={orderStatus}
                onChange={(e) => setOrderStatus(e.target.value)}
              >
                <option value="">{t("po.allOrderStatuses")}</option>
                {[
                  "draft",
                  "confirmed",
                  "ordered",
                  "partially_received",
                  "received",
                  "completed",
                  "cancelled",
                ].map((value) => (
                  <option key={value} value={value}>
                    {statusText(value)}
                  </option>
                ))}
              </select>
              <select
                value={paymentStatus}
                onChange={(e) => setPaymentStatus(e.target.value)}
              >
                <option value="">{t("po.allPaymentStatuses")}</option>
                {["unpaid", "partially_paid", "paid", "overdue"].map(
                  (value) => (
                    <option key={value} value={value}>
                      {paymentStatusText(value)}
                    </option>
                  ),
                )}
              </select>
              <input
                type="date"
                title={t("po.orderDate")}
                value={orderedDate}
                onChange={(e) => setOrderedDate(e.target.value)}
              />
              <input
                type="date"
                title={t("po.dueDate")}
                value={dueDate}
                onChange={(e) => setDueDate(e.target.value)}
              />
              <select value={sort} onChange={(e) => setSort(e.target.value)}>
                <option value="recent">{t("po.sortRecent")}</option>
                <option value="total_desc">{t("po.sortTotal")}</option>
                <option value="due_asc">{t("po.sortDue")}</option>
              </select>
            </div>
            <div className="table-wrap">
              <table className="product-table">
                <thead>
                  <tr>
                    <th>{t("po.order")}</th>
                    <th>{t("po.supplier")}</th>
                    <th>{t("po.orderDate")}</th>
                    <th>{t("po.dueDate")}</th>
                    <th>{t("po.total")}</th>
                    <th>{t("po.paid")}</th>
                    <th>{t("po.remaining")}</th>
                    <th>{t("po.orderStatus")}</th>
                    <th>{t("po.paymentStatusLabel")}</th>
                    <th>{t("po.action")}</th>
                  </tr>
                </thead>
                <tbody>
                  {loading ? (
                    <tr>
                      <td colSpan="10">{t("po.loading")}</td>
                    </tr>
                  ) : orders.length ? (
                    orders.map((order) => (
                      <tr key={order.id}>
                        <td>{order.po_number}</td>
                        <td>{order.supplier?.name || "—"}</td>
                        <td>{dateOnly(order.ordered_at)}</td>
                        <td>{dateOnly(order.due_at)}</td>
                        <td>{money(order.total_amount, order.currency)}</td>
                        <td>{money(order.total_paid, order.currency)}</td>
                        <td>
                          {money(order.remaining_balance, order.currency)}
                        </td>
                        <td>{statusText(order.status)}</td>
                        <td>{paymentStatusText(order.payment_status)}</td>
                        <td>
                          <button onClick={() => openOrder(order.id)}>
                            {t("po.view")}
                          </button>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan="10">{t("po.empty")}</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="pagination">
              <button
                className="secondary"
                disabled={loading || pagination.current_page <= 1}
                onClick={() => {
                  const next = page - 1;
                  setPage(next);
                  loadList(next);
                }}
              >
                {t("po.previous")}
              </button>
              <span>
                {t("po.page")} {pagination.current_page} /{" "}
                {pagination.last_page}
              </span>
              <button
                className="secondary"
                disabled={
                  loading || pagination.current_page >= pagination.last_page
                }
                onClick={() => {
                  const next = page + 1;
                  setPage(next);
                  loadList(next);
                }}
              >
                {t("po.next")}
              </button>
            </div>
          </section>
        )
      )}
    </main>
  );
}
