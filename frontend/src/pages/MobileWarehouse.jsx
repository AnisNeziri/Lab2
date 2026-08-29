import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  ArrowRight, Boxes, Camera, CheckCircle2, ClipboardCheck, LoaderCircle,
  MapPin, PackageCheck, PackageSearch, ScanLine, Search, Truck, Wifi, WifiOff,
} from 'lucide-react'
import BarcodeScanner from '../components/BarcodeScanner'
import {
  countWarehouseProduct,
  getMobileWarehouseBootstrap,
  lookupWarehouseProduct,
  moveWarehouseProduct,
  pickWarehouseProduct,
  receiveWarehouseProduct,
} from '../api/mobileWarehouse'
import { useSettingsStore } from '../store/settingsStore'
import { formatQuantity } from '../utils/formatQuantity'
import './MobileWarehouse.css'

const copy = {
  en: {
    eyebrow: 'Warehouse mobile', title: 'Scan. Confirm. Done.',
    subtitle: 'Fast floor operations with live stock validation.', online: 'Connected', offline: 'Offline',
    scanPlaceholder: 'Scan barcode or enter SKU', find: 'Find', scan: 'Camera',
    receive: 'Receive', move: 'Move', count: 'Count', pick: 'Pick', lookup: 'Lookup',
    noProduct: 'Scan a product to start this operation.', noMatch: 'No exact product found.', suggestions: 'Possible matches',
    stock: 'Company stock', available: 'Available', reserved: 'Reserved', incoming: 'Incoming PO', warehouse: 'Warehouse', location: 'Location', quantity: 'Quantity',
    purchaseLine: 'Purchase order line', accepted: 'Accepted', damaged: 'Damaged', rejected: 'Rejected',
    supplierDoc: 'Supplier document', reason: 'Reason', note: 'Note', source: 'Source bin', destination: 'Destination bin',
    countSession: 'Count session', countItem: 'Count line', reference: 'Reference', lot: 'Lot', serial: 'Serial number', expiry: 'Expiry date',
    submitReceive: 'Post receipt', submitMove: 'Move stock', submitCount: 'Save count', submitPick: 'Confirm pick',
    received: 'Receipt posted and inventory updated.', moved: 'Stock moved to the destination bin.', counted: 'Count recorded.', picked: 'Pick recorded. The transfer dispatches when every requested line is picked.',
    loading: 'Loading warehouse workspace…', retry: 'Try again', balances: 'Stock by location', openOrders: 'Open receipt lines', pickRequest: 'Transfer pick request', scanLocation: 'Scan bin', traceStock: 'Lot / serial / expiry stock',
    trackedHint: 'This product is tracked. Select or enter its trace identity.', required: 'Complete the required fields.',
  },
  sq: {
    eyebrow: 'Magazina mobile', title: 'Skano. Konfirmo. U krye.',
    subtitle: 'Veprime të shpejta në magazinë me kontroll të stokut në kohë reale.', online: 'Lidhur', offline: 'Pa lidhje',
    scanPlaceholder: 'Skano barkodin ose shkruaj SKU-në', find: 'Kërko', scan: 'Kamera',
    receive: 'Prano', move: 'Lëviz', count: 'Numëro', pick: 'Nxirr', lookup: 'Kërko',
    noProduct: 'Skano një produkt për ta filluar këtë veprim.', noMatch: 'Nuk u gjet produkt i saktë.', suggestions: 'Rezultate të mundshme',
    stock: 'Stoku i kompanisë', available: 'Në dispozicion', reserved: 'Rezervuar', incoming: 'Porosi në ardhje', warehouse: 'Magazina', location: 'Lokacioni', quantity: 'Sasia',
    purchaseLine: 'Rreshti i porosisë', accepted: 'Pranuar', damaged: 'Dëmtuar', rejected: 'Refuzuar',
    supplierDoc: 'Dokumenti i furnizuesit', reason: 'Arsyeja', note: 'Shënim', source: 'Lokacioni burim', destination: 'Lokacioni destinacion',
    countSession: 'Sesioni i numërimit', countItem: 'Rreshti i numërimit', reference: 'Referenca', lot: 'Loti', serial: 'Numri serik', expiry: 'Data e skadimit',
    submitReceive: 'Regjistro pranimin', submitMove: 'Lëviz stokun', submitCount: 'Ruaj numërimin', submitPick: 'Konfirmo nxjerrjen',
    received: 'Pranimi u regjistrua dhe inventari u përditësua.', moved: 'Stoku u lëviz në lokacionin e ri.', counted: 'Numërimi u regjistrua.', picked: 'Nxjerrja u regjistrua. Transferi niset pasi të nxirren të gjithë artikujt.',
    loading: 'Duke ngarkuar magazinën…', retry: 'Provo përsëri', balances: 'Stoku sipas lokacionit', openOrders: 'Rreshtat e hapur për pranim', pickRequest: 'Kërkesa e transferit për nxjerrje', scanLocation: 'Skano lokacionin', traceStock: 'Stoku sipas lotit / serisë / skadimit',
    trackedHint: 'Ky produkt gjurmohet. Zgjidh ose shkruaj identitetin e tij.', required: 'Plotëso fushat e detyrueshme.',
  },
}

const actionIcons = { receive: PackageCheck, move: ArrowRight, count: ClipboardCheck, pick: Truck, lookup: PackageSearch }
const requestKey = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
const errorText = (error) => error?.errors ? Object.values(error.errors).flat().join(' ') : (error?.message || 'Operation failed.')

function Field({ label, children, hint }) {
  return <label className="mobile-warehouse-field"><span>{label}</span>{children}{hint && <small>{hint}</small>}</label>
}

export default function MobileWarehouse() {
  const language = useSettingsStore((state) => state.language)
  const text = copy[language] || copy.en
  const searchRef = useRef(null)
  const [online, setOnline] = useState(navigator.onLine)
  const [workspace, setWorkspace] = useState(null)
  const [action, setAction] = useState('lookup')
  const [code, setCode] = useState('')
  const [result, setResult] = useState(null)
  const [scannerOpen, setScannerOpen] = useState(false)
  const [scanTarget, setScanTarget] = useState('product')
  const [loading, setLoading] = useState(true)
  const [finding, setFinding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [form, setForm] = useState({})

  const product = result?.product || null
  const locations = useMemo(() => (workspace?.warehouses || []).flatMap((warehouse) =>
    (warehouse.locations || []).map((location) => ({ ...location, warehouse }))), [workspace])
  const locationById = useMemo(() => new Map(locations.map((location) => [String(location.id), location])), [locations])
  const availableBalances = useMemo(() => (result?.balances || []).filter((row) => Number(row.available_quantity || 0) > 0), [result])
  const movableBalances = useMemo(() => availableBalances.filter((row) => row.location_id), [availableBalances])
  const selectedSource = availableBalances.find((row) => String(row.location_id || `w-${row.warehouse_id}`) === String(form.source_key))
  const destinationLocations = selectedSource
    ? locations.filter((row) => String(row.warehouse_id) === String(selectedSource.warehouse_id) && String(row.id) !== String(selectedSource.location_id))
    : []
  const reservedTotal = useMemo(() => (result?.balances || []).reduce((sum, row) => sum + Number(row.reserved_quantity || 0), 0), [result])
  const incomingTotal = useMemo(() => (result?.receivable_lines || []).reduce((sum, row) => sum + Number(row.remaining_quantity || 0), 0), [result])

  const loadWorkspace = useCallback(async () => {
    setLoading(true)
    try {
      setWorkspace(await getMobileWarehouseBootstrap())
      setError('')
    } catch (err) {
      setError(errorText(err))
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void loadWorkspace() }, [loadWorkspace])
  useEffect(() => {
    const connected = () => setOnline(true)
    const disconnected = () => setOnline(false)
    window.addEventListener('online', connected)
    window.addEventListener('offline', disconnected)
    return () => { window.removeEventListener('online', connected); window.removeEventListener('offline', disconnected) }
  }, [])

  const initializeForm = useCallback((lookup) => {
    const firstBalance = (lookup.balances || []).find((row) => Number(row.available_quantity || 0) > 0)
    const firstMove = (lookup.balances || []).find((row) => row.location_id && Number(row.available_quantity || 0) > 0)
    const firstReceipt = lookup.receivable_lines?.[0]
    const firstCount = lookup.count_items?.[0]
    const firstPick = lookup.pick_lines?.[0]
    setForm({
      receive_line: firstReceipt ? `${firstReceipt.purchase_order_id}:${firstReceipt.purchase_order_item_id}` : '',
      warehouse_id: firstReceipt?.warehouse_id || firstBalance?.warehouse_id || '',
      location_id: '', accepted_quantity: '', damaged_quantity: '', rejected_quantity: '', supplier_document_number: '',
      source_key: firstMove ? String(firstMove.location_id) : '', destination_location_id: '', quantity: '',
      inventory_count_id: firstCount?.inventory_count_session_id || '', count_item_id: firstCount?.id || '', counted_quantity: '',
      pick_line_id: firstPick?.stock_transfer_item_id || '',
      reason: '', notes: '', reference: '', inventory_lot_id: '', inventory_lot_ids: [], lot_number: '', serial_number: '', accepted_serial_numbers: '', damaged_serial_numbers: '', expiry_at: '',
    })
  }, [])

  const lookup = useCallback(async (rawCode = code) => {
    const value = String(rawCode || '').trim()
    if (!value || finding) return
    setFinding(true); setError(''); setSuccess('')
    try {
      const lookupResult = await lookupWarehouseProduct(value)
      setResult(lookupResult)
      if (lookupResult.product) initializeForm(lookupResult)
      setCode(value)
    } catch (err) {
      setResult(null); setError(errorText(err))
    } finally {
      setFinding(false)
    }
  }, [code, finding, initializeForm])

  function scanned(value) {
    setScannerOpen(false)
    if (scanTarget !== 'product') {
      const normalized = String(value || '').trim().toLowerCase()
      const location = locations.find((row) => [row.code, row.path, row.name].some((candidate) => String(candidate || '').trim().toLowerCase() === normalized))
      if (!location) { setError(`${text.location}: ${text.noMatch}`); return }
      if (scanTarget === 'receive_location') setForm((current) => ({ ...current, warehouse_id: String(location.warehouse_id), location_id: String(location.id) }))
      if (scanTarget === 'source_location' || scanTarget === 'pick_location') setForm((current) => ({ ...current, source_key: String(location.id), destination_location_id: '' }))
      if (scanTarget === 'destination_location') setForm((current) => ({ ...current, destination_location_id: String(location.id) }))
      setError('')
      return
    }
    setCode(value)
    void lookup(value)
  }

  function openScanner(target = 'product') {
    setScanTarget(target)
    setScannerOpen(true)
  }

  function chooseMatch(match) {
    setCode(match.barcode || match.sku || match.name)
    void lookup(match.barcode || match.sku || match.name)
  }

  function traceAllocations(quantity, stockState = undefined) {
    if (!product || (product.tracking_mode || 'none') === 'none' || !Number(quantity)) return []
    if (product.tracking_mode === 'serial') {
      if (stockState) {
        const field = stockState === 'damaged' ? form.damaged_serial_numbers : form.accepted_serial_numbers
        return [...new Set(String(field || '').split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean))]
          .map((serial_number) => ({ serial_number, stock_state: stockState, quantity: 1 }))
      }
      return (form.inventory_lot_ids || []).map((inventory_lot_id) => ({ inventory_lot_id: Number(inventory_lot_id), quantity: 1 }))
    }
    const existingLot = (result?.lots || []).find((lot) => String(lot.id) === String(form.inventory_lot_id))
    return [{
      ...(existingLot ? { inventory_lot_id: existingLot.id } : {}),
      ...(!existingLot && form.lot_number ? { lot_number: form.lot_number } : {}),
      ...(!existingLot && form.serial_number ? { serial_number: form.serial_number } : {}),
      ...(!existingLot && form.expiry_at ? { expiry_at: form.expiry_at } : {}),
      ...(stockState ? { stock_state: stockState } : {}),
      quantity: Number(quantity),
    }]
  }

  async function runOperation(handler, message) {
    if (busy || !product) return
    setBusy(true); setError(''); setSuccess('')
    try {
      await handler()
      setSuccess(message)
      navigator.vibrate?.(80)
      await Promise.all([loadWorkspace(), lookup(code)])
      window.dispatchEvent(new Event('stock-refresh'))
    } catch (err) {
      setError(errorText(err))
      navigator.vibrate?.([70, 40, 70])
    } finally {
      setBusy(false)
    }
  }

  function submitReceive(event) {
    event.preventDefault()
    const [purchase_order_id, purchase_order_item_id] = String(form.receive_line || '').split(':').map(Number)
    const trace = [
      ...traceAllocations(form.accepted_quantity, 'available'),
      ...traceAllocations(form.damaged_quantity, 'damaged'),
    ]
    void runOperation(() => receiveWarehouseProduct({
      purchase_order_id, purchase_order_item_id, warehouse_id: Number(form.warehouse_id),
      location_id: form.location_id ? Number(form.location_id) : null,
      accepted_quantity: Number(form.accepted_quantity || 0), damaged_quantity: Number(form.damaged_quantity || 0),
      rejected_quantity: Number(form.rejected_quantity || 0), supplier_document_number: form.supplier_document_number || null,
      reason: form.reason || 'Mobile warehouse receipt', notes: form.notes || null,
      idempotency_key: requestKey(), trace_allocations: trace,
    }), text.received)
  }

  function submitMove(event) {
    event.preventDefault()
    void runOperation(() => moveWarehouseProduct({
      product_id: product.id, source_location_id: Number(selectedSource?.location_id),
      destination_location_id: Number(form.destination_location_id), quantity: Number(form.quantity),
      stock_state: 'available', reason: form.reason || 'Mobile bin move', idempotency_key: requestKey(),
      trace_allocations: traceAllocations(form.quantity),
    }), text.moved)
  }

  function submitCount(event) {
    event.preventDefault()
    void runOperation(() => countWarehouseProduct({
      inventory_count_id: Number(form.inventory_count_id), count_item_id: form.count_item_id ? Number(form.count_item_id) : null,
      product_id: product.id, location_id: form.location_id ? Number(form.location_id) : null,
      counted_quantity: Number(form.counted_quantity), stock_state: 'available', notes: form.notes || null,
    }), text.counted)
  }

  function submitPick(event) {
    event.preventDefault()
    const source = availableBalances.find((row) => String(row.location_id || `w-${row.warehouse_id}`) === String(form.source_key))
    const pickLine = (result.pick_lines || []).find((row) => String(row.stock_transfer_item_id) === String(form.pick_line_id))
    void runOperation(() => pickWarehouseProduct({
      stock_transfer_item_id: Number(pickLine?.stock_transfer_item_id),
      product_id: product.id, warehouse_id: Number(source?.warehouse_id), location_id: source?.location_id || null,
      quantity: Number(form.quantity), reason: form.reason || `Picked for ${pickLine?.transfer_number || 'stock transfer'}`,
      idempotency_key: requestKey(), trace_allocations: traceAllocations(form.quantity),
    }), text.picked)
  }

  if (loading && !workspace) return <div className="mobile-warehouse-loading"><LoaderCircle className="spin" />{text.loading}</div>

  return (
    <main className="mobile-warehouse-page">
      <header className="mobile-warehouse-hero">
        <div><span>{text.eyebrow}</span><h1>{text.title}</h1><p>{text.subtitle}</p></div>
        <div className={`mobile-warehouse-network ${online ? 'online' : 'offline'}`}>
          {online ? <Wifi size={16} /> : <WifiOff size={16} />}{online ? text.online : text.offline}
        </div>
      </header>

      <section className="mobile-warehouse-scanbar">
        <ScanLine size={23} />
        <input ref={searchRef} value={code} onChange={(event) => setCode(event.target.value)}
          onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); void lookup() } }}
          placeholder={text.scanPlaceholder} autoComplete="off" inputMode="search" />
        <button type="button" onClick={() => void lookup()} disabled={finding}>{finding ? <LoaderCircle className="spin" /> : <Search /> }<span>{text.find}</span></button>
        <button type="button" className="camera" onClick={() => openScanner('product')}><Camera /><span>{text.scan}</span></button>
      </section>

      <nav className="mobile-warehouse-actions" aria-label="Warehouse actions">
        {['receive', 'move', 'count', 'pick', 'lookup'].map((item) => {
          const Icon = actionIcons[item]
          return <button type="button" key={item} className={action === item ? 'active' : ''} onClick={() => { setAction(item); setError(''); setSuccess('') }}><Icon /><span>{text[item]}</span></button>
        })}
      </nav>

      {error && <div className="mobile-warehouse-alert error" role="alert">{error}</div>}
      {success && <div className="mobile-warehouse-alert success" role="status"><CheckCircle2 />{success}</div>}

      {!product && result?.matches?.length > 0 && <section className="mobile-warehouse-panel"><h2>{text.suggestions}</h2><div className="mobile-warehouse-matches">{result.matches.map((match) => <button type="button" key={match.id} onClick={() => chooseMatch(match)}><Boxes /><span><strong>{match.name}</strong><small>{match.sku || match.barcode} · {formatQuantity(match.quantity, match.unit)} {match.unit}</small></span></button>)}</div></section>}
      {!product && <section className="mobile-warehouse-empty"><PackageSearch /><h2>{result ? text.noMatch : text.noProduct}</h2></section>}

      {product && <>
        <section className="mobile-warehouse-product">
          <div className="product-picture">{product.image_url ? <img src={product.image_url} alt="" /> : <Boxes />}</div>
          <div><span>{product.sku || product.barcode}</span><h2>{product.name}</h2><p>{product.category?.name || '—'} · {product.unit}</p></div>
          <div className="product-total"><span>{text.stock}</span><strong>{formatQuantity(product.quantity, product.unit)}</strong><small>{product.unit}</small></div>
        </section>

        {action === 'receive' && <OperationForm onSubmit={submitReceive} submit={text.submitReceive} busy={busy}>
          <Field label={text.purchaseLine}><select required value={form.receive_line || ''} onChange={(e) => { const line = result.receivable_lines.find((row) => `${row.purchase_order_id}:${row.purchase_order_item_id}` === e.target.value); setForm({ ...form, receive_line: e.target.value, warehouse_id: line?.warehouse_id || form.warehouse_id }) }}><option value="">—</option>{result.receivable_lines.map((line) => <option key={`${line.purchase_order_id}:${line.purchase_order_item_id}`} value={`${line.purchase_order_id}:${line.purchase_order_item_id}`}>{line.po_number} · {line.supplier} · {formatQuantity(line.remaining_quantity, line.ordered_unit)} {line.ordered_unit}</option>)}</select></Field>
          <WarehouseLocationFields text={text} form={form} setForm={setForm} warehouses={workspace.warehouses} locations={locations} onScan={() => openScanner('receive_location')} />
          <div className="mobile-warehouse-grid three"><Field label={text.accepted}><input required type="number" min="0" step="any" value={form.accepted_quantity || ''} onChange={(e) => setForm({ ...form, accepted_quantity: e.target.value })} /></Field><Field label={text.damaged}><input type="number" min="0" step="any" value={form.damaged_quantity || ''} onChange={(e) => setForm({ ...form, damaged_quantity: e.target.value })} /></Field><Field label={text.rejected}><input type="number" min="0" step="any" value={form.rejected_quantity || ''} onChange={(e) => setForm({ ...form, rejected_quantity: e.target.value })} /></Field></div>
          <TraceFields text={text} product={product} result={result} form={form} setForm={setForm} receive />
          <Field label={text.supplierDoc}><input value={form.supplier_document_number || ''} onChange={(e) => setForm({ ...form, supplier_document_number: e.target.value })} /></Field>
          <Field label={text.note}><textarea rows="2" value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
        </OperationForm>}

        {action === 'move' && <OperationForm onSubmit={submitMove} submit={text.submitMove} busy={busy}>
          <Field label={text.source}><div className="mobile-location-control"><select required value={form.source_key || ''} onChange={(e) => setForm({ ...form, source_key: e.target.value, destination_location_id: '' })}><option value="">—</option>{movableBalances.map((row) => <option key={row.id} value={row.location_id}>{row.warehouse?.name} · {row.location?.path} · {formatQuantity(row.available_quantity, product.unit)} {product.unit}</option>)}</select><button type="button" onClick={() => openScanner('source_location')}><ScanLine /><span>{text.scanLocation}</span></button></div></Field>
          <Field label={text.destination}><div className="mobile-location-control"><select required value={form.destination_location_id || ''} onChange={(e) => setForm({ ...form, destination_location_id: e.target.value })}><option value="">—</option>{destinationLocations.map((row) => <option key={row.id} value={row.id}>{row.path}</option>)}</select><button type="button" onClick={() => openScanner('destination_location')}><ScanLine /><span>{text.scanLocation}</span></button></div></Field>
          <Field label={text.quantity}><input required type="number" min="0.001" step="any" value={form.quantity || ''} onChange={(e) => setForm({ ...form, quantity: e.target.value })} /></Field>
          <TraceFields text={text} product={product} result={result} form={form} setForm={setForm} />
          <Field label={text.reason}><input required value={form.reason || ''} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
        </OperationForm>}

        {action === 'count' && <OperationForm onSubmit={submitCount} submit={text.submitCount} busy={busy}>
          <Field label={text.countItem}><select required value={form.count_item_id || ''} onChange={(e) => { const item = result.count_items.find((row) => String(row.id) === e.target.value); setForm({ ...form, count_item_id: e.target.value, inventory_count_id: item?.inventory_count_session_id || '', location_id: item?.location_id || '' }) }}><option value="">—</option>{result.count_items.map((item) => <option key={item.id} value={item.id}>{item.session?.count_number} · {item.location?.path || item.session?.warehouse?.name} · {item.stock_state}{item.lot?.lot_number ? ` · ${item.lot.lot_number}` : ''}</option>)}</select></Field>
          <Field label={text.quantity}><input required type="number" min="0" step="any" value={form.counted_quantity || ''} onChange={(e) => setForm({ ...form, counted_quantity: e.target.value })} /></Field>
          <Field label={text.note}><textarea rows="2" value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} /></Field>
        </OperationForm>}

        {action === 'pick' && <OperationForm onSubmit={submitPick} submit={text.submitPick} busy={busy}>
          <Field label={text.pickRequest}><select required value={form.pick_line_id || ''} onChange={(e) => { const line = (result.pick_lines || []).find((row) => String(row.stock_transfer_item_id) === e.target.value); const matchingBalance = availableBalances.find((row) => String(row.warehouse_id) === String(line?.source_warehouse_id) && (!line?.source_location_id || String(row.location_id) === String(line.source_location_id))); setForm({ ...form, pick_line_id: e.target.value, source_key: matchingBalance?.location_id ? String(matchingBalance.location_id) : '', quantity: line?.remaining_quantity || '' }) }}><option value="">—</option>{(result.pick_lines || []).map((line) => <option key={line.stock_transfer_item_id} value={line.stock_transfer_item_id}>{line.transfer_number} · {line.source_warehouse} → {line.destination_warehouse} · {formatQuantity(line.remaining_quantity, product.unit)} {product.unit}</option>)}</select></Field>
          <Field label={text.source}><div className="mobile-location-control"><select required value={form.source_key || ''} onChange={(e) => setForm({ ...form, source_key: e.target.value })}><option value="">—</option>{availableBalances.filter((row) => row.location_id && (!(result.pick_lines || []).find((line) => String(line.stock_transfer_item_id) === String(form.pick_line_id))?.source_warehouse_id || String(row.warehouse_id) === String((result.pick_lines || []).find((line) => String(line.stock_transfer_item_id) === String(form.pick_line_id))?.source_warehouse_id))).map((row) => <option key={row.id} value={row.location_id}>{row.warehouse?.name} · {row.location?.path} · {formatQuantity(row.available_quantity, product.unit)} {product.unit}</option>)}</select><button type="button" onClick={() => openScanner('pick_location')}><ScanLine /><span>{text.scanLocation}</span></button></div></Field>
          <Field label={text.quantity}><input required type="number" min="0.001" step="any" value={form.quantity || ''} onChange={(e) => setForm({ ...form, quantity: e.target.value })} /></Field>
          <TraceFields text={text} product={product} result={result} form={form} setForm={setForm} />
          <Field label={text.reason}><input required value={form.reason || ''} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
        </OperationForm>}

        {action === 'lookup' && <section className="mobile-warehouse-panel"><div className="mobile-lookup-summary"><article><span>{text.available}</span><strong>{formatQuantity(availableBalances.reduce((sum, row) => sum + Number(row.available_quantity || 0), 0), product.unit)}</strong></article><article><span>{text.reserved}</span><strong>{formatQuantity(reservedTotal, product.unit)}</strong></article><article><span>{text.incoming}</span><strong>{formatQuantity(incomingTotal, product.unit)}</strong></article></div><h2>{text.balances}</h2><div className="mobile-warehouse-balances">{(result.balances || []).map((row) => <article key={row.id}><MapPin /><div><strong>{row.warehouse?.name}</strong><span>{row.location?.path || 'Unassigned'}</span></div><b>{formatQuantity(row.available_quantity, product.unit)} {product.unit}<small>{text.reserved}: {formatQuantity(row.reserved_quantity, product.unit)} · Total: {formatQuantity(row.quantity, product.unit)}</small></b></article>)}</div>{(result.lots || []).length > 0 && <><h2>{text.traceStock}</h2><div className="mobile-trace-list">{result.lots.map((lot) => <article key={lot.id}><div><strong>{lot.serial_number || lot.lot_number}</strong><span>{lot.expiry_at ? `${text.expiry}: ${String(lot.expiry_at).slice(0, 10)} · ${lot.expiry_status}` : lot.supplier_batch || '—'}</span></div><b>{formatQuantity(lot.quantity_remaining, product.unit)} {product.unit}</b><small>{(lot.balances || []).map((balance) => `${balance.warehouse?.name} / ${balance.location?.path || 'Unassigned'}: ${formatQuantity(balance.quantity, product.unit)}`).join(' · ')}</small></article>)}</div></>}</section>}
      </>}

      {scannerOpen && <BarcodeScanner onScanSuccess={scanned} onClose={() => setScannerOpen(false)} />}
    </main>
  )
}

function OperationForm({ onSubmit, submit, busy, children }) {
  return <form className="mobile-warehouse-panel mobile-warehouse-form" onSubmit={onSubmit}>{children}<button className="mobile-warehouse-submit" type="submit" disabled={busy}>{busy ? <LoaderCircle className="spin" /> : <CheckCircle2 />}{submit}</button></form>
}

function WarehouseLocationFields({ text, form, setForm, warehouses, locations, onScan }) {
  const options = locations.filter((location) => String(location.warehouse_id) === String(form.warehouse_id))
  return <div className="mobile-warehouse-grid"><Field label={text.warehouse}><select required value={form.warehouse_id || ''} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value, location_id: '' })}><option value="">—</option>{warehouses.map((warehouse) => <option key={warehouse.id} value={warehouse.id}>{warehouse.name}</option>)}</select></Field><Field label={text.location}><div className="mobile-location-control"><select value={form.location_id || ''} onChange={(e) => setForm({ ...form, location_id: e.target.value })}><option value="">Unassigned</option>{options.map((location) => <option key={location.id} value={location.id}>{location.path}</option>)}</select>{onScan && <button type="button" onClick={onScan}><ScanLine /><span>{text.scanLocation}</span></button>}</div></Field></div>
}

function TraceFields({ text, product, result, form, setForm, receive = false }) {
  if ((product.tracking_mode || 'none') === 'none') return null
  if (receive && product.tracking_mode === 'serial') return <div className="mobile-warehouse-grid"><Field label={`${text.accepted} · ${text.serial}`} hint="One serial per line"><textarea required={Number(form.accepted_quantity || 0) > 0} rows="4" value={form.accepted_serial_numbers || ''} onChange={(e) => setForm({ ...form, accepted_serial_numbers: e.target.value })} /></Field><Field label={`${text.damaged} · ${text.serial}`} hint="One serial per line"><textarea required={Number(form.damaged_quantity || 0) > 0} rows="4" value={form.damaged_serial_numbers || ''} onChange={(e) => setForm({ ...form, damaged_serial_numbers: e.target.value })} /></Field></div>
  const sourceLocation = String(form.source_key || form.location_id || '')
  const lots = (result.lots || []).filter((lot) => !sourceLocation || (lot.balances || []).some((balance) => String(balance.location_id) === sourceLocation && Number(balance.quantity || 0) > 0))
  if (!receive && lots.length) {
    if (product.tracking_mode === 'serial') return <Field label={text.serial} hint={text.trackedHint}><select required multiple size="5" value={(form.inventory_lot_ids || []).map(String)} onChange={(e) => setForm({ ...form, inventory_lot_ids: [...e.target.selectedOptions].map((option) => option.value) })}>{lots.map((lot) => <option key={lot.id} value={lot.id}>{lot.serial_number} · {lot.expiry_at ? String(lot.expiry_at).slice(0, 10) : 'active'}</option>)}</select></Field>
    return <Field label={text.lot} hint={text.trackedHint}><select required value={form.inventory_lot_id || ''} onChange={(e) => setForm({ ...form, inventory_lot_id: e.target.value })}><option value="">—</option>{lots.map((lot) => <option key={lot.id} value={lot.id}>{lot.lot_number} · {formatQuantity(lot.quantity_remaining, product.unit)} {product.unit}{lot.expiry_at ? ` · ${String(lot.expiry_at).slice(0, 10)}` : ''}</option>)}</select></Field>
  }
  return <div className="mobile-warehouse-grid"><Field label={text.lot} hint={text.trackedHint}><input required value={form.lot_number || ''} onChange={(e) => setForm({ ...form, lot_number: e.target.value })} /></Field><Field label={text.expiry}><input required={product.tracking_mode === 'batch_expiry'} type="date" value={form.expiry_at || ''} onChange={(e) => setForm({ ...form, expiry_at: e.target.value })} /></Field></div>
}
