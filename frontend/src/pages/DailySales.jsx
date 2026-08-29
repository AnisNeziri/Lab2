import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  CalendarDays,
  CheckCircle2,
  FileDown,
  Pencil,
  Plus,
  Printer,
  Save,
  Trash2,
  X,
} from "lucide-react";
import AimsLogo from "../components/AimsLogo";
import { useTranslation } from "../hooks/useTranslation";
import { useAuthStore } from "../store/authStore";
import { getAllProducts } from "../api/products";
import { formatQuantity, isMeterUnit } from "../utils/formatQuantity";
import {
  createDailySale,
  deleteDailySale,
  downloadDailySalesDayPdf,
  finalizeDailySalesDay,
  getDailySales,
  getDailySalesDayNotes,
  updateDailySale,
  updateDailySalesDayNotes,
} from "../api/dailySales";
import "./DailySales.css";
import { getWarehouses } from "../api/warehouseOperations";

const todayIso = () => new Date().toISOString().slice(0, 10);
const money = (value) =>
  new Intl.NumberFormat("de-DE", {
    style: "currency",
    currency: "EUR",
  }).format(Number(value || 0));

function defaultSalePrice(product, unitDefinition = null) {
  const basePrice = Number(product?.selling_price ?? product?.price ?? 0);
  if (unitDefinition?.conversion_mode !== "fixed") return basePrice;
  return Number((basePrice * Number(unitDefinition.factor_to_base || 0)).toFixed(2));
}
function emptyRow(index = 1) {
  return {
    key: `row-${Date.now()}-${index}`,
    product_id: "",
    product_name: "",
    unit: "pcs",
    quantity: 1,
    unit_price: 0,
    line_total: 0,
    stock_available: null,
    inventory_unit: "pcs",
    conversion_mode: "none",
    conversion_factor: null,
    actual_base_quantity: "",
    warehouse_id: "",
  };
}

function mapRow(item) {
  return {
    key: `row-${item.id}`,
    product_id: item.product_id || "",
    product_name: item.product_name,
    unit: item.unit,
    quantity: Number(item.quantity),
    unit_price: Number(item.unit_price),
    line_total: Number(item.line_total),
    stock_available: item.product?.quantity ?? null,
    inventory_unit: item.product?.unit || item.unit,
    conversion_mode: item.conversion_mode || "none",
    conversion_factor: item.conversion_factor ?? null,
    actual_base_quantity: item.conversion_mode === "variable" ? Number(item.base_quantity || 0) : "",
    warehouse_id: item.warehouse_id ? String(item.warehouse_id) : "",
  };
}

function saleSequence(sale, fallback) {
  const match = String(sale?.sale_number || "").match(/-(\d+)$/);
  return match ? Number(match[1]) : fallback;
}

export default function DailySales() {
  const { t, language } = useTranslation();
  const user = useAuthStore((state) => state.user);
  const permissions = useAuthStore((state) => state.permissions);
  const [date, setDate] = useState(todayIso());
  const [sales, setSales] = useState([]);
  const [products, setProducts] = useState([]);
  const [warehouses, setWarehouses] = useState([]);
  const [editorOpen, setEditorOpen] = useState(false);
  const [editorId, setEditorId] = useState(null);
  const [saleNumber, setSaleNumber] = useState("");
  const [rows, setRows] = useState([emptyRow()]);
  const [notes, setNotes] = useState("");
  const [dayNotes, setDayNotes] = useState("");
  const [dayNotesSaving, setDayNotesSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [confirmClose, setConfirmClose] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const dayRequestRef = useRef(0);
  const notesRequestRef = useRef(0);
  const dayNotesDirtyRef = useRef(false);
  const productsRequestRef = useRef(0);
  const refreshTimerRef = useRef(null);

  const canManage = permissions.includes("daily_sales.manage");
  const canFinalize = permissions.includes("daily_sales.finalize");
  const canDelete = permissions.includes("daily_sales.delete");

  const loadDay = useCallback(
    async (targetDate, showLoading = true) => {
      const requestId = ++dayRequestRef.current;
      if (showLoading) setLoading(true);
      try {
        const data = await getDailySales({ date: targetDate });
        if (requestId !== dayRequestRef.current) return;
        setSales(Array.isArray(data) ? data : []);
        setError("");
      } catch (err) {
        if (requestId !== dayRequestRef.current) return;
        if (showLoading) {
          setError(err.message || t("dailySales.loadError"));
          setSales([]);
        }
      } finally {
        if (showLoading && requestId === dayRequestRef.current) setLoading(false);
      }
    },
    [t],
  );

  const loadProducts = useCallback(async ({ silent = false } = {}) => {
    const requestId = ++productsRequestRef.current;
    try {
      const data = await getAllProducts();
      const list = data.data || data || [];
      if (requestId !== productsRequestRef.current) return list;
      setProducts(list);
      return list;
    } catch {
      if (!silent && requestId === productsRequestRef.current) setProducts([]);
      return [];
    }
  }, []);

  const loadDayNotes = useCallback(async (targetDate, { silent = false } = {}) => {
    const requestId = ++notesRequestRef.current;
    try {
      const data = await getDailySalesDayNotes(targetDate);
      if (requestId !== notesRequestRef.current) return;
      if (!dayNotesDirtyRef.current) {
        setDayNotes(data.notes || "");
      }
    } catch {
      if (requestId !== notesRequestRef.current) return;
      if (!silent && !dayNotesDirtyRef.current) setDayNotes("");
    }
  }, []);

  useEffect(() => {
    loadProducts();
    getWarehouses().then((data) => setWarehouses(data || [])).catch(() => setWarehouses([]));
  }, [loadProducts]);

  useEffect(() => {
    dayNotesDirtyRef.current = false;
    setEditorOpen(false);
    setEditorId(null);
    setConfirmClose(false);
    setDeleteTarget(null);
    loadDay(date);
    loadDayNotes(date);
  }, [date, loadDay, loadDayNotes]);

  useEffect(() => {
    const refresh = () => {
      // Polling and Echo can emit several events for one stock movement. Do not
      // replace the visible editor while the user is entering a sale, and
      // coalesce event bursts into one quiet refresh.
      if (editorOpen || refreshTimerRef.current) return;
      refreshTimerRef.current = window.setTimeout(() => {
        refreshTimerRef.current = null;
        void Promise.all([
          loadDay(date, false),
          loadProducts({ silent: true }),
          loadDayNotes(date, { silent: true }),
        ]);
      }, 350);
    };
    window.addEventListener("database-refresh", refresh);
    window.addEventListener("stock-refresh", refresh);
    return () => {
      window.removeEventListener("database-refresh", refresh);
      window.removeEventListener("stock-refresh", refresh);
      if (refreshTimerRef.current) {
        window.clearTimeout(refreshTimerRef.current);
        refreshTimerRef.current = null;
      }
    };
  }, [date, editorOpen, loadDay, loadProducts, loadDayNotes]);

  const editorTotal = useMemo(
    () => rows.reduce((sum, row) => sum + Number(row.line_total || 0), 0),
    [rows],
  );
  const nextSequence = useMemo(
    () =>
      Math.max(
        0,
        ...sales.map((sale, index) => saleSequence(sale, index + 1)),
      ) + 1,
    [sales],
  );
  const savedTotal = useMemo(
    () =>
      sales
        .filter((sale) => sale.id !== editorId)
        .reduce((sum, sale) => sum + Number(sale.total_amount || 0), 0),
    [sales, editorId],
  );
  const dayTotal = savedTotal + (editorOpen ? editorTotal : 0);
  const dayClosed =
    sales.length > 0 && sales.every((sale) => sale.status === "finalized");

  const stockErrors = useMemo(() => {
    const requested = new Map();
    rows.forEach((row) => {
      if (!row.product_id) return;
      const id = `${row.product_id}:${row.warehouse_id || "auto"}`;
      const baseQuantity = row.conversion_mode === "fixed"
        ? Number(row.quantity || 0) * Number(row.conversion_factor || 0)
        : row.conversion_mode === "variable" ? Number(row.actual_base_quantity || 0) : Number(row.quantity || 0);
      requested.set(id, (requested.get(id) || 0) + baseQuantity);
    });
    return rows.reduce((errors, row) => {
      if (!row.product_id || row.stock_available == null) return errors;
      if (
        (requested.get(`${row.product_id}:${row.warehouse_id || "auto"}`) || 0) >
        Number(row.stock_available)
      ) {
        errors[row.key] = t("dailySales.insufficientStock", {
          quantity: row.stock_available,
          unit: row.inventory_unit,
        });
      }
      return errors;
    }, {});
  }, [rows, t]);

  function resetEditor() {
    setEditorOpen(false);
    setEditorId(null);
    setSaleNumber("");
    setRows([emptyRow()]);
    setNotes("");
    setError("");
  }

  function startNewSale() {
    setEditorOpen(true);
    setEditorId(null);
    setSaleNumber("");
    setRows([emptyRow()]);
    setNotes("");
    setConfirmClose(false);
    setDeleteTarget(null);
    setMessage("");
    setError("");
  }

  function editSale(sale) {
    if (sale.status !== "draft") return;
    setEditorOpen(true);
    setEditorId(sale.id);
    setSaleNumber(sale.sale_number);
    setRows(sale.items?.length ? sale.items.map(mapRow) : [emptyRow()]);
    setNotes(sale.notes || "");
    setConfirmClose(false);
    setDeleteTarget(null);
    setMessage("");
    setError("");
  }

  function updateRow(key, patch) {
    setRows((current) =>
      current.map((row) => {
        if (row.key !== key) return row;
        const next = { ...row, ...patch };
        const minimum = isMeterUnit(next.unit) ? 0.001 : 1;
        const quantity = next.quantity === ""
          ? ""
          : Math.max(minimum, Number(next.quantity) || minimum);
        const normalizedQuantity = quantity === "" || isMeterUnit(next.unit)
          ? quantity
          : Math.floor(quantity);
        const unitPrice = next.unit_price === ""
          ? ""
          : Math.max(0, Number(next.unit_price) || 0);
        return {
          ...next,
          quantity: normalizedQuantity,
          unit_price: unitPrice,
          line_total:
            normalizedQuantity === "" || unitPrice === ""
              ? 0
              : Number((normalizedQuantity * unitPrice).toFixed(2)),
        };
      }),
    );
  }

  function selectProduct(key, productId) {
    const product = products.find(
      (item) => String(item.id) === String(productId),
    );
    if (!product) {
      updateRow(key, {
        product_id: "",
        product_name: "",
        stock_available: null,
      });
      return;
    }
    const defaultWarehouse = product.default_warehouse_id || warehouses.find((warehouse) => warehouse.is_default)?.id || "";
    const warehouseBalance = product.warehouse_stock?.find((balance) => String(balance.warehouse_id) === String(defaultWarehouse));
    updateRow(key, {
      product_id: product.id,
      product_name: product.name,
      unit: product.unit || "pcs",
      unit_price: defaultSalePrice(product, null),
      stock_available: warehouseBalance?.available_quantity ?? product.quantity,
      inventory_unit: product.unit || "pcs",
      conversion_mode: "none",
      conversion_factor: null,
      actual_base_quantity: "",
      warehouse_id: String(defaultWarehouse),
    });
  }

  function selectSaleUnit(key, unitCode) {
    const row = rows.find((candidate) => candidate.key === key);
    const product = products.find((candidate) => String(candidate.id) === String(row?.product_id));
    const definition = product?.units?.find((unit) => unit.code === unitCode);
    updateRow(key, {
      unit: unitCode,
      conversion_mode: definition?.conversion_mode || "none",
      conversion_factor: definition?.factor_to_base ?? null,
      actual_base_quantity: "",
      unit_price: defaultSalePrice(product, definition),
    });
  }

  function selectSaleWarehouse(key, warehouseId) {
    const row = rows.find((candidate) => candidate.key === key);
    const product = products.find((candidate) => String(candidate.id) === String(row?.product_id));
    const balance = product?.warehouse_stock?.find((candidate) => String(candidate.warehouse_id) === String(warehouseId));
    updateRow(key, { warehouse_id: warehouseId, stock_available: balance?.available_quantity ?? 0 });
  }

  const addProduct = () =>
    setRows((current) => [...current, emptyRow(current.length + 1)]);
  const removeProduct = (key) =>
    setRows((current) =>
      current.length === 1
        ? [emptyRow()]
        : current.filter((row) => row.key !== key),
    );

  function payload() {
    return {
      sale_date: date,
      notes: notes || null,
      items: rows
        .filter((row) => row.product_name.trim())
        .map((row) => ({
          product_id: row.product_id || null,
          product_name: row.product_name,
          unit: row.unit,
          quantity: row.quantity,
          unit_price: row.unit_price,
          warehouse_id: row.warehouse_id ? Number(row.warehouse_id) : null,
          actual_base_quantity: row.conversion_mode === "variable" ? Number(row.actual_base_quantity) : null,
        })),
    };
  }

  async function saveEditor(closeAfter = true) {
    if (!editorOpen) return null;
    const data = payload();
    if (!data.items.length) throw new Error(t("dailySales.addProductRequired"));
    const incomplete = rows.some((row) => {
      if (!row.product_name.trim()) return false;
      return row.quantity === "" || row.unit_price === "" ||
        (row.conversion_mode === "variable" && (!row.actual_base_quantity || Number(row.actual_base_quantity) <= 0)) ||
        !Number.isFinite(Number(row.quantity)) || !Number.isFinite(Number(row.unit_price));
    });
    if (incomplete) throw new Error(t("dailySales.numberFieldsRequired"));
    const saved = editorId
      ? await updateDailySale(editorId, data)
      : await createDailySale(data);
    if (closeAfter) resetEditor();
    return saved;
  }

  async function handleSaveDayNotes() {
    if (dayNotesSaving) return;
    setDayNotesSaving(true);
    setError("");
    try {
      const saved = await updateDailySalesDayNotes(date, dayNotes);
      dayNotesDirtyRef.current = false;
      setDayNotes(saved?.notes ?? dayNotes);
      setMessage(t("dailySales.dayNotesSaved"));
    } catch (err) {
      setError(err.message || t("dailySales.dayNotesError"));
    } finally {
      setDayNotesSaving(false);
    }
  }

  async function handleSave() {
    if (saving) return;
    setSaving(true);
    setError("");
    setMessage("");
    try {
      await saveEditor(true);
      await Promise.all([loadDay(date, false), loadProducts()]);
      setMessage(t("dailySales.saleSaved"));
    } catch (err) {
      setError(err.message || t("dailySales.saveError"));
    } finally {
      setSaving(false);
    }
  }

  async function handleCloseDay() {
    if (saving) return;
    setSaving(true);
    setError("");
    setMessage("");
    try {
      if (editorOpen && rows.some((row) => row.product_name.trim())) {
        await saveEditor(true);
      }
      await finalizeDailySalesDay(date);
      setConfirmClose(false);
      await Promise.all([loadDay(date, false), loadProducts()]);
      setMessage(t("dailySales.dayFinalized"));
    } catch (err) {
      setError(err.message || t("dailySales.finalizeError"));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!deleteTarget || saving) return;
    setSaving(true);
    setError("");
    try {
      await deleteDailySale(deleteTarget.id);
      if (editorId === deleteTarget.id) resetEditor();
      setDeleteTarget(null);
      await Promise.all([loadDay(date, false), loadProducts()]);
      setMessage(t("dailySales.saleDeleted"));
    } catch (err) {
      setError(err.message || t("dailySales.deleteError"));
    } finally {
      setSaving(false);
    }
  }

  async function handlePdf() {
    if (!sales.length) {
      setError(t("dailySales.noSalesForDate"));
      return;
    }
    try {
      await downloadDailySalesDayPdf(date, language);
    } catch (err) {
      setError(err.message || t("dailySales.pdfError"));
    }
  }

  if (!canManage) {
    return <p className="page-message">{t("dailySales.accessDenied")}</p>;
  }

  return (
    <main className="page-stack daily-sales-page">
      <section className="card daily-sales-toolbar no-print">
        <div>
          <h2>{t("dailySales.dayBook")}</h2>
          <p className="page-intro">{t("dailySales.dayBookDescription")}</p>
        </div>
        <label className="daily-sales-date-picker">
          <CalendarDays size={18} />
          <span>{t("dailySales.viewDate")}</span>
          <input
            type="date"
            value={date}
            onChange={(event) => setDate(event.target.value)}
          />
        </label>
        <div className="daily-sales-toolbar-actions">
          <button type="button" onClick={startNewSale} disabled={saving}>
            <Plus size={17} /> {t("dailySales.addSale")}
          </button>
          <button
            type="button"
            className="secondary"
            onClick={() => window.print()}
          >
            <Printer size={17} /> {t("dailySales.print")}
          </button>
          <button type="button" className="secondary" onClick={handlePdf}>
            <FileDown size={17} /> {t("dailySales.exportPdf")}
          </button>
          <button
            type="button"
            className="btn-primary"
            disabled={
              !canFinalize ||
              saving ||
              (!sales.length && !editorOpen) ||
              dayClosed
            }
            onClick={() => setConfirmClose(true)}
          >
            <CheckCircle2 size={17} />
            {dayClosed ? t("dailySales.dayClosed") : t("dailySales.closeDay")}
          </button>
        </div>
      </section>

      {error ? <div className="form-error-banner no-print">{error}</div> : null}
      {message ? (
        <div className="success-banner no-print">{message}</div>
      ) : null}

      {confirmClose ? (
        <section className="card daily-sales-confirm no-print">
          <h3>{t("dailySales.closeDay")}</h3>
          <p>{t("dailySales.closeDayConfirm")}</p>
          <div className="form-actions">
            <button type="button" disabled={saving} onClick={handleCloseDay}>
              {t("dailySales.confirmCloseDay")}
            </button>
            <button
              type="button"
              className="secondary"
              disabled={saving}
              onClick={() => setConfirmClose(false)}
            >
              {t("dailySales.cancel")}
            </button>
          </div>
        </section>
      ) : null}

      {deleteTarget ? (
        <section className="card daily-sales-confirm danger-confirmation no-print">
          <h3>{t("dailySales.deleteSale")}</h3>
          <p>
            {t("dailySales.deleteSaleConfirm", {
              number: saleSequence(deleteTarget, 1),
            })}
          </p>
          <div className="form-actions">
            <button
              type="button"
              className="danger"
              disabled={saving}
              onClick={handleDelete}
            >
              {t("dailySales.deleteSale")}
            </button>
            <button
              type="button"
              className="secondary"
              disabled={saving}
              onClick={() => setDeleteTarget(null)}
            >
              {t("dailySales.cancel")}
            </button>
          </div>
        </section>
      ) : null}

      <section className="daily-sales-paper">
        <header className="daily-sales-book-header">
          <div>
            <h1>{user?.company_name || "AIMS"}</h1>
            <p>{t("dailySales.dayBook")}</p>
          </div>
          <div className="daily-sales-book-date">
            <strong>{t("dailySales.date")}</strong>
            <span>{date}</span>
            <span
              className={`status-pill ${dayClosed ? "status-paid" : "status-unpaid"}`}
            >
              {dayClosed ? t("dailySales.dayClosed") : t("dailySales.dayOpen")}
            </span>
          </div>
          <AimsLogo size="md" showText={false} />
        </header>

        <section className="daily-sales-day-summary">
          <div>
            <span>{t("dailySales.dayTotal")}</span>
            <strong>{money(dayTotal)}</strong>
          </div>
          <div>
            <span>{t("dailySales.salesCount")}</span>
            <strong>{sales.length + (editorOpen && !editorId ? 1 : 0)}</strong>
          </div>
        </section>

        <section className="daily-sales-day-notes no-print">
          <label htmlFor="daily-sales-day-notes">
            <span>{t("dailySales.dayNotes")}</span>
            <textarea
              id="daily-sales-day-notes"
              rows="3"
              value={dayNotes}
              onChange={(event) => {
                dayNotesDirtyRef.current = true;
                setDayNotes(event.target.value);
              }}
              placeholder={t("dailySales.dayNotesPlaceholder")}
            />
          </label>
          <button type="button" className="secondary" disabled={dayNotesSaving} onClick={handleSaveDayNotes}>
            {dayNotesSaving ? t("common.loading") : t("dailySales.saveDayNotes")}
          </button>
        </section>

        {editorOpen ? (
          <section className="daily-sale-entry daily-sale-editor no-print">
            <div className="daily-sale-entry-heading">
              <div>
                <span>
                  {t("dailySales.saleNumber", {
                    number: editorId
                      ? saleSequence(
                          sales.find((sale) => sale.id === editorId),
                          nextSequence,
                        )
                      : nextSequence,
                  })}
                </span>
                <small>{saleNumber || t("dailySales.assignedOnSave")}</small>
              </div>
              <div className="daily-sale-entry-total">
                <span>{t("dailySales.saleTotal")}</span>
                <strong>{money(editorTotal)}</strong>
              </div>
            </div>
            <table className="daily-sales-table">
              <thead>
                <tr>
                  <th>{t("dailySales.line")}</th>
                  <th>{t("dailySales.colProduct")}</th>
                  <th>{t("dailySales.colUnit")}</th>
                  <th>{t("dailySales.colQuantity")}</th>
                  <th>{t("dailySales.colPrice")}</th>
                  <th>{t("dailySales.colAmount")}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {rows.map((row, index) => (
                  <tr key={row.key}>
                    <td className="daily-sales-line-number">{index + 1}</td>
                    <td>
                      <div className="daily-sales-product-cell">
                        <select
                          value={row.product_id}
                          onChange={(event) =>
                            selectProduct(row.key, event.target.value)
                          }
                        >
                          <option value="">
                            {t("dailySales.selectProduct")}
                          </option>
                          {products.map((product) => (
                            <option key={product.id} value={product.id}>
                              {product.name} ({formatQuantity(product.quantity, product.unit, language)} {product.unit})
                            </option>
                          ))}
                        </select>
                        <input
                          value={row.product_name}
                          onChange={(event) =>
                            updateRow(row.key, {
                              product_name: event.target.value,
                            })
                          }
                        />
                        {row.stock_available != null ? (
                          <small>
                            {t("dailySales.stock")}: {formatQuantity(row.stock_available, row.inventory_unit, language)}{" "}
                            {row.inventory_unit}
                            {row.conversion_mode === "fixed" && Number(row.conversion_factor) > 0
                              ? ` (${Math.floor(Number(row.stock_available) / Number(row.conversion_factor))} ${row.unit} ${t("dailySales.fullPacksAvailable")})`
                              : ""}
                          </small>
                        ) : null}
                        {stockErrors[row.key] ? (
                          <small className="daily-sales-error">
                            {stockErrors[row.key]}
                          </small>
                        ) : null}
                      </div>
                    </td>
                    <td>
                      {row.product_id ? (
                        <div className="daily-sale-unit-cell">
                          <select value={row.unit} onChange={(event) => selectSaleUnit(row.key, event.target.value)}>
                            {(() => { const product = products.find((candidate) => String(candidate.id) === String(row.product_id)); return [product?.unit || "pcs", ...(product?.units || []).filter((unit) => unit.is_active !== false).map((unit) => unit.code)].filter((unit, index, all) => all.indexOf(unit) === index).map((unit) => <option key={unit} value={unit}>{unit}</option>) })()}
                          </select>
                          <select value={row.warehouse_id} onChange={(event) => selectSaleWarehouse(row.key, event.target.value)}>
                            <option value="">{t("productUnits.useCompanyDefault")}</option>
                            {warehouses.filter((warehouse) => warehouse.is_active).map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}
                          </select>
                          {row.conversion_mode === "fixed" ? <small>1 {row.unit} = {formatQuantity(row.conversion_factor, row.inventory_unit, language)} {row.inventory_unit}. {t("dailySales.stockDeduction")}: {formatQuantity(Number(row.quantity || 0) * Number(row.conversion_factor || 0), row.inventory_unit, language)} {row.inventory_unit}</small> : null}
                          {row.conversion_mode === "variable" ? <label className="daily-sale-measured">{t("dailySales.actualMeasured")} ({row.inventory_unit})<input required type="number" min="0.001" step="0.001" value={row.actual_base_quantity} onChange={(event) => updateRow(row.key, { actual_base_quantity: event.target.value })} /></label> : null}
                        </div>
                      ) : (
                        <input
                          value={row.unit}
                          onChange={(event) =>
                            updateRow(row.key, { unit: event.target.value })
                          }
                        />
                      )}
                    </td>
                    <td>
                      <input
                        type="number"
                        min={isMeterUnit(row.unit) ? "0.001" : "1"}
                        step={isMeterUnit(row.unit) ? "0.001" : "1"}
                        value={row.quantity}
                        onChange={(event) =>
                          updateRow(row.key, { quantity: event.target.value })
                        }
                      />
                    </td>
                    <td>
                      <input
                        type="number"
                        min="0"
                        step="0.01"
                        value={row.unit_price}
                        onChange={(event) =>
                          updateRow(row.key, { unit_price: event.target.value })
                        }
                      />
                    </td>
                    <td className="daily-sales-amount">
                      {money(row.line_total)}
                    </td>
                    <td>
                      <button
                        type="button"
                        className="daily-sales-icon-button"
                        aria-label={t("dailySales.removeProduct")}
                        onClick={() => removeProduct(row.key)}
                      >
                        <X size={17} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <button
              type="button"
              className="daily-sales-add-product"
              onClick={addProduct}
            >
              <Plus size={16} /> {t("dailySales.addProduct")}
            </button>
            <div className="daily-sale-editor-footer">
              <label>
                {t("dailySales.saleNotes")}
                <textarea
                  rows="2"
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                />
              </label>
              <div className="form-actions">
                <button
                  type="button"
                  disabled={saving}
                  onClick={handleSave}
                >
                  <Save size={16} /> {t("dailySales.saveSale")}
                </button>
                <button
                  type="button"
                  className="secondary"
                  disabled={saving}
                  onClick={resetEditor}
                >
                  {t("dailySales.cancel")}
                </button>
              </div>
            </div>
          </section>
        ) : null}

        {loading ? (
          <p className="daily-sales-empty">{t("common.loading")}</p>
        ) : sales.length ? (
          sales
            .filter((sale) => sale.id !== editorId)
            .map((sale, index) => (
              <section className="daily-sale-entry" key={sale.id}>
                <div className="daily-sale-entry-heading">
                  <div>
                    <span>
                      {t("dailySales.saleNumber", {
                        number: saleSequence(sale, index + 1),
                      })}
                    </span>
                    <small>
                      {sale.sale_number} ·{" "}
                      {sale.status === "finalized"
                        ? t("dailySales.finalizedStatus")
                        : t("dailySales.draftStatus")}
                    </small>
                  </div>
                  <div className="daily-sale-entry-actions no-print">
                    {sale.status === "draft" ? (
                      <>
                        <button
                          type="button"
                          className="secondary"
                          onClick={() => editSale(sale)}
                        >
                          <Pencil size={15} /> {t("dailySales.editSale")}
                        </button>
                        {canDelete ? (
                          <button
                            type="button"
                            className="danger"
                            onClick={() => setDeleteTarget(sale)}
                          >
                            <Trash2 size={15} /> {t("dailySales.delete")}
                          </button>
                        ) : null}
                      </>
                    ) : null}
                  </div>
                  <div className="daily-sale-entry-total">
                    <span>{t("dailySales.saleTotal")}</span>
                    <strong>{money(sale.total_amount)}</strong>
                  </div>
                </div>
                <table className="daily-sales-table">
                  <thead>
                    <tr>
                      <th>{t("dailySales.line")}</th>
                      <th>{t("dailySales.colProduct")}</th>
                      <th>{t("dailySales.colUnit")}</th>
                      <th>{t("dailySales.colQuantity")}</th>
                      <th>{t("dailySales.colPrice")}</th>
                      <th>{t("dailySales.colAmount")}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sale.items?.map((item, itemIndex) => (
                      <tr key={item.id}>
                        <td className="daily-sales-line-number">
                          {itemIndex + 1}
                        </td>
                        <td>{item.product_name}</td>
                        <td>{item.unit}</td>
                        <td>{Number(item.quantity)}</td>
                        <td>{money(item.unit_price)}</td>
                        <td className="daily-sales-amount">
                          {money(item.line_total)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {sale.notes ? (
                  <p className="daily-sale-notes">
                    <strong>{t("dailySales.saleNotes")}:</strong> {sale.notes}
                  </p>
                ) : null}
              </section>
            ))
        ) : !editorOpen ? (
          <div className="daily-sales-empty">
            <p>{t("dailySales.noSalesForDate")}</p>
            <button type="button" className="no-print" onClick={startNewSale}>
              <Plus size={16} /> {t("dailySales.addFirstSale")}
            </button>
          </div>
        ) : null}

        <footer className="daily-sales-day-total">
          <span>{t("dailySales.dayTotal")}</span>
          <strong>{money(dayTotal)}</strong>
        </footer>
      </section>
    </main>
  );
}
