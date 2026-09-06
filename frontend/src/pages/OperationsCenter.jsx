import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { AlertTriangle, Boxes, ClipboardCheck, PackagePlus, RefreshCw, RotateCcw, Scale, ShoppingCart, Truck } from "lucide-react";
import { getAllProducts } from "../api/products";
import { getSuppliers } from "../api/suppliers";
import { getCustomerDebts } from "../api/customerDebts";
import { getPurchaseOrder, getPurchaseOrders } from "../api/purchaseOrders";
import { getGoodsReceipt, getGoodsReceipts, getWarehouseLocations, getWarehouses } from "../api/warehouseOperations";
import {
  allocateSupplierInvoicePayment, approveSupplierInvoice, cancelInventoryCount, createInventoryCount, createInventoryReturn, createLandedCost,
  createProductSupplier, createReplenishmentDrafts, createSupplierInvoice, getBinInventory,
  deactivateProductSupplier, deleteInventoryReturn, deleteLandedCost, getExpiringInventory, getFinancialAccounts,
  getInventoryCount, getInventoryCounts, getInventoryReturn, getInventoryReturns, getInventoryReturnSources,
  getLandedCosts, getProductSupplierHistory, getProductSuppliers, getReplenishment, getSupplierInvoice, getSupplierInvoices,
  getSupplierPerformance, moveBinStock, postLandedCost, recalculateSupplierInvoice,
  recordInventoryCount, requestInventoryRecount, submitInventoryCount, approveInventoryCount,
  transitionInventoryReturn, updateInventoryReturn, updateProductSupplier, updateSupplierInvoice,
} from "../api/advancedOperations";
import { useSettingsStore } from "../store/settingsStore";
import { useAuthStore } from "../store/authStore";
import "./OperationsCenter.css";

const text = {
  en: { title: "Inventory Operations", sub: "Execute bin movements, counts, replenishment, supplier costing and returns. Incoming acceptance decisions are managed separately in Quality Management.", locator: "Bin transfers & balances", counts: "Physical counts", expiry: "Expiry control", replenish: "Replenishment", catalogue: "Supplier catalogue", landed: "Landed cost", returns: "Returns", matching: "Supplier matching", save: "Save", create: "Create", refresh: "Refresh", all: "All", loading: "Loading…", empty: "No records found.", error: "The operation could not be completed.", success: "Saved successfully.", countSnapshot: "Snapshot captured", countScope: "Count scope", countSnapshotHint: "This is the comparison snapshot; stock is not frozen. Later movements are checked before posting a count.", allocatePayment: "Allocate Purchase Order payment", payment: "Payment", paymentAmount: "Amount to allocate", allocationReason: "Allocation note", noAdvance: "No unallocated Purchase Order payment is available.", existingAllocations: "Allocated advances", availablePayment: "available", allocate: "Allocate" },
  sq: { title: "Operacionet e Inventarit", sub: "Kryeni lëvizjet mes lokacioneve, numërimet, rimbushjen, kostot e furnitorëve dhe kthimet. Vendimet për pranimin e cilësisë menaxhohen veçmas te Menaxhimi i Cilësisë.", locator: "Transferet dhe gjendja sipas lokacionit", counts: "Numërimi fizik", expiry: "Kontrolli i skadimit", replenish: "Rimbushja", catalogue: "Katalogu i furnizuesit", landed: "Kostoja e importit", returns: "Kthimet", matching: "Përputhja e furnizuesit", save: "Ruaj", create: "Krijo", refresh: "Rifresko", all: "Të gjitha", loading: "Duke ngarkuar…", empty: "Nuk ka të dhëna.", error: "Veprimi nuk mund të kryhej.", success: "U ruajt me sukses.", countSnapshot: "Gjendja e fotografuar", countScope: "Fusha e numërimit", countSnapshotHint: "Kjo është gjendja krahasuese; stoku nuk ngrin. Lëvizjet e mëvonshme kontrollohen para regjistrimit të numërimit.", allocatePayment: "Aloko pagesën e porosisë", payment: "Pagesa", paymentAmount: "Shuma për alokim", allocationReason: "Shënim për alokimin", noAdvance: "Nuk ka pagesë të paalokuar të porosisë.", existingAllocations: "Avanset e alokuara", availablePayment: "në dispozicion", allocate: "Aloko" },
};

const rows = (value) => value?.data || value || [];
const label = (translations, en, sq) => translations === text.sq ? sq : en;
const uuid = () => crypto.randomUUID?.() || `${Date.now()}-${Math.random()}`;
const err = (error, fallback) => error?.errors ? Object.values(error.errors).flat().join(" ") : error?.message || fallback;

function Field({ label, children }) { return <label className="ops-field"><span>{label}</span>{children}</label>; }
function Notice({ value, error }) { return value ? <p className={`ops-notice ${error ? "is-error" : ""}`}>{value}</p> : null; }

export default function OperationsCenter() {
  const language = useSettingsStore((state) => state.language);
  const baseCurrency = useSettingsStore((state) => state.base_currency || "EUR");
  const permissions = useAuthStore((state) => state.permissions);
  const can = useCallback((permission) => permissions.includes(permission), [permissions]);
  const t = text[language] || text.en;
  const [tab, setTab] = useState("locator");
  const [meta, setMeta] = useState({ products: [], suppliers: [], warehouses: [], locations: [], customers: [], orders: [], receipts: [], accounts: [] });
  const [data, setData] = useState([]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");
  const [failed, setFailed] = useState(false);

  const loadMeta = useCallback(async () => {
    const safe = (promise) => promise.catch(() => []);
    const [products, suppliers, warehouses, customers, orders, receipts, accounts] = await Promise.all([
      safe(getAllProducts()), safe(getSuppliers()), safe(getWarehouses()), safe(getCustomerDebts({ per_page: 100 })),
      safe(getPurchaseOrders({ per_page: 100 })), safe(getGoodsReceipts({ per_page: 100 })), safe(getFinancialAccounts()),
    ]);
    const warehouseRows = rows(warehouses);
    const locations = (await Promise.all(warehouseRows.map((warehouse) => getWarehouseLocations(warehouse.id).catch(() => [])))).flatMap(rows);
    setMeta({ products: rows(products), suppliers: rows(suppliers), warehouses: warehouseRows, locations, customers: rows(customers), orders: rows(orders), receipts: rows(receipts), accounts: rows(accounts) });
  }, []);

  const loaders = useMemo(() => ({
    locator: () => getBinInventory({ per_page: 200 }), counts: () => getInventoryCounts({ per_page: 100 }), expiry: () => getExpiringInventory({ within_days: 365 }),
    replenish: () => getReplenishment({ include_ok: 0 }).then((value) => value.suggestions || []), catalogue: () => getProductSuppliers({ per_page: 100 }),
    landed: () => getLandedCosts({ per_page: 100 }), returns: () => getInventoryReturns({ per_page: 100 }),
    matching: () => getSupplierInvoices({ per_page: 100 }),
  }), []);

  const reload = useCallback(async () => {
    setBusy(true); setNotice("");
    try { setData(rows(await loaders[tab]())); setFailed(false); }
    catch (error) { setNotice(err(error, t.error)); setFailed(true); }
    finally { setBusy(false); }
  }, [loaders, tab, t.error]);

  useEffect(() => { loadMeta().catch((error) => { setNotice(err(error, t.error)); setFailed(true); }); }, [loadMeta, t.error]);
  useEffect(() => { reload(); }, [reload]);

  const done = async (operation) => {
    setBusy(true); setNotice("");
    try { await operation(); setNotice(t.success); setFailed(false); await Promise.all([reload(), loadMeta()]); }
    catch (error) { setNotice(err(error, t.error)); setFailed(true); setBusy(false); }
  };

  const tabs = [
    ["locator", t.locator, Boxes, "inventory.view"], ["counts", t.counts, ClipboardCheck, "inventory.counts.manage"],
    ["expiry", t.expiry, AlertTriangle, "inventory.view"],
    ["replenish", t.replenish, ShoppingCart, "replenishment.view"], ["catalogue", t.catalogue, Truck, "supplier_catalogue.view"],
    ["landed", t.landed, Scale, "landed_costs.manage"], ["returns", t.returns, RotateCcw, "returns.view"],
    ["matching", t.matching, PackagePlus, "supplier_invoices.view"],
  ].filter(([, , , permission]) => can(permission));

  return <div className="operations-center">
    <header className="ops-hero"><div><p>AIMS OPERATIONS</p><h1>{t.title}</h1><span>{t.sub}</span></div><button className="secondary" onClick={reload} disabled={busy}><RefreshCw size={16} /> {t.refresh}</button></header>
    <nav className="ops-tabs">{tabs.map(([id, label, Icon]) => <button key={id} className={tab === id ? "active" : ""} onClick={() => setTab(id)}><Icon size={17} />{label}</button>)}</nav>
    <Notice value={notice} error={failed} />
    {busy && data.length === 0 ? <p className="page-message">{t.loading}</p> : null}
    {tab === "locator" && <Locator data={data} meta={meta} run={done} t={t} can={can} />}
    {tab === "counts" && <Counts data={data} meta={meta} run={done} t={t} can={can} />}
    {tab === "expiry" && <ExpiryQueue data={data} meta={meta} t={t} />}
    {tab === "replenish" && <Replenishment data={data} meta={meta} run={done} t={t} can={can} />}
    {tab === "catalogue" && <Catalogue data={data} meta={meta} run={done} t={t} can={can} baseCurrency={baseCurrency} />}
    {tab === "landed" && <LandedCosts data={data} meta={meta} run={done} t={t} can={can} baseCurrency={baseCurrency} />}
    {tab === "returns" && <Returns data={data} meta={meta} run={done} t={t} can={can} baseCurrency={baseCurrency} />}
    {tab === "matching" && <SupplierMatching data={data} meta={meta} run={done} t={t} can={can} />}
  </div>;
}

function Locator({ data, meta, run, t, can }) {
  const idempotencyKeyRef = useRef(uuid());
  const [search, setSearch] = useState("");
  const [warehouseFilter, setWarehouseFilter] = useState("");
  const [form, setForm] = useState({ product_id: "", source_warehouse_id: "", destination_warehouse_id: "", source_location_id: "", destination_location_id: "", quantity: "", stock_state: "available", reason: "Bin relocation" });
  const sourceLocations = meta.locations.filter((location) => !form.source_warehouse_id || String(location.warehouse_id) === String(form.source_warehouse_id));
  const destinationLocations = meta.locations.filter((location) => (!form.destination_warehouse_id || String(location.warehouse_id) === String(form.destination_warehouse_id)) && String(location.id) !== String(form.source_location_id));
  const visible = data.filter((item) => !search || [item.product?.name, item.product?.sku, item.location?.path, item.warehouse?.name].some((value) => String(value || "").toLowerCase().includes(search.toLowerCase())));
  const filtered = visible.filter((item) => !warehouseFilter || String(item.warehouse_id || item.warehouse?.id) === String(warehouseFilter));
  return <section className={`ops-layout ${can("stock.manage") ? "" : "is-single"}`}>{can("stock.manage") && <aside className="ops-panel"><h2>Move between bins</h2><form onSubmit={(event) => { event.preventDefault(); run(async () => { const key = idempotencyKeyRef.current || uuid(); idempotencyKeyRef.current = key; await moveBinStock({ product_id: +form.product_id, source_location_id: +form.source_location_id, destination_location_id: +form.destination_location_id, quantity: +form.quantity, stock_state: form.stock_state, reason: form.reason, idempotency_key: key }); idempotencyKeyRef.current = uuid(); }); }}>
    <Field label={label(t, "Product", "Produkti")}><select required value={form.product_id} onChange={(e) => setForm({ ...form, product_id: e.target.value })}><option value="">—</option>{meta.products.map((p) => <option key={p.id} value={p.id}>{p.name} · {p.sku}</option>)}</select></Field>
    <Field label={label(t, "Source warehouse", "Magazina burim")}><select required value={form.source_warehouse_id} onChange={(e) => setForm({ ...form, source_warehouse_id: e.target.value, source_location_id: "" })}><option value="">—</option>{meta.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></Field>
    <Field label={label(t, "Source bin", "Lokacioni burim")}><select required value={form.source_location_id} onChange={(e) => setForm({ ...form, source_location_id: e.target.value })}><option value="">—</option>{sourceLocations.map((l) => <option key={l.id} value={l.id}>{l.path || l.name}</option>)}</select></Field>
    <Field label={label(t, "Destination warehouse", "Magazina pritëse")}><select required value={form.destination_warehouse_id} onChange={(e) => setForm({ ...form, destination_warehouse_id: e.target.value, destination_location_id: "" })}><option value="">—</option>{meta.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></Field>
    <Field label={label(t, "Destination bin", "Destinacioni")}><select required value={form.destination_location_id} onChange={(e) => setForm({ ...form, destination_location_id: e.target.value })}><option value="">—</option>{destinationLocations.map((l) => <option key={l.id} value={l.id}>{l.path || l.name}</option>)}</select></Field>
    <Field label={label(t, "Quantity", "Sasia")}><input required type="number" min="0.001" step="0.001" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} /></Field><button>{t.save}</button>
  </form></aside>}<div className="ops-panel"><div className="ops-panel-head"><h2>{t.locator}</h2><div className="ops-filter-row"><select aria-label="Filter warehouse" value={warehouseFilter} onChange={(e) => setWarehouseFilter(e.target.value)}><option value="">All warehouses</option>{meta.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select><input type="search" placeholder="Search product, SKU, warehouse or bin…" value={search} onChange={(e) => setSearch(e.target.value)} /></div></div><div className="ops-table"><table><thead><tr><th>Product</th><th>Warehouse</th><th>Bin</th><th>Available</th><th>Reserved</th><th>Damaged</th><th>Total</th></tr></thead><tbody>{filtered.map((item) => <tr key={item.id}><td><strong>{item.product?.name}</strong><small>{item.product?.sku}</small></td><td>{item.warehouse?.name}</td><td>{item.location?.path || "Unassigned"}</td><td>{item.available_quantity}</td><td>{item.reserved_quantity}</td><td>{item.damaged_quantity}</td><td><strong>{item.quantity}</strong> {item.product?.unit}</td></tr>)}</tbody></table>{!filtered.length && <p>{t.empty}</p>}</div></div></section>;
}

function Counts({ data, meta, run, t, can }) {
  const [form, setForm] = useState({ warehouse_id: "", location_id: "", product_ids: [], stock_states: ["available"], notes: "" });
  const [selected, setSelected] = useState(null);
  const [values, setValues] = useState({});
  const [recountItems, setRecountItems] = useState([]);
  const [reason, setReason] = useState("");
  const open = async (id) => {
    const session = await getInventoryCount(id);
    setSelected(session);
    setValues(Object.fromEntries((session.items || []).map((item) => [item.id, item.counted_quantity ?? ""])));
    setRecountItems([]);
    setReason("");
  };
  const locations = meta.locations.filter((location) => String(location.warehouse_id) === String(form.warehouse_id));
  const active = selected && ["in_progress", "recount_required"].includes(selected.status);
  const refreshAfter = (operation) => run(async () => { await operation(); await open(selected.id); });
  const setState = (state, enabled) => setForm((current) => ({ ...current, stock_states: enabled ? [...new Set([...current.stock_states, state])] : current.stock_states.filter((value) => value !== state) }));

  return <section className="ops-layout">
    <aside className="ops-panel">
      <h2>{label(t, "New count", "Numërim i ri")}</h2>
      <form onSubmit={(event) => { event.preventDefault(); run(() => createInventoryCount({ warehouse_id: +form.warehouse_id, location_id: form.location_id ? +form.location_id : null, product_ids: form.product_ids.map(Number), stock_states: form.stock_states, notes: form.notes || null })); }}>
        <Field label={label(t, "Warehouse", "Magazina")}><select required value={form.warehouse_id} onChange={(event) => setForm({ ...form, warehouse_id: event.target.value, location_id: "" })}><option value="">—</option>{meta.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></Field>
        <Field label={label(t, "Location scope", "Lokacioni")}><select value={form.location_id} onChange={(event) => setForm({ ...form, location_id: event.target.value })}><option value="">{label(t, "Full warehouse", "E gjithë magazina")}</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.path || location.name}</option>)}</select></Field>
        <Field label={label(t, "Products (optional)", "Produktet (opsionale)")}><select multiple size="5" value={form.product_ids.map(String)} onChange={(event) => setForm({ ...form, product_ids: [...event.target.selectedOptions].map((option) => option.value) })}>{meta.products.map((product) => <option key={product.id} value={product.id}>{product.name} · {product.sku || "—"}</option>)}</select></Field>
        <fieldset className="ops-check-group"><legend>{label(t, "Stock states", "Gjendjet")}</legend>{["available", "damaged", "quarantine"].map((state) => <label key={state}><input type="checkbox" checked={form.stock_states.includes(state)} onChange={(event) => setState(state, event.target.checked)} /> {state}</label>)}</fieldset>
        <Field label={label(t, "Notes", "Shënime")}><textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></Field>
        <button disabled={!form.stock_states.length}>{t.create}</button>
      </form>
      <div className="ops-card-list">{data.map((item) => <button type="button" className={`ops-record ${selected?.id === item.id ? "active" : ""}`} key={item.id} onClick={() => open(item.id)}><strong>{item.count_number}</strong><span>{item.warehouse?.name}{item.location?.path ? ` · ${item.location.path}` : ""} · {item.status}</span></button>)}</div>
    </aside>
    <div className="ops-panel">
      <h2>{selected?.count_number || t.counts}</h2>
      {selected ? <>
        <p className="ops-summary">{t.countSnapshot}: {selected.snapshot_at || selected.frozen_at ? new Date(selected.snapshot_at || selected.frozen_at).toLocaleString() : "—"} · {t.countScope}: {(selected.stock_states || []).join(", ")}</p>
        <p className="ops-summary"><small>{t.countSnapshotHint}</small></p>
        <div className="ops-table"><table><thead><tr><th>Recount</th><th>Product</th><th>Bin / state</th><th>Trace</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead><tbody>{(selected.items || []).map((item) => <tr key={item.id}>
          <td><input aria-label="Select for recount" type="checkbox" disabled={["approved", "cancelled"].includes(selected.status)} checked={recountItems.includes(item.id)} onChange={() => setRecountItems(recountItems.includes(item.id) ? recountItems.filter((id) => id !== item.id) : [...recountItems, item.id])} /></td>
          <td><strong>{item.product?.name}</strong><small>{item.product?.sku}</small></td>
          <td>{item.location?.path || "Unassigned"}<small>{item.stock_state}</small></td>
          <td>{item.lot?.serial_number || item.lot?.lot_number || "—"}</td><td>{item.expected_quantity}</td>
          <td><input disabled={!active} type="number" min="0" step="0.001" value={values[item.id] ?? ""} onChange={(event) => setValues({ ...values, [item.id]: event.target.value })} /></td><td>{item.variance_quantity ?? "—"}</td>
        </tr>)}</tbody></table></div>
        <Field label={label(t, "Action reason", "Arsyeja e veprimit")}><input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Required for recount, approval or cancellation" /></Field>
        <div className="ops-actions">
          {active && <button onClick={() => refreshAfter(() => recordInventoryCount(selected.id, { items: Object.entries(values).filter(([, value]) => value !== "").map(([id, value]) => ({ count_item_id: +id, counted_quantity: +value })) }))}>{t.save}</button>}
          {active && <button className="secondary" onClick={() => refreshAfter(() => submitInventoryCount(selected.id))}>Submit</button>}
          {can("inventory.counts.manage") && recountItems.length > 0 && !["approved", "cancelled"].includes(selected.status) && <button className="secondary" disabled={reason.trim().length < 3} onClick={() => refreshAfter(() => requestInventoryRecount(selected.id, { item_ids: recountItems, reason }))}>Request recount</button>}
          {can("inventory.counts.approve") && selected.status === "submitted" && <button disabled={reason.trim().length < 3} onClick={() => refreshAfter(() => approveInventoryCount(selected.id, reason))}>Approve & adjust</button>}
          {can("inventory.counts.approve") && !["approved", "cancelled"].includes(selected.status) && <button className="danger" disabled={reason.trim().length < 3} onClick={() => refreshAfter(() => cancelInventoryCount(selected.id, reason))}>Cancel</button>}
        </div>
        <details className="ops-history"><summary>{label(t, "Count and recount history", "Historia e numërimit")}</summary>{(selected.items || []).flatMap((item) => (item.entries || []).map((entry) => <article key={entry.id}><strong>{item.product?.name} · round {entry.count_round} · {entry.entry_type}</strong><span>{entry.counted_quantity ?? "—"} · {entry.user?.name || "—"} · {entry.entered_at ? new Date(entry.entered_at).toLocaleString() : "—"}</span>{entry.notes && <p>{entry.notes}</p>}</article>))}</details>
      </> : <p>{t.empty}</p>}
    </div>
  </section>;
}

function ExpiryQueue({ data, meta, t }) {
  const [warehouse, setWarehouse] = useState("");
  const [product, setProduct] = useState("");
  const [status, setStatus] = useState("");
  const lots = data.filter((lot) => (!product || String(lot.product_id) === product) && (!status || lot.expiry_status === status));
  const rows = lots.flatMap((lot) => (lot.balances || []).filter((balance) => !warehouse || String(balance.warehouse_id) === warehouse).map((balance) => ({ lot, balance })));
  const expiryProducts = meta.products.filter((item) => item.expiration_controlled || item.tracking_mode === "batch_expiry");
  return <section className="ops-panel"><div className="ops-panel-head"><div><h2>{t.expiry}</h2><p>Live work queue for expired and near-expiry batches that still have stock.</p></div><div className="ops-filter-row"><select value={warehouse} onChange={(event) => setWarehouse(event.target.value)}><option value="">All warehouses</option>{meta.warehouses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><select value={product} onChange={(event) => setProduct(event.target.value)}><option value="">All expiry-controlled products</option>{expiryProducts.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><select value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All warning states</option><option value="expired">Expired</option><option value="near_expiry">Near expiry</option></select></div></div><div className="ops-table"><table><thead><tr><th>Status</th><th>Product</th><th>Lot / supplier batch</th><th>Expiry</th><th>Warehouse / bin</th><th>Quantity</th></tr></thead><tbody>{rows.map(({ lot, balance }) => <tr key={`${lot.id}-${balance.id}`} className={lot.expiry_status === "expired" ? "ops-expired" : "ops-near-expiry"}><td><strong>{lot.expiry_status}</strong></td><td>{lot.product?.name}<small>{lot.product?.sku}</small></td><td>{lot.lot_number || "—"}<small>{lot.supplier_batch || "—"}</small></td><td>{String(lot.expiry_at || "").slice(0, 10)}</td><td>{balance.warehouse?.name}<small>{balance.location?.path || "Unassigned"}</small></td><td>{balance.quantity}</td></tr>)}</tbody></table>{!rows.length && <p>{t.empty}</p>}</div></section>;
}

function Replenishment({ data, meta, run, t, can }) {
  const [selected, setSelected] = useState([]); const [warehouse, setWarehouse] = useState("");
  return <section className="ops-panel"><div className="ops-panel-head"><div><h2>{t.replenish}</h2><p>Every suggestion shows the stock, usage, lead-time, safety, MOQ and pack calculation used by AIMS.</p></div><div className="ops-actions"><select value={warehouse} onChange={(e) => setWarehouse(e.target.value)}><option value="">Default warehouse</option>{meta.warehouses.map((w) => <option key={w.id} value={w.id}>{w.name}</option>)}</select>{can("replenishment.manage") && <button disabled={!selected.length} onClick={() => run(() => createReplenishmentDrafts({ product_ids: selected, warehouse_id: warehouse ? +warehouse : null, notes: "Created from reviewed replenishment suggestions" }))}>Create draft PO</button>}</div></div><div className="ops-table ops-wide-table"><table><thead><tr><th></th><th>Product</th><th>On hand / reserved</th><th>Available + incoming</th><th>Projected / reorder point</th><th>Usage/day · history</th><th>Safety / minimum</th><th>Lead · stockout</th><th>MOQ / pack</th><th>Suggested</th><th>Supplier</th><th>Why</th></tr></thead><tbody>{data.map((item) => { const c = item.calculation || {}; const supplier = item.preferred_supplier || {}; return <tr key={item.product_id}><td>{can("replenishment.manage") && <input type="checkbox" checked={selected.includes(item.product_id)} onChange={() => setSelected(selected.includes(item.product_id) ? selected.filter((id) => id !== item.product_id) : [...selected, item.product_id])} />}</td><td><strong>{item.product_name}</strong><small>{item.sku} · {item.unit}</small></td><td>{c.on_hand} / {c.reserved}</td><td>{c.available} + {c.confirmed_incoming}</td><td><strong>{c.projected}</strong> / {c.effective_reorder_point}</td><td>{c.average_daily_usage}<small>{c.usage_in_history} in {c.history_days} days</small></td><td>{c.safety_stock} / {c.minimum_stock}</td><td>{c.supplier_lead_time_days} days<small>{item.expected_stockout_date || "No calculated stockout"}</small></td><td>{c.minimum_order_quantity} / {c.pack_size}</td><td><strong>{item.recommended_quantity}</strong></td><td>{supplier.supplier_name || "—"}<small>{supplier.purchase_price ?? "—"} {supplier.currency || ""}</small></td><td>{item.reason}<details><summary>Formula</summary><small>{(c.formula || []).join(" · ")}</small></details></td></tr>; })}</tbody></table>{!data.length && <p>{t.empty}</p>}</div></section>;
}

function Catalogue({ data, meta, run, t, can, baseCurrency }) {
  const today = new Date().toISOString().slice(0, 10);
  const blank = () => ({ product_id: "", supplier_id: "", supplier_sku: "", purchase_price: "", currency: baseCurrency, exchange_rate_to_base: 1, exchange_rate_date: today, pack_size: 1, minimum_order_quantity: 0, usual_lead_time_days: 0, is_preferred: false, is_active: true, supplier_description: "", price_effective_at: today, price_change_reason: "" });
  const [form, setForm] = useState(blank);
  const [editing, setEditing] = useState(null);
  const [performance, setPerformance] = useState(null);
  const [history, setHistory] = useState(null);
  const edit = (item) => {
    setEditing(item.id);
    setForm({ ...blank(), product_id: String(item.product_id), supplier_id: String(item.supplier_id), supplier_sku: item.supplier_sku || "", purchase_price: item.purchase_price ?? "", currency: item.currency || baseCurrency, exchange_rate_to_base: item.exchange_rate_to_base || 1, exchange_rate_date: String(item.exchange_rate_date || today).slice(0, 10), pack_size: item.pack_size || 1, minimum_order_quantity: item.minimum_order_quantity || 0, usual_lead_time_days: item.usual_lead_time_days || 0, is_preferred: Boolean(item.is_preferred), is_active: Boolean(item.is_active), supplier_description: item.supplier_description || "" });
  };
  const submit = (event) => {
    event.preventDefault();
    const values = { supplier_sku: form.supplier_sku || null, purchase_price: form.purchase_price === "" ? null : +form.purchase_price, currency: form.currency, exchange_rate_to_base: form.currency === baseCurrency ? 1 : +form.exchange_rate_to_base, exchange_rate_date: form.currency === baseCurrency ? null : form.exchange_rate_date, pack_size: +form.pack_size, minimum_order_quantity: +form.minimum_order_quantity, usual_lead_time_days: +form.usual_lead_time_days, is_preferred: form.is_preferred, is_active: form.is_active, supplier_description: form.supplier_description || null, price_effective_at: form.price_effective_at || null, price_change_reason: form.price_change_reason || null };
    run(async () => { if (editing) await updateProductSupplier(editing, values); else await createProductSupplier({ ...values, product_id: +form.product_id, supplier_id: +form.supplier_id }); setEditing(null); setForm(blank()); });
  };
  const cards = <div className="ops-card-list">{data.map((item) => <article className={`ops-card ${item.is_active ? "" : "is-muted"}`} key={item.id}><div><strong>{item.product?.name}{item.is_preferred ? " ★" : ""}</strong><span>{item.supplier?.name} · {item.supplier_sku || "No supplier SKU"}</span>{item.supplier_description && <small>{item.supplier_description}</small>}</div><div><strong>{item.purchase_price ?? "—"} {item.currency}</strong><span>Pack {item.pack_size} · MOQ {item.minimum_order_quantity} · {item.usual_lead_time_days} days · {item.is_active ? "Active" : "Inactive"}</span></div><div className="ops-actions"><button className="secondary" onClick={async () => { setHistory({ item, rows: await getProductSupplierHistory(item.id) }); }}>Price history</button><button className="secondary" onClick={async () => setPerformance(await getSupplierPerformance(item.supplier_id))}>Performance</button>{can("supplier_catalogue.manage") && <button className="secondary" onClick={() => edit(item)}>Edit</button>}{can("supplier_catalogue.manage") && item.is_active && <button className="danger" onClick={() => run(() => deactivateProductSupplier(item.id))}>Deactivate</button>}</div></article>)}</div>;
  if (!can("supplier_catalogue.manage")) return <section className="ops-panel"><h2>{t.catalogue}</h2>{cards}<CatalogueInsights performance={performance} history={history} baseCurrency={baseCurrency} /></section>;
  return <section className="ops-layout"><aside className="ops-panel"><h2>{editing ? "Edit supplier offer" : "Add supplier offer"}</h2><form onSubmit={submit}>
    <Field label="Product"><select required disabled={Boolean(editing)} value={form.product_id} onChange={(e) => setForm({ ...form, product_id: e.target.value })}><option value="">—</option>{meta.products.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
    <Field label="Supplier"><select required disabled={Boolean(editing)} value={form.supplier_id} onChange={(e) => setForm({ ...form, supplier_id: e.target.value })}><option value="">—</option>{meta.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
    <Field label="Supplier SKU"><input value={form.supplier_sku} onChange={(e) => setForm({ ...form, supplier_sku: e.target.value })} /></Field>
    <div className="ops-form-grid"><Field label="Price"><input type="number" min="0" step="0.000001" value={form.purchase_price} onChange={(e) => setForm({ ...form, purchase_price: e.target.value })} /></Field><Field label="Currency"><select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value, exchange_rate_to_base: e.target.value === baseCurrency ? 1 : form.exchange_rate_to_base })}>{[...new Set([baseCurrency, "EUR", "USD", "ALL", "GBP", "CHF", "CNY"])].map((currency) => <option key={currency}>{currency}</option>)}</select></Field>{form.currency !== baseCurrency && <><Field label={`Rate to ${baseCurrency}`}><input required type="number" min="0.00000001" step="0.00000001" value={form.exchange_rate_to_base} onChange={(e) => setForm({ ...form, exchange_rate_to_base: e.target.value })} /></Field><Field label="Rate date"><input required type="date" value={form.exchange_rate_date} onChange={(e) => setForm({ ...form, exchange_rate_date: e.target.value })} /></Field></>}<Field label="Pack size"><input type="number" min="0.001" step="0.001" value={form.pack_size} onChange={(e) => setForm({ ...form, pack_size: e.target.value })} /></Field><Field label="MOQ"><input type="number" min="0" step="0.001" value={form.minimum_order_quantity} onChange={(e) => setForm({ ...form, minimum_order_quantity: e.target.value })} /></Field><Field label="Lead days"><input type="number" min="0" value={form.usual_lead_time_days} onChange={(e) => setForm({ ...form, usual_lead_time_days: e.target.value })} /></Field></div>
    <Field label="Supplier product description"><textarea value={form.supplier_description} onChange={(e) => setForm({ ...form, supplier_description: e.target.value })} /></Field>
    <div className="ops-form-grid"><Field label="Price effective date"><input type="date" value={form.price_effective_at} onChange={(e) => setForm({ ...form, price_effective_at: e.target.value })} /></Field><Field label="Price change reason"><input value={form.price_change_reason} onChange={(e) => setForm({ ...form, price_change_reason: e.target.value })} /></Field></div>
    <label className="ops-check"><input type="checkbox" checked={form.is_preferred} onChange={(e) => setForm({ ...form, is_preferred: e.target.checked })} /> Preferred supplier</label><label className="ops-check"><input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} /> Active</label>
    <div className="ops-actions"><button>{t.save}</button>{editing && <button type="button" className="secondary" onClick={() => { setEditing(null); setForm(blank()); }}>Cancel edit</button>}</div>
  </form></aside><div className="ops-panel"><h2>{t.catalogue}</h2>{cards}<CatalogueInsights performance={performance} history={history} baseCurrency={baseCurrency} /></div></section>;
}

function CatalogueInsights({ performance, history, baseCurrency }) {
  const categories = performance?.category_scores || {};
  return <>{history && <section className="ops-insight"><h3>{history.item.product?.name} · price history</h3><div className="ops-table"><table><thead><tr><th>Effective</th><th>Price</th><th>{baseCurrency} value</th><th>Changed by</th><th>Reason</th></tr></thead><tbody>{history.rows.map((row) => <tr key={row.id}><td>{String(row.effective_at || "").slice(0, 10)}</td><td>{row.purchase_price} {row.currency}</td><td>{row.base_currency_price}</td><td>{row.changed_by?.name || "—"}</td><td>{row.change_reason || "—"}</td></tr>)}</tbody></table></div></section>}{performance && <section className="ops-insight"><h3>Authoritative supplier scorecard · {performance.supplier_name}</h3><p>One shared score is used here, in Quality Management, Suppliers and Procurement.</p><div className="ops-metric-grid"><div><span>Overall score</span><strong>{performance.overall_score ?? "Insufficient data"}</strong></div>{Object.entries(categories).map(([key, value]) => <div key={key}><span>{key.toLowerCase()}</span><strong>{value?.score ?? "—"}</strong></div>)}</div>{performance.explanation?.map((line) => <small key={line}>{line}</small>)}</section>}</>;
}

function LandedCosts({ data, meta, run, t, can, baseCurrency }) {
  const [form, setForm] = useState({ goods_receipt_id: "", cost_type: "freight", amount: "", currency: baseCurrency, exchange_rate_to_base: 1, exchange_rate_date: new Date().toISOString().slice(0, 10), allocation_method: "value", reference_number: "", description: "" });
  const [receipt, setReceipt] = useState(null);
  const [receiptBusy, setReceiptBusy] = useState(false);
  const [selectedItems, setSelectedItems] = useState([]);
  const [manualAmounts, setManualAmounts] = useState({});

  useEffect(() => {
    let current = true;
    if (!form.goods_receipt_id) { setReceipt(null); setSelectedItems([]); setManualAmounts({}); return () => { current = false; }; }
    setReceipt(null); setSelectedItems([]); setManualAmounts({}); setReceiptBusy(true);
    getGoodsReceipt(form.goods_receipt_id).then((value) => {
      if (!current) return;
      const validItems = (value.items || []).filter((item) => +(item.accepted_base_quantity ?? item.accepted_quantity ?? 0) + +(item.damaged_base_quantity ?? item.damaged_quantity ?? 0) > 0);
      setReceipt(value);
      setSelectedItems(validItems.map((item) => item.id));
      setManualAmounts(Object.fromEntries(validItems.map((item) => [item.id, ""])));
    }).catch(() => { if (current) setReceipt(null); }).finally(() => { if (current) setReceiptBusy(false); });
    return () => { current = false; };
  }, [form.goods_receipt_id]);

  const receiptItems = (receipt?.items || []).filter((item) => +(item.accepted_base_quantity ?? item.accepted_quantity ?? 0) + +(item.damaged_base_quantity ?? item.damaged_quantity ?? 0) > 0);
  const manualTotal = selectedItems.reduce((sum, id) => sum + (+manualAmounts[id] || 0), 0);
  const manualComplete = selectedItems.length > 0 && selectedItems.every((id) => +manualAmounts[id] > 0) && Math.abs(manualTotal - (+form.amount || 0)) <= 0.005;
  const submit = (event) => {
    event.preventDefault();
    const payload = { ...form, goods_receipt_id: +form.goods_receipt_id, amount: +form.amount, exchange_rate_to_base: form.currency === baseCurrency ? 1 : +form.exchange_rate_to_base, exchange_rate_date: form.currency === baseCurrency ? null : form.exchange_rate_date, idempotency_key: uuid() };
    if (form.allocation_method === "manual") payload.allocations = selectedItems.map((id) => ({ goods_receipt_item_id: +id, amount: +manualAmounts[id] || 0 }));
    else payload.goods_receipt_item_ids = selectedItems.map(Number);
    run(() => createLandedCost(payload));
  };

  return <section className="ops-layout"><aside className="ops-panel"><h2>Allocate import cost</h2><form onSubmit={submit}><Field label="Goods receipt"><select required value={form.goods_receipt_id} onChange={(e) => setForm({ ...form, goods_receipt_id: e.target.value })}><option value="">{meta.receipts.length ? "Select a goods receipt" : "No received purchase orders available"}</option>{meta.receipts.map((r) => <option key={r.id} value={r.id}>{r.receipt_number} · {r.purchase_order?.po_number || ""}</option>)}</select>{!meta.receipts.length && <small className="ops-field-hint">Receive products from a purchase order first, then allocate freight, customs or other import costs here.</small>}</Field><Field label="Cost type"><select value={form.cost_type} onChange={(e) => setForm({ ...form, cost_type: e.target.value })}>{["freight", "customs", "insurance", "port", "forwarding", "inland_transport", "handling", "other"].map((v) => <option key={v}>{v.replaceAll("_", " ")}</option>)}</select></Field><div className="ops-form-grid"><Field label="Amount"><input required type="number" min="0.01" step="0.01" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} /></Field><Field label="Currency"><select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value, exchange_rate_to_base: e.target.value === baseCurrency ? 1 : form.exchange_rate_to_base })}>{[...new Set([baseCurrency, "EUR", "USD", "ALL", "GBP", "CHF", "CNY"])].map((currency) => <option key={currency}>{currency}</option>)}</select></Field>{form.currency !== baseCurrency && <><Field label={`Rate to ${baseCurrency}`}><input required type="number" min="0.00000001" step="0.00000001" value={form.exchange_rate_to_base} onChange={(e) => setForm({ ...form, exchange_rate_to_base: e.target.value })} /></Field><Field label="Rate date"><input required type="date" value={form.exchange_rate_date} onChange={(e) => setForm({ ...form, exchange_rate_date: e.target.value })} /></Field></>}<Field label="Allocation"><select value={form.allocation_method} onChange={(e) => setForm({ ...form, allocation_method: e.target.value })}>{["quantity", "value", "weight", "volume", "manual"].map((v) => <option key={v}>{v}</option>)}</select></Field></div>{receiptBusy && <small>Loading receipt lines…</small>}{receiptItems.length > 0 && <fieldset className="ops-allocation-lines"><legend>{label(t, "Receipt lines", "Rreshtat e pranimit")}</legend>{receiptItems.map((item) => { const quantity = +(item.accepted_base_quantity ?? item.accepted_quantity ?? 0) + +(item.damaged_base_quantity ?? item.damaged_quantity ?? 0); return <label key={item.id} className="ops-allocation-line"><input type="checkbox" checked={selectedItems.includes(item.id)} onChange={(e) => setSelectedItems(e.target.checked ? [...selectedItems, item.id] : selectedItems.filter((id) => id !== item.id))} /><span><strong>{item.product?.name || item.purchase_order_item?.description}</strong><small>{quantity} {item.product?.unit || item.purchase_order_item?.unit}</small></span>{form.allocation_method === "manual" && selectedItems.includes(item.id) && <input aria-label={`Amount for ${item.product?.name || item.id}`} required type="number" min="0.01" step="0.01" value={manualAmounts[item.id] || ""} onChange={(e) => setManualAmounts({ ...manualAmounts, [item.id]: e.target.value })} />}</label>; })}{form.allocation_method === "manual" && <p className={!manualComplete ? "ops-total is-invalid" : "ops-total"}>Allocated: {manualTotal.toFixed(2)} / {(+form.amount || 0).toFixed(2)} {form.currency}</p>}</fieldset>}<Field label="Reference"><input value={form.reference_number} onChange={(e) => setForm({ ...form, reference_number: e.target.value })} /></Field><Field label="Description"><textarea value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field><button disabled={!selectedItems.length || (form.allocation_method === "manual" && !manualComplete)}>{t.create}</button></form></aside><div className="ops-panel"><h2>{t.landed}</h2><div className="ops-card-list">{data.map((item) => <article className="ops-card" key={item.id}><div><strong>{(item.cost_type || "other").replaceAll("_", " ")} · {item.base_currency_amount} {item.base_currency || baseCurrency}</strong><span>{item.goods_receipt?.receipt_number} · {item.amount} {item.currency} @ {item.exchange_rate_to_base} · {item.allocation_method} · {item.status}</span>{item.status === "posted" && <small>Inventory / Inventari: {Number(item.inventory_adjustment_total || 0).toFixed(2)} {item.base_currency || baseCurrency} · COGS: {Number(item.cogs_adjustment_total || 0).toFixed(2)} {item.base_currency || baseCurrency}</small>}</div>{can("landed_costs.manage") && item.status === "draft" && <div className="ops-actions"><button onClick={() => run(() => postLandedCost(item.id))}>Post allocation</button><button className="danger" onClick={() => { if (window.confirm("Delete this draft landed cost?")) run(() => deleteLandedCost(item.id)); }}>Delete draft</button></div>}</article>)}</div></div></section>;
}

function Returns({ data, meta, run, t, can, baseCurrency }) {
  const idempotencyKeyRef = useRef(uuid());
  const blankItem = () => ({ product_id: "", source_item_id: "", warehouse_id: "", location_id: "", quantity: "", condition: "sellable", notes: "", lot_number: "", serial_numbers: "", supplier_batch: "", manufactured_at: "", expiry_at: "" });
  const blankForm = () => ({ type: "customer", customer_id: "", supplier_id: "", source_id: "", financial_resolution: "none", financial_amount: "", financial_account_id: "", payment_method: "cash", reference_number: "", reason: "", notes: "", items: [blankItem()] });
  const [form, setForm] = useState(blankForm);
  const [sources, setSources] = useState({ sales: [], receipts: [] });
  const [selected, setSelected] = useState(null);
  const [editing, setEditing] = useState(false);
  const [actionReason, setActionReason] = useState("");
  const partyId = form.type === "customer" ? form.customer_id : form.supplier_id;

  useEffect(() => {
    let current = true;
    getInventoryReturnSources({ type: form.type, ...(form.type === "customer" && partyId ? { customer_id: partyId } : {}), ...(form.type === "supplier" && partyId ? { supplier_id: partyId } : {}) })
      .then((value) => { if (current) setSources(value); }).catch(() => { if (current) setSources({ sales: [], receipts: [] }); });
    return () => { current = false; };
  }, [form.type, partyId]);

  const sourceRecords = form.type === "customer" ? sources.sales : sources.receipts;
  const source = sourceRecords.find((record) => String(record.id) === String(form.source_id));
  const updateLine = (index, values) => setForm((current) => ({ ...current, items: current.items.map((line, lineIndex) => lineIndex === index ? { ...line, ...values } : line) }));
  const serials = (line) => [...new Set(String(line.serial_numbers || "").split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean))];
  const sourceItems = source?.items || [];
  const traceData = (line, product, quantity) => {
    const mode = product?.tracking_mode || "none";
    if (mode === "serial") return { allocations: serials(line).map((serial_number) => ({ serial_number, quantity: 1 })) };
    if (["batch", "batch_expiry"].includes(mode)) return { allocations: [{ lot_number: line.lot_number, supplier_batch: line.supplier_batch || null, manufactured_at: line.manufactured_at || null, expiry_at: line.expiry_at || null, quantity }] };
    return undefined;
  };
  const payload = () => ({
    type: form.type, customer_id: form.type === "customer" ? +form.customer_id : null, supplier_id: form.type === "supplier" ? +form.supplier_id : null,
    daily_sale_id: form.type === "customer" && form.source_id ? +form.source_id : null,
    purchase_order_id: form.type === "supplier" && source?.purchase_order_id ? +source.purchase_order_id : null,
    goods_receipt_id: form.type === "supplier" && form.source_id ? +form.source_id : null,
    financial_resolution: form.financial_resolution, financial_amount: +form.financial_amount || 0,
    financial_account_id: form.financial_account_id ? +form.financial_account_id : null, payment_method: form.payment_method || null,
    currency: baseCurrency, reference_number: form.reference_number || null, reason: form.reason, notes: form.notes || null,
    items: form.items.map((line) => { const product = meta.products.find((item) => String(item.id) === String(line.product_id)); const quantity = product?.tracking_mode === "serial" ? serials(line).length : +line.quantity; return {
      product_id: +line.product_id, daily_sale_item_id: form.type === "customer" && line.source_item_id ? +line.source_item_id : null,
      goods_receipt_item_id: form.type === "supplier" && line.source_item_id ? +line.source_item_id : null,
      warehouse_id: +line.warehouse_id, location_id: line.location_id ? +line.location_id : null, quantity, condition: line.condition,
      trace_data: traceData(line, product, quantity), notes: line.notes || null,
    }; }),
  });
  const submit = (event) => { event.preventDefault(); run(async () => { if (editing && selected) setSelected(await updateInventoryReturn(selected.id, payload())); else { const key = idempotencyKeyRef.current || uuid(); idempotencyKeyRef.current = key; setSelected(await createInventoryReturn({ ...payload(), idempotency_key: key })); idempotencyKeyRef.current = uuid(); } setEditing(false); }); };
  const open = async (id) => { setSelected(await getInventoryReturn(id)); setEditing(false); setActionReason(""); };
  const edit = () => { const record = selected; setForm({ type: record.type, customer_id: record.customer_id ? String(record.customer_id) : "", supplier_id: record.supplier_id ? String(record.supplier_id) : "", source_id: record.type === "customer" ? String(record.daily_sale_id || "") : String(record.goods_receipt_id || ""), financial_resolution: record.financial_resolution, financial_amount: record.financial_amount || "", financial_account_id: record.financial_account_id ? String(record.financial_account_id) : "", payment_method: record.payment_method || "cash", reference_number: record.reference_number || "", reason: record.reason || "", notes: record.notes || "", items: record.items.map((item) => { const allocations = item.trace_data?.allocations || []; return { ...blankItem(), product_id: String(item.product_id), source_item_id: String(record.type === "customer" ? item.daily_sale_item_id || "" : item.goods_receipt_item_id || ""), warehouse_id: String(item.warehouse_id), location_id: item.location_id ? String(item.location_id) : "", quantity: item.quantity, condition: item.condition, notes: item.notes || "", lot_number: allocations[0]?.lot_number || "", serial_numbers: allocations.map((allocation) => allocation.serial_number).filter(Boolean).join("\n"), supplier_batch: allocations[0]?.supplier_batch || "", manufactured_at: String(allocations[0]?.manufactured_at || "").slice(0, 10), expiry_at: String(allocations[0]?.expiry_at || "").slice(0, 10) }; }) }); setEditing(true); };
  const act = (action, body = {}) => run(async () => { setSelected(await transitionInventoryReturn(selected.id, action, body)); });

  return <section className={`ops-layout ${can("returns.manage") ? "" : "is-single"}`}>
    {can("returns.manage") && <aside className="ops-panel"><h2>{editing ? `Edit ${selected?.return_number}` : "New controlled return"}</h2><form onSubmit={submit}>
      <Field label="Return type"><select value={form.type} onChange={(event) => setForm({ ...blankForm(), type: event.target.value })}><option value="customer">Customer return</option><option value="supplier">Supplier return</option></select></Field>
      {form.type === "customer" ? <Field label="Customer"><select required value={form.customer_id} onChange={(event) => setForm({ ...form, customer_id: event.target.value, source_id: "" })}><option value="">—</option>{meta.customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select></Field> : <Field label="Supplier"><select required value={form.supplier_id} onChange={(event) => setForm({ ...form, supplier_id: event.target.value, source_id: "" })}><option value="">—</option>{meta.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></Field>}
      <Field label={form.type === "customer" ? "Original daily sale (optional)" : "Original goods receipt (optional)"}><select value={form.source_id} onChange={(event) => setForm({ ...form, source_id: event.target.value, items: [blankItem()] })}><option value="">No source document</option>{sourceRecords.map((record) => <option key={record.id} value={record.id}>{record.sale_number || record.receipt_number} · {String(record.sale_date || record.received_at || "").slice(0, 10)}</option>)}</select></Field>
      <fieldset className="ops-lines"><legend>{label(t, "Return lines", "Artikujt e kthimit")}</legend>{form.items.map((line, index) => { const product = meta.products.find((item) => String(item.id) === String(line.product_id)); const mode = product?.tracking_mode || "none"; const lineLocations = meta.locations.filter((location) => String(location.warehouse_id) === String(line.warehouse_id)); return <div className="ops-return-line" key={index}>
        {source ? <Field label="Source line"><select required value={line.source_item_id} onChange={(event) => { const sourceLine = sourceItems.find((item) => String(item.id) === event.target.value); updateLine(index, { source_item_id: event.target.value, product_id: String(sourceLine?.product_id || ""), quantity: sourceLine?.base_quantity || (+sourceLine?.accepted_base_quantity + +sourceLine?.damaged_base_quantity) || "", warehouse_id: String(source.warehouse_id || line.warehouse_id), location_id: source.location_id ? String(source.location_id) : "" }); }}><option value="">—</option>{sourceItems.map((item) => <option key={item.id} value={item.id}>{item.product?.name} · {item.base_quantity || (+item.accepted_base_quantity + +item.damaged_base_quantity)} {item.product?.unit}</option>)}</select></Field> : <Field label="Product"><select required value={line.product_id} onChange={(event) => updateLine(index, { product_id: event.target.value, lot_number: "", serial_numbers: "", expiry_at: "" })}><option value="">—</option>{meta.products.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></Field>}
        <div className="ops-form-grid"><Field label="Warehouse"><select required value={line.warehouse_id} onChange={(event) => updateLine(index, { warehouse_id: event.target.value, location_id: "" })}><option value="">—</option>{meta.warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></Field><Field label="Bin"><select value={line.location_id} onChange={(event) => updateLine(index, { location_id: event.target.value })}><option value="">Unassigned</option>{lineLocations.map((location) => <option key={location.id} value={location.id}>{location.path || location.name}</option>)}</select></Field><Field label="Condition"><select value={line.condition} onChange={(event) => updateLine(index, { condition: event.target.value })}>{["sellable", "damaged", "quarantine", "scrap"].map((value) => <option key={value}>{value}</option>)}</select></Field></div>
        {mode === "serial" ? <Field label={`Serial numbers (${serials(line).length})`}><textarea required value={line.serial_numbers} onChange={(event) => updateLine(index, { serial_numbers: event.target.value })} placeholder="One serial per line" /></Field> : <Field label="Quantity"><input required type="number" min="0.001" step="0.001" value={line.quantity} onChange={(event) => updateLine(index, { quantity: event.target.value })} /></Field>}
        {["batch", "batch_expiry"].includes(mode) && <div className="ops-form-grid"><Field label="Lot / batch"><input required value={line.lot_number} onChange={(event) => updateLine(index, { lot_number: event.target.value })} /></Field><Field label="Supplier batch"><input value={line.supplier_batch} onChange={(event) => updateLine(index, { supplier_batch: event.target.value })} /></Field><Field label="Manufactured"><input type="date" value={line.manufactured_at} onChange={(event) => updateLine(index, { manufactured_at: event.target.value })} /></Field><Field label="Expiry"><input required={mode === "batch_expiry"} type="date" value={line.expiry_at} onChange={(event) => updateLine(index, { expiry_at: event.target.value })} /></Field></div>}
        <Field label="Line notes"><input value={line.notes} onChange={(event) => updateLine(index, { notes: event.target.value })} /></Field><button type="button" className="danger" disabled={form.items.length === 1} onClick={() => setForm({ ...form, items: form.items.filter((_, itemIndex) => itemIndex !== index) })}>Remove line</button>
      </div>; })}<button type="button" className="secondary" onClick={() => setForm({ ...form, items: [...form.items, blankItem()] })}>+ Add return line</button></fieldset>
      <Field label="Financial resolution"><select value={form.financial_resolution} onChange={(event) => setForm({ ...form, financial_resolution: event.target.value })}>{(form.type === "customer" ? ["none", "cash_refund", "debt_credit"] : ["none", "cash_refund", "supplier_credit", "replacement"]).map((value) => <option key={value}>{value}</option>)}</select></Field>
      {!["none", "replacement"].includes(form.financial_resolution) && <Field label="Amount"><input required type="number" min="0.01" step="0.01" value={form.financial_amount} onChange={(event) => setForm({ ...form, financial_amount: event.target.value })} /></Field>}
      {form.financial_resolution === "cash_refund" && <Field label="Money account"><select required value={form.financial_account_id} onChange={(event) => setForm({ ...form, financial_account_id: event.target.value })}><option value="">—</option>{meta.accounts.map((account) => <option key={account.id} value={account.id}>{account.name}</option>)}</select></Field>}
      <Field label={form.financial_resolution === "replacement" ? "Replacement reference / status note" : "Reference number"}><input value={form.reference_number} onChange={(event) => setForm({ ...form, reference_number: event.target.value })} /></Field><Field label="Reason"><textarea required minLength="3" value={form.reason} onChange={(event) => setForm({ ...form, reason: event.target.value })} /></Field><Field label="Notes"><textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></Field><div className="ops-actions"><button>{editing ? t.save : t.create}</button>{editing && <button type="button" className="secondary" onClick={() => { setEditing(false); setForm(blankForm()); }}>Cancel edit</button>}</div>
    </form></aside>}
    <div className="ops-panel"><h2>{t.returns}</h2><div className="ops-card-list">{data.map((item) => <article className="ops-card" key={item.id}><div><strong>{item.return_number} · {item.type}</strong><span>{item.customer?.name || item.supplier?.name} · {item.status} · {item.items?.length || 0} lines</span></div><button className="secondary" onClick={() => open(item.id)}>View</button></article>)}</div>
      {selected && <section className="ops-detail"><h3>{selected.return_number} · {selected.status}</h3><p>{selected.reason}</p><div className="ops-table"><table><thead><tr><th>Product</th><th>Source</th><th>Destination</th><th>Quantity</th><th>Condition</th><th>Processed</th></tr></thead><tbody>{selected.items.map((item) => <tr key={item.id}><td>{item.product?.name}</td><td>{item.daily_sale_item_id ? `Sale line ${item.daily_sale_item_id}` : item.goods_receipt_item_id ? `Receipt line ${item.goods_receipt_item_id}` : "Manual"}</td><td>{item.warehouse?.name} · {item.location?.path || "Unassigned"}</td><td>{item.quantity} {item.unit}</td><td>{item.condition}</td><td>{item.processed_quantity}</td></tr>)}</tbody></table></div><Field label="Action/cancellation reason"><input value={actionReason} onChange={(event) => setActionReason(event.target.value)} /></Field><div className="ops-actions">{can("returns.manage") && selected.status === "draft" && <><button onClick={edit}>Edit</button><button onClick={() => act("submit")}>Submit</button><button className="danger" onClick={() => run(async () => { await deleteInventoryReturn(selected.id); setSelected(null); })}>Delete draft</button></>}{can("returns.approve") && selected.status === "submitted" && <button onClick={() => act("approve")}>Approve</button>}{can("returns.approve") && selected.status === "approved" && <button onClick={() => act("complete")}>Complete</button>}{can("returns.manage") && !["completed", "cancelled"].includes(selected.status) && <button className="danger" disabled={actionReason.trim().length < 3} onClick={() => act("cancel", { reason: actionReason })}>Cancel return</button>}</div><details className="ops-history"><summary>{label(t, "Audit history", "Historia e auditimit")}</summary>{(selected.events || []).map((event) => <article key={event.id}><strong>{event.action}</strong><span>{event.created_at ? new Date(event.created_at).toLocaleString() : "—"}</span>{event.reason && <p>{event.reason}</p>}</article>)}</details></section>}
    </div>
  </section>;
}

function SupplierMatching({ data, meta, run, t, can }) {
  const blankLine = (vatRate = 0) => ({ product_id: "", purchase_order_item_id: "", description: "", quantity: 1, unit: "pcs", unit_price: 0, vat_rate: vatRate });
  const [form, setForm] = useState({ supplier_id: "", purchase_order_id: "", document_number: "", invoice_date: new Date().toISOString().slice(0, 10), vat_rate: 0, input_vat_eligible: false, lines: [blankLine()] });
  const [order, setOrder] = useState(null);
  const [receiptDetails, setReceiptDetails] = useState([]);
  const [selectedReceiptIds, setSelectedReceiptIds] = useState([]);
  const [selected, setSelected] = useState(null);
  const [editing, setEditing] = useState(null);
  const [approvalReason, setApprovalReason] = useState("");
  const [allocation, setAllocation] = useState({ purchase_order_payment_id: "", amount: "", reason: "", idempotency_key: uuid() });
  const orderReceipts = meta.receipts.filter((receipt) => String(receipt.purchase_order_id) === String(form.purchase_order_id));
  const selectableReceiptItems = receiptDetails.filter((receipt) => selectedReceiptIds.includes(receipt.id)).flatMap((receipt) => (receipt.items || []).map((item) => ({ ...item, receipt })));
  const chooseOrder = async (id) => {
    if (!id) { setOrder(null); setReceiptDetails([]); setSelectedReceiptIds([]); setForm((current) => ({ ...current, purchase_order_id: "", lines: [blankLine(current.vat_rate)] })); return; }
    const detail = await getPurchaseOrder(id);
    const receipts = meta.receipts.filter((receipt) => String(receipt.purchase_order_id) === String(id));
    const details = await Promise.all(receipts.map((receipt) => getGoodsReceipt(receipt.id)));
    setOrder(detail); setReceiptDetails(details); setSelectedReceiptIds(details.map((receipt) => receipt.id));
    setForm((current) => ({ ...current, purchase_order_id: String(id), supplier_id: String(detail.supplier_id), lines: detail.items.map((line) => { const receiptItem = details.flatMap((receipt) => receipt.items || []).find((item) => +item.purchase_order_item_id === +line.id); return { product_id: line.product_id || "", purchase_order_item_id: line.id, goods_receipt_item_id: receiptItem?.id || "", description: line.description, quantity: line.quantity, unit: line.unit, unit_price: line.unit_price, vat_rate: current.vat_rate }; }) }));
  };
  const submit = (e) => {
    e.preventDefault();
    const items = form.lines.map((line) => ({ ...line, product_id: line.product_id ? +line.product_id : null, purchase_order_item_id: line.purchase_order_item_id ? +line.purchase_order_item_id : null, goods_receipt_item_id: line.goods_receipt_item_id ? +line.goods_receipt_item_id : null, quantity: +line.quantity, unit_price: +line.unit_price, vat_rate: +form.vat_rate }));
    const net = +items.reduce((sum, line) => sum + line.quantity * line.unit_price, 0).toFixed(2);
    const vat = +items.reduce((sum, line) => sum + line.quantity * line.unit_price * line.vat_rate / 100, 0).toFixed(2);
    const supplier = meta.suppliers.find((item) => String(item.id) === String(form.supplier_id));
    const payload = { supplier_id: +form.supplier_id, purchase_order_id: form.purchase_order_id ? +form.purchase_order_id : null, goods_receipt_ids: selectedReceiptIds, vendor_name: supplier?.name || "Supplier", document_type: "purchase_invoice", document_number: form.document_number, source_type: "domestic", asset_treatment: "ordinary", category: "inventory", description: "Supplier inventory invoice", business_purpose: null, invoice_date: form.invoice_date, received_date: form.invoice_date, supply_date: form.invoice_date, due_date: null, currency: order?.currency || selected?.currency || "EUR", exchange_rate: order?.exchange_rate || selected?.exchange_rate || 1, net_amount: net, vat_rate: +form.vat_rate, vat_amount: vat, vat_treatment: +form.vat_rate === 18 ? "standard" : +form.vat_rate === 8 ? "reduced" : "non_vat", input_vat_eligible: form.input_vat_eligible, items };
    run(async () => { const saved = editing ? await updateSupplierInvoice(editing, payload) : await createSupplierInvoice(payload); setSelected(saved); setEditing(null); });
  };
  const open = async (id) => { setSelected(await getSupplierInvoice(id)); setEditing(null); setApprovalReason(""); setAllocation({ purchase_order_payment_id: "", amount: "", reason: "", idempotency_key: uuid() }); };
  const edit = async () => { const detail = selected; const receipts = detail.goods_receipts || []; const fullReceipts = await Promise.all(receipts.map((receipt) => getGoodsReceipt(receipt.id))); setReceiptDetails(fullReceipts); setSelectedReceiptIds(fullReceipts.map((receipt) => receipt.id)); setOrder(detail.purchase_order || null); const firstRate = +(detail.supplier_invoice_items?.[0]?.vat_rate ?? detail.vat_rate ?? 0); setForm({ supplier_id: String(detail.supplier_id), purchase_order_id: detail.purchase_order_id ? String(detail.purchase_order_id) : "", document_number: detail.document_number, invoice_date: String(detail.invoice_date).slice(0, 10), vat_rate: firstRate, input_vat_eligible: Boolean(detail.input_vat_eligible), lines: detail.supplier_invoice_items.map((line) => ({ product_id: line.product_id || "", purchase_order_item_id: line.purchase_order_item_id || "", goods_receipt_item_id: line.goods_receipt_item_id || "", description: line.description, quantity: line.quantity, unit: line.unit, unit_price: line.unit_price, vat_rate: line.vat_rate })) }); setEditing(detail.id); };
  const updateLine = (index, values) => setForm((current) => ({ ...current, lines: current.lines.map((line, lineIndex) => lineIndex === index ? { ...line, ...values } : line) }));
  const records = <div className="ops-card-list">{data.map((item) => <article className="ops-card" key={item.id}><div><strong>{item.document_number} · {item.vendor_name}</strong><span>{item.purchase_order?.po_number || "No PO"} · {item.match_status} · {item.gross_amount} {item.currency}</span></div><button className="secondary" onClick={() => open(item.id)}>Review match</button></article>)}</div>;
  const summaryLines = selected?.supplier_invoice_items || [];
  const availableForPayment = (payment) => Math.max(0, Number(payment.amount || 0) - (payment.allocations || []).reduce((sum, row) => sum + Number(row.amount || 0), 0));
  const paymentOptions = (selected?.purchase_order?.payments || []).filter((payment) => payment.status !== "reversed").map((payment) => ({ ...payment, available: availableForPayment(payment) })).filter((payment) => payment.available > 0.001);
  const allocationPayment = paymentOptions.find((payment) => String(payment.id) === String(allocation.purchase_order_payment_id));
  const allocationMaximum = Math.min(Number(allocationPayment?.available || 0), Number(selected?.remaining_amount || 0));
  const submitAllocation = (event) => {
    event.preventDefault();
    run(async () => {
      const detail = await allocateSupplierInvoicePayment(selected.id, {
        purchase_order_payment_id: Number(allocation.purchase_order_payment_id),
        amount: Number(allocation.amount),
        reason: allocation.reason.trim() || null,
        idempotency_key: allocation.idempotency_key,
      });
      setSelected(detail);
      setAllocation({ purchase_order_payment_id: "", amount: "", reason: "", idempotency_key: uuid() });
    });
  };
  return <section className={`ops-layout ${can("supplier_invoices.manage") ? "" : "is-single"}`}>{can("supplier_invoices.manage") && <aside className="ops-panel"><h2>{editing ? `Edit ${selected?.document_number}` : "New supplier invoice"}</h2><form onSubmit={submit}><Field label="Supplier"><select required value={form.supplier_id} onChange={(e) => setForm({ ...form, supplier_id: e.target.value })}><option value="">—</option>{meta.suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></Field><Field label="Purchase Order (optional)"><select value={form.purchase_order_id} onChange={(e) => chooseOrder(e.target.value)}><option value="">No PO</option>{meta.orders.map((po) => <option key={po.id} value={po.id}>{po.po_number}</option>)}</select></Field>{orderReceipts.length > 0 && <fieldset className="ops-check-group"><legend>Goods receipts included in this match</legend>{orderReceipts.map((receipt) => <label key={receipt.id}><input type="checkbox" checked={selectedReceiptIds.includes(receipt.id)} onChange={(event) => setSelectedReceiptIds(event.target.checked ? [...selectedReceiptIds, receipt.id] : selectedReceiptIds.filter((id) => id !== receipt.id))} /> {receipt.receipt_number} · {String(receipt.received_at).slice(0, 10)}</label>)}</fieldset>}<div className="ops-form-grid"><Field label="Document number"><input required value={form.document_number} onChange={(e) => setForm({ ...form, document_number: e.target.value })} /></Field><Field label="Invoice date"><input type="date" required value={form.invoice_date} onChange={(e) => setForm({ ...form, invoice_date: e.target.value })} /></Field></div><div className="ops-form-grid"><Field label={label(t, "VAT rate", "Norma e TVSH-së")}><select value={form.vat_rate} onChange={(e) => { const rate = +e.target.value; setForm({ ...form, vat_rate: rate, input_vat_eligible: rate > 0 ? form.input_vat_eligible : false, lines: form.lines.map((line) => ({ ...line, vat_rate: rate })) }); }}><option value="0">0% · No VAT</option><option value="8">8% · Reduced</option><option value="18">18% · Standard</option></select></Field><label className="ops-check"><input type="checkbox" disabled={!+form.vat_rate} checked={form.input_vat_eligible} onChange={(e) => setForm({ ...form, input_vat_eligible: e.target.checked })} /> VAT deductible</label></div><fieldset className="ops-lines"><legend>Invoice lines</legend>{form.lines.map((line, index) => <div className="ops-invoice-line" key={index}><Field label="Product"><select required value={line.product_id} onChange={(e) => { const product = meta.products.find((item) => String(item.id) === e.target.value); updateLine(index, { product_id: e.target.value, description: product?.name || line.description, unit: product?.unit || line.unit }); }}><option value="">—</option>{meta.products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select></Field>{line.purchase_order_item_id && <Field label="PO line"><select value={line.purchase_order_item_id} onChange={(e) => updateLine(index, { purchase_order_item_id: e.target.value, goods_receipt_item_id: "" })}><option value="">—</option>{(order?.items || []).map((item) => <option key={item.id} value={item.id}>{item.description} · ordered {item.quantity}</option>)}</select></Field>}<Field label="Receipt line"><select value={line.goods_receipt_item_id || ""} onChange={(e) => updateLine(index, { goods_receipt_item_id: e.target.value })}><option value="">No receipt line</option>{selectableReceiptItems.filter((item) => !line.purchase_order_item_id || +item.purchase_order_item_id === +line.purchase_order_item_id).map((item) => <option key={item.id} value={item.id}>{item.receipt.receipt_number} · {item.product?.name} · received {+item.accepted_quantity + +item.damaged_quantity}</option>)}</select></Field><div className="ops-form-grid"><Field label="Invoiced quantity"><input type="number" min="0.001" step="0.001" value={line.quantity} onChange={(e) => updateLine(index, { quantity: e.target.value })} /></Field><Field label="Invoice unit price"><input type="number" min="0" step="0.0001" value={line.unit_price} onChange={(e) => updateLine(index, { unit_price: e.target.value })} /></Field></div><button type="button" className="danger" disabled={form.lines.length === 1} onClick={() => setForm({ ...form, lines: form.lines.filter((_, lineIndex) => lineIndex !== index) })}>Remove</button></div>)}<button type="button" className="secondary" onClick={() => setForm({ ...form, lines: [...form.lines, blankLine(form.vat_rate)] })}>+ Add line</button></fieldset><div className="ops-actions"><button>{editing ? t.save : t.create}</button>{editing && <button type="button" className="secondary" onClick={() => setEditing(null)}>Cancel edit</button>}</div></form></aside>}<div className="ops-panel"><h2>{t.matching}</h2>{records}{selected && <section className="ops-detail"><h3>{selected.document_number} · {selected.match_status}</h3><p>PO {selected.purchase_order?.po_number || "—"} · Invoice {selected.gross_amount} {selected.currency}</p>{selected.purchase_order_id && can("supplier_invoices.manage") && <section className="ops-payment-allocation"><h4>{t.allocatePayment}</h4>{paymentOptions.length ? <form onSubmit={submitAllocation}><Field label={t.payment}><select required value={allocation.purchase_order_payment_id} onChange={(event) => { const payment = paymentOptions.find((row) => String(row.id) === event.target.value); const suggested = Math.min(Number(payment?.available || 0), Number(selected.remaining_amount || 0)); setAllocation({ ...allocation, purchase_order_payment_id: event.target.value, amount: suggested > 0 ? suggested.toFixed(2) : "" }); }}><option value="">—</option>{paymentOptions.map((payment) => <option key={payment.id} value={payment.id}>{String(payment.payment_date || "").slice(0, 10)} · {payment.reference_number || `#${payment.id}`} · {payment.available.toFixed(2)} {payment.currency || selected.currency} {t.availablePayment}</option>)}</select></Field><div className="ops-form-grid"><Field label={t.paymentAmount}><input required type="number" min="0.01" max={allocationMaximum || undefined} step="0.01" value={allocation.amount} onChange={(event) => setAllocation({ ...allocation, amount: event.target.value })} /></Field><Field label={t.allocationReason}><input value={allocation.reason} onChange={(event) => setAllocation({ ...allocation, reason: event.target.value })} /></Field></div><button disabled={!allocation.purchase_order_payment_id || !Number(allocation.amount) || Number(allocation.amount) > allocationMaximum + .001}>{t.allocate}</button></form> : <p className="ops-empty">{t.noAdvance}</p>}{(selected.purchase_order_payment_allocations || []).length > 0 && <div className="ops-allocation-history"><strong>{t.existingAllocations}</strong>{selected.purchase_order_payment_allocations.map((row) => <span key={row.id}>{String(row.allocated_at || row.created_at || "").slice(0, 10)} · {row.payment?.reference_number || `#${row.purchase_order_payment_id}`} · {row.amount} {selected.currency}</span>)}</div>}</section>}<div className="ops-table ops-wide-table"><table><thead><tr><th>Line</th><th>Ordered</th><th>Received</th><th>Previously invoiced</th><th>This invoice</th><th>Quantity variance</th><th>Price variance</th><th>Tax variance</th><th>Issues</th></tr></thead><tbody>{summaryLines.map((line) => { const variance = line.variance || {}; return <tr key={line.id}><td>{line.product?.name || line.description}</td><td>{variance.ordered ?? "—"}</td><td>{variance.received ?? "—"}</td><td>{variance.otherInvoiced ?? "—"}</td><td>{variance.invoiced ?? line.quantity}</td><td>{variance.quantityVariance ?? "—"}</td><td>{variance.priceVariance ?? "—"}</td><td>{variance.taxVariance ?? "—"}</td><td>{(variance.issues || []).join(", ") || "Matched"}</td></tr>; })}</tbody></table></div><p className="ops-summary">Tolerances: quantity {selected.match_summary?.tolerances?.quantity ?? "—"}, unit price {selected.match_summary?.tolerances?.unit_price ?? "—"}, tax {selected.match_summary?.tolerances?.tax ?? "—"}</p><Field label="Exception approval reason"><textarea value={approvalReason} onChange={(e) => setApprovalReason(e.target.value)} placeholder="Required when approving an exception or unmatched invoice" /></Field><div className="ops-actions">{can("supplier_invoices.manage") && selected.status === "draft" && !selected.approved_at && <button onClick={edit}>Edit draft</button>}{can("supplier_invoices.manage") && <button className="secondary" onClick={() => run(async () => setSelected(await recalculateSupplierInvoice(selected.id)))}>Recalculate</button>}{can("supplier_invoices.approve") && selected.match_status !== "approved" && <button disabled={["exception", "unmatched"].includes(selected.match_status) && approvalReason.trim().length < 3} onClick={() => run(async () => setSelected(await approveSupplierInvoice(selected.id, approvalReason || null)))}>Approve</button>}</div><details className="ops-history"><summary>Matching audit history</summary>{(selected.match_events || []).map((event) => <article key={event.id}><strong>{event.action}</strong><span>{event.created_at ? new Date(event.created_at).toLocaleString() : "—"}</span>{event.reason && <p>{event.reason}</p>}</article>)}</details></section>}</div></section>;
}
