import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Area,
  CartesianGrid,
  ComposedChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { getAllProducts } from '../api/products'
import { formatQuantity, isMeterUnit } from '../utils/formatQuantity'
import { getInventoryQuantity } from '../utils/inventoryQuantity'
import { getInventoryProduct } from '../api/advancedOperations'
import { getWarehouses, getWarehouseLocations } from '../api/warehouseOperations'
import {
  createStockMovement,
  exportStockMovements,
  getStockMovements,
  lookupProductBySku,
} from '../api/stock'

function buildChartData(movements) {
  const byDay = {}
  movements.forEach((m) => {
    const day = m.created_at ? m.created_at.slice(0, 10) : 'unknown'
    if (!byDay[day]) byDay[day] = { date: day, in: 0, out: 0 }
    if (m.type === 'in') byDay[day].in += Number(m.quantity)
    else byDay[day].out += Number(m.quantity)
  })
  return Object.values(byDay)
    .sort((a, b) => a.date.localeCompare(b.date))
    .slice(-30)
}

function MovementTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null
  const fmt = (d) => {
    if (!d || d === 'unknown') return d
    const [y, m, day] = d.split('-')
    return new Date(y, m - 1, day).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
  }
  return (
    <div style={{
      background: '#0f172a',
      border: '1px solid #1e293b',
      borderRadius: 10,
      padding: '10px 16px',
      fontSize: 13,
      color: '#e2e8f0',
      boxShadow: '0 8px 32px rgba(0,0,0,0.4)',
    }}>
      <p style={{ color: '#94a3b8', marginBottom: 6, fontWeight: 600 }}>{fmt(label)}</p>
      {payload.map((entry) => (
        <p key={entry.dataKey} style={{ color: entry.color, margin: '2px 0' }}>
          {entry.dataKey === 'in' ? '▲ Inbound' : '▼ Outbound'}: {entry.dataKey === 'out' ? '-' : '+'}{entry.value}
        </p>
      ))}
    </div>
  )
}

function movementLabel(code, type) {
  if (!code) return type === 'in' ? 'Stock in' : 'Stock out'
  return code.split('_').map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(' ')
}

const emptyForm = {
  product_id: '',
  warehouse_id: '',
  location_id: '',
  type: 'in',
  stock_state: 'available',
  quantity: 1,
  reason: '',
  lot_number: '',
  serial_numbers: '',
  inventory_lot_id: '',
  inventory_lot_ids: [],
  manufactured_at: '',
  expiry_at: '',
}

function productStockSummary(product) {
  const unit = product.unit || 'units'
  return `${formatQuantity(getInventoryQuantity(product, 'on_hand'), product.unit)} ${unit} on hand; ${formatQuantity(getInventoryQuantity(product, 'available'), product.unit)} ${unit} available`
}

function Stock() {
  const [products, setProducts] = useState([])
  const [warehouses, setWarehouses] = useState([])
  const [locations, setLocations] = useState([])
  const [inventoryDetail, setInventoryDetail] = useState(null)
  const [movements, setMovements] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [filters, setFilters] = useState({ product_id: '', type: '' })
  const [skuLookup, setSkuLookup] = useState('')
  const [lookupMessage, setLookupMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [exporting, setExporting] = useState(false)
  const filtersRef = useRef(filters)
  const dataRequestRef = useRef(0)
  const submissionKeyRef = useRef(null)
  filtersRef.current = filters

  const chartData = useMemo(() => buildChartData(movements), [movements])
  const selectedProduct = useMemo(
    () => products.find((product) => String(product.id) === String(form.product_id)),
    [form.product_id, products],
  )
  const activeLocations = useMemo(
    () => locations.filter((location) => String(location.warehouse_id) === String(form.warehouse_id) && location.is_active !== false),
    [form.warehouse_id, locations],
  )
  const eligibleLots = useMemo(() => (inventoryDetail?.lots || []).map((lot) => ({
    ...lot,
    eligible_quantity: (lot.balances || [])
      .filter((balance) => String(balance.warehouse_id) === String(form.warehouse_id)
        && String(balance.location_id || '') === String(form.location_id)
        && String(balance.stock_state || 'available') === String(form.stock_state))
      .reduce((total, balance) => total + Number(balance.quantity || 0), 0),
  })).filter((lot) => lot.eligible_quantity > 0), [form.location_id, form.stock_state, form.warehouse_id, inventoryDetail])

  const loadMovements = useCallback(async (activeFilters = filtersRef.current) => {
    const movementFilters = {}
    if (activeFilters.product_id) {
      movementFilters.product_id = Number(activeFilters.product_id)
    }
    if (activeFilters.type) {
      movementFilters.type = activeFilters.type
    }
    return getStockMovements(movementFilters)
  }, [])

  const loadData = useCallback(async ({ silent = false, activeFilters = filtersRef.current } = {}) => {
    const requestId = ++dataRequestRef.current
    try {
      if (!silent) {
        setLoading(true)
        setError('')
      }
      const [productsList, movementsData, warehouseList] = await Promise.all([
        getAllProducts(),
        loadMovements(activeFilters),
        getWarehouses(),
      ])
      const locationLists = await Promise.all((warehouseList || [])
        .filter((warehouse) => warehouse.is_active !== false)
        .map((warehouse) => getWarehouseLocations(warehouse.id)))
      if (requestId !== dataRequestRef.current) return
      setProducts(productsList)
      setMovements(movementsData)
      setWarehouses(warehouseList || [])
      setLocations(locationLists.flatMap((value) => value?.data || value || []))
    } catch {
      if (!silent && requestId === dataRequestRef.current) {
        setError('Could not load stock data.')
      }
    } finally {
      if (!silent) setLoading(false)
    }
  }, [loadMovements])

  useEffect(() => {
    loadData()
    const refresh = () => loadData({ silent: true })
    window.addEventListener('database-refresh', refresh)
    return () => window.removeEventListener('database-refresh', refresh)
  }, [loadData])

  useEffect(() => {
    let current = true
    if (!form.product_id || !form.warehouse_id || !form.location_id) {
      setInventoryDetail(null)
      return () => { current = false }
    }
    getInventoryProduct(form.product_id, {
      warehouse_id: form.warehouse_id,
      location_id: form.location_id,
    }).then((value) => {
      if (current) setInventoryDetail(value)
    }).catch(() => {
      if (current) setInventoryDetail(null)
    })
    return () => { current = false }
  }, [form.location_id, form.product_id, form.warehouse_id])

  async function applyFilters(event) {
    event.preventDefault()
    try {
      setLoading(true)
      setError('')
      const movementsData = await loadMovements(filters)
      setMovements(movementsData)
    } catch {
      setError('Could not filter stock movements.')
    } finally {
      setLoading(false)
    }
  }

  function clearFilters() {
    const cleared = { product_id: '', type: '' }
    setFilters(cleared)
    setLoading(true)
    loadMovements(cleared)
      .then((movementsData) => setMovements(movementsData))
      .catch(() => setError('Could not load stock movements.'))
      .finally(() => setLoading(false))
  }

  function handleChange(event) {
    const { name, value } = event.target
    submissionKeyRef.current = null
    setForm((current) => {
      const next = { ...current, [name]: value }
      if (name === 'product_id') {
        const product = products.find((item) => String(item.id) === String(value))
        next.warehouse_id = product?.default_warehouse_id
          ? String(product.default_warehouse_id)
          : String(warehouses.find((warehouse) => warehouse.is_default && warehouse.is_active !== false)?.id || '')
        next.location_id = ''
      }
      if (['product_id', 'warehouse_id', 'location_id', 'type', 'stock_state'].includes(name)) {
        next.inventory_lot_id = ''
        next.inventory_lot_ids = []
        next.lot_number = ''
        next.serial_numbers = ''
        next.manufactured_at = ''
        next.expiry_at = ''
      }
      if (name === 'warehouse_id') next.location_id = ''
      return next
    })
  }

  function handleSerialLotSelection(event) {
    const selected = [...event.target.selectedOptions].map((option) => option.value)
    submissionKeyRef.current = null
    setForm((current) => ({ ...current, inventory_lot_ids: selected, quantity: selected.length }))
  }

  function handleFilterChange(event) {
    const { name, value } = event.target
    setFilters((current) => ({
      ...current,
      [name]: value,
    }))
  }

  async function handleSkuLookup(event) {
    event.preventDefault()
    setLookupMessage('')
    setFormError('')

    if (!skuLookup.trim()) {
      return
    }

    try {
      const product = await lookupProductBySku(skuLookup.trim())
      submissionKeyRef.current = null
      setForm((current) => ({
        ...current,
        product_id: String(product.id),
        warehouse_id: product.default_warehouse_id
          ? String(product.default_warehouse_id)
          : String(warehouses.find((warehouse) => warehouse.is_default && warehouse.is_active !== false)?.id || ''),
        location_id: '',
        inventory_lot_id: '',
        inventory_lot_ids: [],
      }))
      setLookupMessage(`Selected: ${product.name} (${productStockSummary(product)})`)
    } catch {
      setLookupMessage('No product found for that SKU.')
    }
  }

  async function handleExport() {
    try {
      setExporting(true)
      const exportFilters = {}
      if (filters.product_id) {
        exportFilters.product_id = Number(filters.product_id)
      }
      if (filters.type) {
        exportFilters.type = filters.type
      }
      await exportStockMovements(exportFilters)
    } catch {
      setError('Could not export stock movements.')
    } finally {
      setExporting(false)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFormError('')

    try {
      submissionKeyRef.current ||= globalThis.crypto?.randomUUID?.()
        || `stock-${Date.now()}-${Math.random().toString(16).slice(2)}`
      const mode = selectedProduct?.tracking_mode || 'none'
      const expirationControlled = Boolean(selectedProduct?.expiration_controlled || mode === 'batch_expiry')
      let quantity = Number(form.quantity)
      let traceAllocations = []
      if (mode === 'serial' && form.type === 'in') {
        const serials = [...new Set(form.serial_numbers.split(/[\n,;]+/).map((value) => value.trim()).filter(Boolean))]
        if (!serials.length || serials.length !== quantity) {
          throw new Error(`Enter exactly ${quantity} unique serial number(s).`)
        }
        traceAllocations = serials.map((serial_number) => ({
          serial_number,
          manufactured_at: form.manufactured_at || null,
          expiry_at: form.expiry_at || null,
          quantity: 1,
        }))
      } else if (mode === 'serial' && form.type === 'out') {
        quantity = form.inventory_lot_ids.length
        if (!quantity) throw new Error('Select every serial number included in this adjustment.')
        traceAllocations = form.inventory_lot_ids.map((inventory_lot_id) => ({
          inventory_lot_id: Number(inventory_lot_id),
          quantity: 1,
        }))
      } else if (mode !== 'none' && form.type === 'in') {
        if (!form.lot_number.trim()) throw new Error('Enter the lot or batch number.')
        traceAllocations = [{
          lot_number: form.lot_number.trim(),
          manufactured_at: form.manufactured_at || null,
          expiry_at: form.expiry_at || null,
          quantity,
        }]
      } else if (mode !== 'none') {
        if (!form.inventory_lot_id) throw new Error('Select the lot or batch included in this adjustment.')
        traceAllocations = [{ inventory_lot_id: Number(form.inventory_lot_id), quantity }]
      }
      if (expirationControlled && form.type === 'in' && !form.expiry_at
        && (!selectedProduct?.default_shelf_life_days
          || (selectedProduct?.shelf_life_basis || 'manufacture_date') === 'manufacture_date' && !form.manufactured_at)) {
        throw new Error('Enter an expiry date, or provide the date required to calculate it.')
      }

      await createStockMovement({
        product_id: Number(form.product_id),
        warehouse_id: Number(form.warehouse_id),
        location_id: Number(form.location_id),
        type: form.type,
        stock_state: form.stock_state,
        quantity,
        reason: form.reason.trim(),
        idempotency_key: submissionKeyRef.current,
        ...(traceAllocations.length ? { trace_allocations: traceAllocations } : {}),
      })

      submissionKeyRef.current = null
      setForm(emptyForm)
      setSkuLookup('')
      setLookupMessage('')
      await loadData({ silent: true })
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else if (err.message) {
        setFormError(err.message)
      } else {
        setFormError('Could not record stock movement.')
      }
    }
  }

  return (
    <main className="stock-page">
      <section className="card">
        <h2>Adjust stock</h2>
        <p className="card-description">
          Record stock coming in or going out. On-hand and available balances update automatically.
        </p>

        <form className="sku-lookup-form" onSubmit={handleSkuLookup}>
          <label>
            Find by SKU
            <div className="form-row">
              <input
                value={skuLookup}
                onChange={(event) => setSkuLookup(event.target.value)}
                placeholder="e.g. ELEC-001"
              />
              <button type="submit" className="secondary">
                Find product
              </button>
            </div>
          </label>
          {lookupMessage && <p className="lookup-message">{lookupMessage}</p>}
        </form>

        <form className="stock-form" onSubmit={handleSubmit}>
          <label>
            Product
            <select name="product_id" value={form.product_id} onChange={handleChange} required>
              <option value="">Select a product</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name} ({product.sku}) - {productStockSummary(product)}
                </option>
              ))}
            </select>
          </label>

          <div className="form-row">
            <label>
              Warehouse
              <select name="warehouse_id" value={form.warehouse_id} onChange={handleChange} required>
                <option value="">Select a warehouse</option>
                {warehouses.filter((warehouse) => warehouse.is_active !== false).map((warehouse) => (
                  <option key={warehouse.id} value={warehouse.id}>{warehouse.name} ({warehouse.code})</option>
                ))}
              </select>
            </label>

            <label>
              Exact location
              <select name="location_id" value={form.location_id} onChange={handleChange} required>
                <option value="">Select a bin or location</option>
                {activeLocations.map((location) => (
                  <option key={location.id} value={location.id}>{location.path || location.name}</option>
                ))}
              </select>
            </label>
          </div>

          <div className="form-row">
            <label>
              Type
              <select name="type" value={form.type} onChange={handleChange} required>
                <option value="in">Stock in</option>
                <option value="out">Stock out</option>
              </select>
            </label>

            <label>
              Stock state
              <select name="stock_state" value={form.stock_state} onChange={handleChange} required>
                {['available', 'reserved', 'damaged', 'quarantine', 'blocked'].map((state) => (
                  <option key={state} value={state}>{state.charAt(0).toUpperCase() + state.slice(1)}</option>
                ))}
              </select>
            </label>

            <label>
              Quantity
              <input
                name="quantity"
                type="number"
                min={isMeterUnit(selectedProduct?.unit) ? '0.001' : '1'}
                step={isMeterUnit(selectedProduct?.unit) ? '0.001' : '1'}
                value={form.quantity}
                onChange={handleChange}
                readOnly={selectedProduct?.tracking_mode === 'serial' && form.type === 'out'}
                required
              />
            </label>
          </div>

          {selectedProduct && selectedProduct.tracking_mode !== 'none' && form.type === 'out' ? (
            selectedProduct.tracking_mode === 'serial' ? (
              <label>
                Serial numbers at this location
                <select multiple size="6" value={form.inventory_lot_ids} onChange={handleSerialLotSelection} required>
                  {eligibleLots.map((lot) => (
                    <option key={lot.id} value={lot.id}>{lot.serial_number} {lot.expiry_at ? `· expires ${String(lot.expiry_at).slice(0, 10)}` : ''}</option>
                  ))}
                </select>
                <small>Select every serial included in the adjustment.</small>
              </label>
            ) : (
              <label>
                Lot or batch at this location
                <select name="inventory_lot_id" value={form.inventory_lot_id} onChange={handleChange} required>
                  <option value="">Select a lot</option>
                  {eligibleLots.map((lot) => (
                    <option key={lot.id} value={lot.id}>{lot.lot_number} · {formatQuantity(lot.eligible_quantity, selectedProduct.unit)} available {lot.expiry_at ? `· expires ${String(lot.expiry_at).slice(0, 10)}` : ''}</option>
                  ))}
                </select>
              </label>
            )
          ) : null}

          {selectedProduct && selectedProduct.tracking_mode !== 'none' && form.type === 'in' ? (
            <div className="opening-trace-panel">
              {selectedProduct.tracking_mode === 'serial' ? (
                <label>
                  Serial numbers
                  <textarea name="serial_numbers" rows="5" value={form.serial_numbers} onChange={handleChange} placeholder="One serial per line" required />
                </label>
              ) : (
                <label>
                  Lot or batch number
                  <input name="lot_number" value={form.lot_number} onChange={handleChange} required />
                </label>
              )}
              {selectedProduct.expiration_controlled || selectedProduct.tracking_mode === 'batch_expiry' ? (
                <div className="form-row">
                  <label>
                    Manufacture date
                    <input name="manufactured_at" type="date" value={form.manufactured_at} onChange={handleChange} />
                  </label>
                  <label>
                    Expiry date
                    <input name="expiry_at" type="date" value={form.expiry_at} onChange={handleChange} />
                  </label>
                </div>
              ) : null}
            </div>
          ) : null}

          <label>
            Reason
            <input
              name="reason"
              value={form.reason}
              onChange={handleChange}
              placeholder="e.g. New delivery, sold to customer"
              minLength="3"
              required
            />
          </label>

          {formError && <p className="error">{formError}</p>}

          <button type="submit">Record movement</button>
        </form>
      </section>

      <section className="card">
        <div className="section-header">
          <h2>Recent movements</h2>
          <div className="section-header-actions">
            {!loading && <p className="result-count">{movements.length} record(s)</p>}
            <button
              type="button"
              className="secondary"
              onClick={handleExport}
              disabled={exporting || loading}
            >
              {exporting ? 'Exporting...' : 'Export CSV'}
            </button>
          </div>
        </div>

        <form className="filter-form" onSubmit={applyFilters}>
          <label>
            Product
            <select name="product_id" value={filters.product_id} onChange={handleFilterChange}>
              <option value="">All products</option>
              {products.map((product) => (
                <option key={product.id} value={product.id}>
                  {product.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Type
            <select name="type" value={filters.type} onChange={handleFilterChange}>
              <option value="">All types</option>
              <option value="in">Stock in</option>
              <option value="out">Stock out</option>
            </select>
          </label>

          <div className="form-actions">
            <button type="submit">Apply filters</button>
            <button type="button" className="secondary" onClick={clearFilters}>
              Clear
            </button>
          </div>
        </form>

        {chartData.length > 0 && (
          <div style={{ marginBottom: 24 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 20, marginBottom: 12 }}>
              <p style={{ fontSize: 13, fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.05em', margin: 0 }}>
                Movement Trends
              </p>
              <div style={{ display: 'flex', gap: 16 }}>
                <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#64748b' }}>
                  <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#16a34a', display: 'inline-block' }} />
                  Stock In
                </span>
                <span style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12, color: '#64748b' }}>
                  <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#d97706', display: 'inline-block' }} />
                  Stock Out
                </span>
              </div>
            </div>
            <ResponsiveContainer width="100%" height={250}>
              <ComposedChart data={chartData} margin={{ top: 4, right: 16, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="gIn" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#16a34a" stopOpacity={0.25} />
                    <stop offset="95%" stopColor="#16a34a" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="gOut" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#d97706" stopOpacity={0.22} />
                    <stop offset="95%" stopColor="#d97706" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" vertical={false} />
                <XAxis
                  dataKey="date"
                  tick={{ fontSize: 11, fill: '#94a3b8' }}
                  tickLine={false}
                  axisLine={false}
                  tickFormatter={(d) => {
                    if (!d || d === 'unknown') return ''
                    const [y, m, day] = d.split('-')
                    return new Date(y, m - 1, day).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
                  }}
                />
                <YAxis
                  tick={{ fontSize: 11, fill: '#94a3b8' }}
                  tickLine={false}
                  axisLine={false}
                  allowDecimals={false}
                />
                <Tooltip content={<MovementTooltip />} />
                <Area
                  type="monotone"
                  dataKey="in"
                  stroke="#16a34a"
                  strokeWidth={2}
                  fill="url(#gIn)"
                  dot={false}
                  activeDot={{ r: 5, fill: '#16a34a', strokeWidth: 0 }}
                  isAnimationActive={true}
                  animationDuration={600}
                />
                <Area
                  type="monotone"
                  dataKey="out"
                  stroke="#d97706"
                  strokeWidth={2}
                  fill="url(#gOut)"
                  dot={false}
                  activeDot={{ r: 5, fill: '#d97706', strokeWidth: 0 }}
                  isAnimationActive={true}
                  animationDuration={600}
                />
              </ComposedChart>
            </ResponsiveContainer>
          </div>
        )}

        {loading && <p>Loading stock history...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && movements.length === 0 && (
          <p>No stock movements recorded yet.</p>
        )}

        {!loading && movements.length > 0 && (
          <table className="product-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Movement</th>
                <th>Qty</th>
                <th>Company stock before</th>
                <th>Company stock after</th>
                <th>Source</th>
                <th>User</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              {movements.map((movement) => (
                <tr key={movement.id}>
                  <td>{new Date(movement.occurred_at || movement.created_at).toLocaleString()}</td>
                  <td>{movement.product?.name}</td>
                  <td>
                    <span className={`badge badge-${movement.type}`}>
                      {movementLabel(movement.movement_code, movement.type)}
                    </span>
                  </td>
                  <td>{formatQuantity(movement.quantity, movement.product?.unit)}</td>
                  <td>{formatQuantity(movement.quantity_before, movement.product?.unit)}</td>
                  <td>{formatQuantity(movement.quantity_after, movement.product?.unit)}</td>
                  <td>{movement.source_type ? `${movement.source_type}${movement.source_id ? ` #${movement.source_id}` : ''}` : '-'}</td>
                  <td>{movement.actor?.name || 'System'}</td>
                  <td>{movement.reason ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}

export default Stock
