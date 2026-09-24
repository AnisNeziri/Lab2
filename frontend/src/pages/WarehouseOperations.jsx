import EntityDocuments from '../components/EntityDocuments'
import EntityContext from '../components/EntityContext'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { ArrowRightLeft, Building2, FileDown, LocateFixed, MapPin, PackageCheck, Pencil, Plus, Search, Send, X } from 'lucide-react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { getAllProducts } from '../api/products'
import {
  cancelStockTransfer,
  createStockTransfer,
  createWarehouse,
  createWarehouseLocation,
  deleteWarehouse,
  deleteWarehouseLocation,
  dispatchStockTransfer,
  downloadGoodsReceiptPdf,
  getGoodsReceipt,
  getGoodsReceipts,
  getStockTransfers,
  getWarehouses,
  getWarehouseLocations,
  receiveStockTransfer,
  updateWarehouse,
  updateWarehouseLocation,
} from '../api/warehouseOperations'
import { useAuthStore } from '../store/authStore'
import { useSettingsStore } from '../store/settingsStore'
import { useTranslation } from '../hooks/useTranslation'
import { formatQuantity, isMeterUnit } from '../utils/formatQuantity'
import { getInventoryQuantity } from '../utils/inventoryQuantity'
import './WarehouseOperations.css'

const warehouseBlank = {
  name: '',
  code: '',
  address: '',
  length_m: '',
  width_m: '',
  height_m: '',
  floor_count: 1,
  is_active: true,
  is_default: false,
}
const locationBlank = { warehouse_id: '', parent_id: '', type: 'zone', code: '', name: '', floor_level: 1 }
const transferLine = () => ({ product_id: '', quantity: '', notes: '' })
const transferBlank = () => ({ source_warehouse_id: '', destination_warehouse_id: '', source_location_id: '', destination_location_id: '', notes: '', items: [transferLine()] })
const requestKey = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
const locatorColors = ['#258cf4', '#7c3aed', '#0891b2', '#059669', '#d97706', '#db2777']

function normalizedBalance(balance) {
  const available = Number(balance.available_quantity ?? balance.available ?? balance.quantity ?? 0)
  const reserved = Number(balance.reserved_quantity || 0)
  const damaged = Number(balance.damaged_quantity || 0)
  const quarantine = Number(balance.quarantine_quantity || 0)
  const blocked = Number(balance.blocked_quantity || 0)
  const stateTotal = available + reserved + damaged + quarantine + blocked

  return {
    ...balance,
    available,
    reserved,
    damaged,
    quarantine,
    blocked,
    total: stateTotal || Number(balance.quantity || 0),
  }
}

function errorText(error, fallback) {
  return error?.errors ? Object.values(error.errors).flat().join(' ') : (error?.message || fallback)
}

function optionalNumber(value) {
  return value === '' || value == null ? null : Number(value)
}

export default function WarehouseOperations() {
  const language = useSettingsStore((state) => state.language)
  const warehouseFormRef = useRef(null)
  const receiveKeyRef = useRef(null)
  const dispatchKeysRef = useRef(new Map())
  const navigate = useNavigate()
  const [documentParams] = useSearchParams()
  const linkedReceiptId = documentParams.get('receipt')
  const { t } = useTranslation()
  const permissions = useAuthStore((state) => state.permissions)
  const enable3dMap = useSettingsStore((state) => state.enable_3d_map)
  const theme = useSettingsStore((state) => state.theme)
  const activeLocatorColors = theme === 'dark'
    ? ['#60a5fa', '#a78bfa', '#22d3ee', '#34d399', '#fbbf24', '#f472b6']
    : locatorColors
  const canManageWarehouses = permissions.includes('warehouses.manage')
  const canManageTransfers = permissions.includes('transfers.manage')
  const canDispatch = permissions.includes('transfers.dispatch')
  const canReceive = permissions.includes('transfers.receive')
  const [tab, setTab] = useState('locator')
  const [warehouses, setWarehouses] = useState([])
  const [locations, setLocations] = useState([])
  const [products, setProducts] = useState([])
  const [locatorMode, setLocatorMode] = useState('product')
  const [locatorQuery, setLocatorQuery] = useState('')
  const [locatorCategoryId, setLocatorCategoryId] = useState('')
  const [locatorSupplierId, setLocatorSupplierId] = useState('')
  const [locatorLowOnly, setLocatorLowOnly] = useState(false)
  const [locatedProductId, setLocatedProductId] = useState('')
  const [selectedWarehouseId, setSelectedWarehouseId] = useState('')
  const [transfers, setTransfers] = useState([])
  const [receipts, setReceipts] = useState([])
  const [warehouseForm, setWarehouseForm] = useState(warehouseBlank)
  const [editingWarehouseId, setEditingWarehouseId] = useState(null)
  const [locationForm, setLocationForm] = useState(locationBlank)
  const [editingLocationId, setEditingLocationId] = useState(null)
  const [transferForm, setTransferForm] = useState(transferBlank)
  const [receiveTarget, setReceiveTarget] = useState(null)
  const [receiveLines, setReceiveLines] = useState([])
  const [receiptDetail, setReceiptDetail] = useState(null)
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(async ({ silent = false } = {}) => {
    if (!silent) setLoading(true)
    try {
      const [warehouseData, locationData, productData, transferData, receiptData] = await Promise.all([
        getWarehouses(), getWarehouseLocations(), getAllProducts(), getStockTransfers({ per_page: 100 }), getGoodsReceipts({ per_page: 100 }),
      ])
      setWarehouses(warehouseData || [])
      setLocations(locationData || [])
      setProducts(productData || [])
      setTransfers(transferData?.data || [])
      setReceipts(receiptData?.data || [])
      setError('')
    } catch (err) {
      if (!silent) setError(errorText(err, t('warehouseOps.loadError')))
    } finally {
      if (!silent) setLoading(false)
    }
  }, [t])

  useEffect(() => { void load() }, [load])
  useEffect(() => {
    if (!linkedReceiptId) return
    let active = true
    getGoodsReceipt(linkedReceiptId).then(data => { if(active){ setTab('receipts'); setReceiptDetail(data) } }).catch(err => { if(active) setError(err.message) })
    return () => { active = false }
  }, [linkedReceiptId])
  useEffect(() => {
    if (warehouses.length === 0) {
      setSelectedWarehouseId('')
      return
    }

    const selectionExists = warehouses.some((warehouse) => String(warehouse.id) === String(selectedWarehouseId))
    if (!selectionExists) {
      const preferred = warehouses.find((warehouse) => warehouse.is_default) || warehouses.find((warehouse) => warehouse.is_active) || warehouses[0]
      setSelectedWarehouseId(String(preferred.id))
    }
  }, [selectedWarehouseId, warehouses])
  useEffect(() => {
    const refresh = () => void load({ silent: true })
    window.addEventListener('database-refresh', refresh)
    window.addEventListener('stock-refresh', refresh)
    return () => {
      window.removeEventListener('database-refresh', refresh)
      window.removeEventListener('stock-refresh', refresh)
    }
  }, [load])

  const locationOptions = useMemo(() => locations.filter((row) => !locationForm.warehouse_id || String(row.warehouse_id) === String(locationForm.warehouse_id)), [locations, locationForm.warehouse_id])
  const sourceLocations = locations.filter((row) => String(row.warehouse_id) === String(transferForm.source_warehouse_id))
  const destinationLocations = locations.filter((row) => String(row.warehouse_id) === String(transferForm.destination_warehouse_id))
  const locatorCategories = useMemo(() => {
    const options = new Map()
    products.forEach((product) => {
      const id = product.category_id ?? product.category?.id
      const name = product.category?.name
      if (id != null && name) options.set(String(id), { id: String(id), name })
    })
    return [...options.values()].sort((left, right) => left.name.localeCompare(right.name))
  }, [products])
  const locatorSuppliers = useMemo(() => {
    const options = new Map()
    products.forEach((product) => {
      const id = product.supplier_id ?? product.supplier?.id
      const name = product.supplier?.name
      if (id != null && name) options.set(String(id), { id: String(id), name })
    })
    return [...options.values()].sort((left, right) => left.name.localeCompare(right.name))
  }, [products])
  const matchesLocatorFilters = useCallback((product, availableOverride = null) => {
    const categoryId = product.category_id ?? product.category?.id
    const supplierId = product.supplier_id ?? product.supplier?.id
    if (locatorCategoryId && String(categoryId ?? '') !== locatorCategoryId) return false
    if (locatorSupplierId && String(supplierId ?? '') !== locatorSupplierId) return false
    if (locatorLowOnly) {
      const quantity = Number(availableOverride ?? getInventoryQuantity(product, 'available'))
      const minimum = Number(product.min_quantity ?? 0)
      if (quantity > minimum) return false
    }
    return true
  }, [locatorCategoryId, locatorLowOnly, locatorSupplierId])
  const locatorProducts = useMemo(() => {
    const query = locatorQuery.trim().toLocaleLowerCase()
    const sorted = [...products].sort((left, right) => left.name.localeCompare(right.name))
    return sorted.filter((product) => matchesLocatorFilters(product))
      .filter((product) => !query || [product.name, product.sku, product.barcode]
        .some((value) => String(value || '').toLocaleLowerCase().includes(query)))
      .slice(0, 20)
  }, [locatorQuery, matchesLocatorFilters, products])
  useEffect(() => {
    if (locatedProductId && !locatorProducts.some((product) => String(product.id) === String(locatedProductId))) {
      setLocatedProductId('')
    }
  }, [locatedProductId, locatorProducts])
  const locatedProduct = useMemo(
    () => products.find((product) => String(product.id) === String(locatedProductId)) || null,
    [locatedProductId, products],
  )
  const locatedBalances = useMemo(() => (locatedProduct?.warehouse_stock || [])
    .map(normalizedBalance)
    .filter((balance) => balance.total > 0)
    .sort((left, right) => right.total - left.total), [locatedProduct])
  const locatedTotal = useMemo(
    () => locatedBalances.reduce((sum, balance) => sum + balance.total, 0),
    [locatedBalances],
  )
  const selectedWarehouse = useMemo(
    () => warehouses.find((warehouse) => String(warehouse.id) === String(selectedWarehouseId)) || null,
    [selectedWarehouseId, warehouses],
  )
  const editingWarehouse = useMemo(
    () => warehouses.find((warehouse) => String(warehouse.id) === String(editingWarehouseId)) || null,
    [editingWarehouseId, warehouses],
  )
  const warehouseInventory = useMemo(() => {
    const query = locatorQuery.trim().toLocaleLowerCase()

    return products.flatMap((product) => (product.warehouse_stock || [])
      .filter((balance) => String(balance.warehouse_id) === String(selectedWarehouseId))
      .map((balance) => ({ product, ...normalizedBalance(balance) })))
      .filter((row) => row.total > 0)
      .filter((row) => matchesLocatorFilters(row.product, row.available))
      .filter((row) => !query || [row.product.name, row.product.sku, row.product.barcode, row.location?.path]
        .some((value) => String(value || '').toLocaleLowerCase().includes(query)))
      .sort((left, right) => left.product.name.localeCompare(right.product.name))
  }, [locatorQuery, matchesLocatorFilters, products, selectedWarehouseId])
  const warehouseStockCount = useCallback((warehouseId) => products.reduce((count, product) => {
    const hasStock = (product.warehouse_stock || []).some((balance) => String(balance.warehouse_id) === String(warehouseId) && normalizedBalance(balance).total > 0)
    return count + (hasStock ? 1 : 0)
  }, 0), [products])
  const productFor = (productId) => products.find((product) => String(product.id) === String(productId))
  const sourceAvailable = (product) => {
    if (!product || !transferForm.source_warehouse_id) return null
    const balances = Array.isArray(product.warehouse_stock) ? product.warehouse_stock : null
    if (!balances?.length) return getInventoryQuantity(product, 'available')
    return balances
      .filter((balance) => String(balance.warehouse_id) === String(transferForm.source_warehouse_id)
        && (!transferForm.source_location_id || String(balance.location_id || '') === String(transferForm.source_location_id)))
      .reduce((total, balance) => total + Number(balance.available_quantity ?? balance.available ?? balance.quantity ?? 0), 0)
  }

  async function perform(action, success) {
    if (busy) return
    setBusy(true); setError(''); setMessage('')
    try {
      await action()
      setMessage(success)
      await load({ silent: true })
    } catch (err) {
      setError(errorText(err, t('warehouseOps.actionError')))
    } finally { setBusy(false) }
  }

  function saveWarehouse(event) {
    event.preventDefault()
    return perform(async () => {
      const payload = {
        name: warehouseForm.name.trim(),
        code: warehouseForm.code.trim().toUpperCase(),
        address: warehouseForm.address.trim() || null,
        length_m: optionalNumber(warehouseForm.length_m),
        width_m: optionalNumber(warehouseForm.width_m),
        height_m: optionalNumber(warehouseForm.height_m),
        floor_count: Number(warehouseForm.floor_count || 1),
        is_active: Boolean(warehouseForm.is_active),
        is_default: Boolean(warehouseForm.is_default),
      }
      if (editingWarehouseId) await updateWarehouse(editingWarehouseId, payload)
      else await createWarehouse(payload)
      setEditingWarehouseId(null)
      setWarehouseForm(warehouseBlank)
    }, t('warehouseOps.warehouseSaved'))
  }

  function editWarehouse(warehouse) {
    setEditingWarehouseId(warehouse.id)
    setWarehouseForm({
      name: warehouse.name || '',
      code: warehouse.code || '',
      address: warehouse.address || '',
      length_m: warehouse.length_m ?? '',
      width_m: warehouse.width_m ?? '',
      height_m: warehouse.height_m ?? '',
      floor_count: Number(warehouse.floor_count || 1),
      is_active: Boolean(warehouse.is_active),
      is_default: Boolean(warehouse.is_default),
    })
    requestAnimationFrame(() => {
      warehouseFormRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      warehouseFormRef.current?.querySelector('input[name="warehouse-name"]')?.focus({ preventScroll: true })
    })
  }

  function cancelWarehouseEdit() {
    setEditingWarehouseId(null)
    setWarehouseForm(warehouseBlank)
  }

  function saveLocation(event) {
    event.preventDefault()
    return perform(async () => {
      const payload = { ...locationForm, warehouse_id: Number(locationForm.warehouse_id), parent_id: locationForm.parent_id ? Number(locationForm.parent_id) : null, floor_level: Number(locationForm.floor_level) }
      if (editingLocationId) await updateWarehouseLocation(editingLocationId, payload)
      else await createWarehouseLocation(payload)
      setEditingLocationId(null)
      setLocationForm((current) => ({ ...locationBlank, warehouse_id: current.warehouse_id }))
    }, t('warehouseOps.locationSaved'))
  }

  function editLocation(location) {
    setEditingLocationId(location.id)
    setLocationForm({ warehouse_id: String(location.warehouse_id), parent_id: location.parent_id ? String(location.parent_id) : '', type: location.type, code: location.code, name: location.name, floor_level: Number(location.floor_level || 1) })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function saveTransfer(event) {
    event.preventDefault()
    return perform(async () => {
      await createStockTransfer({
        ...transferForm,
        source_warehouse_id: Number(transferForm.source_warehouse_id),
        destination_warehouse_id: Number(transferForm.destination_warehouse_id),
        source_location_id: transferForm.source_location_id ? Number(transferForm.source_location_id) : null,
        destination_location_id: transferForm.destination_location_id ? Number(transferForm.destination_location_id) : null,
        items: transferForm.items.map((row) => ({ product_id: Number(row.product_id), quantity: Number(row.quantity), notes: row.notes || null })),
      })
      setTransferForm(transferBlank())
    }, t('warehouseOps.transferSaved'))
  }

  function openReceive(transfer) {
    receiveKeyRef.current = requestKey()
    setReceiveTarget(transfer)
    setReceiveLines(transfer.items.map((item) => ({ id: item.id, name: item.product?.name || '-', unit: item.product?.unit || '', remaining: Math.max(0, Number(item.quantity) - Number(item.received_quantity || 0) - Number(item.damaged_quantity || 0)), accepted_quantity: Math.max(0, Number(item.quantity) - Number(item.received_quantity || 0) - Number(item.damaged_quantity || 0)), damaged_quantity: 0 })))
  }

  async function viewReceipt(receipt) {
    setError('')
    try { setReceiptDetail(await getGoodsReceipt(receipt.id)) } catch (err) { setError(errorText(err, t('warehouseOps.actionError'))) }
  }

  const tabs = [
    ['locator', LocateFixed, t('warehouseOps.stockLocator')],
    ['warehouses', Building2, t('warehouseOps.warehouses')],
    ['locations', MapPin, t('warehouseOps.locations')],
    ['transfers', ArrowRightLeft, t('warehouseOps.transfers')],
    ['receipts', PackageCheck, t('warehouseOps.receipts')],
  ]

  return (
    <main className="warehouse-ops-page">
      <header className="warehouse-ops-hero">
        <div><p className="eyebrow">AIMS WMS</p><h1>{t('warehouseOps.title')}</h1><p>{t('warehouseOps.subtitle')}</p></div>
        <div className="warehouse-ops-hero-actions">
          {enable3dMap ? <div className="warehouse-ops-map-actions"><button type="button" onClick={() => navigate('/warehouse-layout')}>{t('warehouseOps.openLayout')}</button><button type="button" onClick={() => navigate('/warehouse-3d')}>{t('warehouseOps.open3d')}</button></div> : null}
          <div className="warehouse-ops-kpis">
            <span><strong>{warehouses.filter((row) => row.is_active).length}</strong>{t('warehouseOps.activeWarehouses')}</span>
            <span><strong>{transfers.filter((row) => ['in_transit', 'partially_received'].includes(row.status)).length}</strong>{t('warehouseOps.inTransit')}</span>
            <span><strong>{receipts.length}</strong>{t('warehouseOps.goodsReceipts')}</span>
          </div>
        </div>
      </header>

      <nav className="warehouse-tabs" aria-label={t('warehouseOps.title')}>
        {tabs.map(([id, Icon, label]) => <button key={id} type="button" className={tab === id ? 'active' : ''} onClick={() => setTab(id)}><Icon size={18} />{label}</button>)}
      </nav>
      {error ? <p className="error warehouse-feedback">{error}</p> : null}
      {message ? <p className="success warehouse-feedback">{message}</p> : null}
      {loading ? <section className="card"><p>{t('common.loading')}</p></section> : null}

      {!loading && tab === 'warehouses' && <div className="warehouse-grid">
        {canManageWarehouses && <section ref={warehouseFormRef} className="card warehouse-side-form" id="warehouse-form"><h2>{editingWarehouseId ? <Pencil size={19} /> : <Plus size={19} />}{t(editingWarehouseId ? 'warehouseOps.editWarehouse' : 'warehouseOps.addWarehouse')}</h2><form onSubmit={saveWarehouse}>
          <label>{t('warehouseOps.name')}<input name="warehouse-name" value={warehouseForm.name} onChange={(event) => setWarehouseForm({ ...warehouseForm, name: event.target.value })} required /></label>
          <label>{t('warehouseOps.code')}<input value={warehouseForm.code} onChange={(event) => setWarehouseForm({ ...warehouseForm, code: event.target.value.toUpperCase() })} required /></label>
          <label>{t('warehouseOps.address')}<textarea rows="2" value={warehouseForm.address} onChange={(event) => setWarehouseForm({ ...warehouseForm, address: event.target.value })} /></label>
          <fieldset className="warehouse-dimensions">
            <legend>{t('warehouseOps.dimensions')}</legend>
            <label>{t('warehouseOps.length')}<input type="number" min="1" max="1000" step="0.1" value={warehouseForm.length_m} onChange={(event) => setWarehouseForm({ ...warehouseForm, length_m: event.target.value })} placeholder="m" /></label>
            <label>{t('warehouseOps.width')}<input type="number" min="1" max="1000" step="0.1" value={warehouseForm.width_m} onChange={(event) => setWarehouseForm({ ...warehouseForm, width_m: event.target.value })} placeholder="m" /></label>
            <label>{t('warehouseOps.height')}<input type="number" min="1" max="100" step="0.1" value={warehouseForm.height_m} onChange={(event) => setWarehouseForm({ ...warehouseForm, height_m: event.target.value })} placeholder="m" /></label>
            <label>{t('warehouseOps.floorCount')}<input type="number" min="1" max="20" step="1" value={warehouseForm.floor_count} onChange={(event) => setWarehouseForm({ ...warehouseForm, floor_count: event.target.value })} required /></label>
          </fieldset>
          <div className="warehouse-toggle-grid">
            <label className="check-line"><input type="checkbox" checked={warehouseForm.is_active} disabled={Boolean(editingWarehouse?.is_default)} onChange={(event) => setWarehouseForm({ ...warehouseForm, is_active: event.target.checked })} />{t('warehouseOps.active')}</label>
            <label className="check-line"><input type="checkbox" checked={warehouseForm.is_default} disabled={Boolean(editingWarehouse?.is_default)} onChange={(event) => setWarehouseForm({ ...warehouseForm, is_default: event.target.checked, is_active: event.target.checked ? true : warehouseForm.is_active })} />{t('warehouseOps.makeDefault')}</label>
          </div>
          <div className="warehouse-form-actions"><button type="submit" disabled={busy}>{t('common.save')}</button>{editingWarehouseId ? <button type="button" className="secondary" disabled={busy} onClick={cancelWarehouseEdit}>{t('common.cancel')}</button> : null}</div>
        </form></section>}
        <section className="card warehouse-main-list"><h2>{t('warehouseOps.warehouseList')}</h2><div className="warehouse-card-list">{warehouses.map((row) => <article className="warehouse-summary" key={row.id}>
          <div><h3>{row.name}{row.is_default ? <span className="status-pill">{t('warehouseOps.default')}</span> : null}{!row.is_active ? <span className="status-pill inactive">{t('warehouseOps.inactive')}</span> : null}</h3><p>{row.code} · {row.address || t('warehouseOps.noAddress')}</p></div>
          <div className="stock-state-grid"><span>{t('warehouseOps.productsAvailable')}<strong>{row.available_products_count || 0}</strong></span><span>{t('warehouseOps.productsReserved')}<strong>{row.reserved_products_count || 0}</strong></span><span>{t('warehouseOps.productsDamaged')}<strong>{row.damaged_products_count || 0}</strong></span><span>{t('warehouseOps.locations')}<strong>{row.locations_count || 0}</strong></span></div>
          {canManageWarehouses && <div className="row-actions"><button type="button" className="secondary" disabled={busy} onClick={() => editWarehouse(row)}><Pencil size={15} />{t('common.edit')}</button>{!row.is_default && <><button type="button" className="secondary" disabled={busy} onClick={() => perform(() => updateWarehouse(row.id, { is_default: true, is_active: true }), t('warehouseOps.defaultChanged'))}>{t('warehouseOps.setDefault')}</button><button type="button" className="secondary" disabled={busy} onClick={() => perform(() => updateWarehouse(row.id, { is_active: !row.is_active }), t('warehouseOps.warehouseSaved'))}>{row.is_active ? t('warehouseOps.deactivate') : t('warehouseOps.activate')}</button><button type="button" className="danger" disabled={busy} onClick={() => window.confirm(t('warehouseOps.deleteWarehouseConfirm')) && perform(() => deleteWarehouse(row.id), t('warehouseOps.deleted'))}>{t('common.delete')}</button></>}</div>}
        </article>)}</div></section>
      </div>}

      {!loading && tab === 'locator' && <section className="card stock-locator-panel">
        <header className="stock-locator-heading">
          <span className="stock-locator-heading-icon"><LocateFixed size={24} /></span>
          <div><h2>{t('warehouseOps.locatorTitle')}</h2><p>{t('warehouseOps.locatorSubtitle')}</p></div>
        </header>
        <div className="stock-locator-modes" role="tablist" aria-label={t('warehouseOps.locatorView')}>
          <button type="button" role="tab" aria-selected={locatorMode === 'product'} className={locatorMode === 'product' ? 'active' : ''} onClick={() => { setLocatorMode('product'); setLocatorQuery('') }}><LocateFixed size={17} />{t('warehouseOps.locatorByProduct')}</button>
          <button type="button" role="tab" aria-selected={locatorMode === 'warehouse'} className={locatorMode === 'warehouse' ? 'active' : ''} onClick={() => { setLocatorMode('warehouse'); setLocatorQuery('') }}><Building2 size={17} />{t('warehouseOps.locatorByWarehouse')}</button>
        </div>
        <label className="stock-locator-search" aria-label={t(locatorMode === 'product' ? 'warehouseOps.locatorSearch' : 'warehouseOps.warehouseStockSearch')}>
          <Search size={19} aria-hidden="true" />
          <input type="search" value={locatorQuery} onChange={(event) => setLocatorQuery(event.target.value)} placeholder={t(locatorMode === 'product' ? 'warehouseOps.locatorSearch' : 'warehouseOps.warehouseStockSearch')} />
        </label>
        <div className="stock-locator-filters">
          <label className="stock-locator-filter-control"><span>{t('warehouseOps.filterCategory')}</span><select value={locatorCategoryId} onChange={(event) => setLocatorCategoryId(event.target.value)}><option value="">{t('warehouseOps.allCategories')}</option>{locatorCategories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
          <label className="stock-locator-filter-control"><span>{t('warehouseOps.filterSupplier')}</span><select value={locatorSupplierId} onChange={(event) => setLocatorSupplierId(event.target.value)}><option value="">{t('warehouseOps.allSuppliers')}</option>{locatorSuppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}</select></label>
          <label className="stock-locator-low-filter"><input type="checkbox" checked={locatorLowOnly} onChange={(event) => setLocatorLowOnly(event.target.checked)} /><span>{t('warehouseOps.lowQuantityOnly')}</span></label>
          {(locatorCategoryId || locatorSupplierId || locatorLowOnly) ? <button type="button" className="secondary stock-locator-filter-clear" onClick={() => { setLocatorCategoryId(''); setLocatorSupplierId(''); setLocatorLowOnly(false) }}><X size={16} />{t('warehouseOps.clearFilters')}</button> : null}
        </div>
        {locatorMode === 'product' ? <>
          <div className="stock-locator-product-grid">
            {locatorProducts.map((product) => {
              const balanceCount = (product.warehouse_stock || []).filter((balance) => Number(balance.quantity ?? balance.available_quantity ?? 0) > 0).length
              return <button key={product.id} type="button" className={String(product.id) === String(locatedProductId) ? 'active' : ''} onClick={() => setLocatedProductId(product.id)}>
                <span className="stock-locator-product-icon"><PackageCheck size={18} /></span>
                <span><strong>{product.name}</strong><small>{product.sku || t('warehouseOps.locatorNoSku')} · {formatQuantity(getInventoryQuantity(product, 'available'), product.unit)} {product.unit}</small></span>
                <em>{balanceCount}</em>
              </button>
            })}
            {locatorProducts.length === 0 ? <p className="stock-locator-empty">{t('warehouseOps.locatorNoProducts')}</p> : null}
          </div>

          {!locatedProduct ? <div className="stock-locator-prompt"><LocateFixed size={34} /><strong>{t('warehouseOps.locatorSelect')}</strong></div> : (
            <div className="stock-locator-overview">
              <div className="stock-locator-summary">
                <div><small>{t('warehouseOps.locatorTotalStock')}</small><strong>{formatQuantity(locatedTotal, locatedProduct.unit)} <span>{locatedProduct.unit}</span></strong></div>
                <div><small>{t('warehouseOps.locatorInWarehouses')}</small><strong>{locatedBalances.length}</strong></div>
              </div>
              {locatedBalances.length > 0 ? <>
                <div className="stock-distribution-bar" aria-label={t('warehouseOps.locatorDistribution')}>
                  {locatedBalances.map((balance, index) => <span key={balance.id} title={`${balance.warehouse?.name}: ${formatQuantity(balance.total, locatedProduct.unit)} ${locatedProduct.unit}`} style={{ '--locator-color': activeLocatorColors[index % activeLocatorColors.length], flexGrow: balance.total }} />)}
                </div>
                <div className="stock-locator-warehouses">
                  {locatedBalances.map((balance, index) => {
                    const share = locatedTotal > 0 ? Math.round((balance.total / locatedTotal) * 100) : 0
                    return <article key={balance.id} className="stock-warehouse-card" style={{ '--locator-color': activeLocatorColors[index % activeLocatorColors.length] }}>
                      <header><div><Building2 size={19} /><span><strong>{balance.warehouse?.name || t('warehouseOps.unknownWarehouse')}</strong><small>{balance.warehouse?.code || '—'}</small></span></div><em>{share}%</em></header>
                      <div className="stock-warehouse-quantity"><strong>{formatQuantity(balance.total, locatedProduct.unit)}</strong><span>{locatedProduct.unit}</span></div>
                      <div className="stock-location-badge"><MapPin size={16} /><span><small>{t('warehouseOps.location')}</small><strong>{balance.location?.path || t('warehouseOps.locatorUnassigned')}</strong>{balance.location?.floor_level ? <em>{t('warehouseOps.level')} {balance.location.floor_level}</em> : null}</span></div>
                      <div className="stock-state-chips">
                        <span>{t('warehouseOps.available')} <strong>{formatQuantity(balance.available, locatedProduct.unit)}</strong></span>
                        {balance.reserved > 0 ? <span>{t('warehouseOps.reserved')} <strong>{formatQuantity(balance.reserved, locatedProduct.unit)}</strong></span> : null}
                        {balance.damaged > 0 ? <span>{t('warehouseOps.damaged')} <strong>{formatQuantity(balance.damaged, locatedProduct.unit)}</strong></span> : null}
                        {balance.quarantine > 0 ? <span>{t('warehouseOps.quarantine')} <strong>{formatQuantity(balance.quarantine, locatedProduct.unit)}</strong></span> : null}
                      </div>
                    </article>
                  })}
                </div>
              </> : <div className="stock-locator-prompt"><PackageCheck size={34} /><strong>{t('warehouseOps.locatorNoStock')}</strong></div>}
            </div>
          )}
        </> : <div className="warehouse-browser">
          <div className="warehouse-picker" aria-label={t('warehouseOps.chooseWarehouse')}>
            {warehouses.map((warehouse, index) => {
              const stockCount = warehouseStockCount(warehouse.id)
              return <button key={warehouse.id} type="button" className={String(warehouse.id) === String(selectedWarehouseId) ? 'active' : ''} style={{ '--locator-color': activeLocatorColors[index % activeLocatorColors.length] }} onClick={() => setSelectedWarehouseId(String(warehouse.id))}>
                <span className="warehouse-picker-icon"><Building2 size={20} /></span>
                <span><strong>{warehouse.name}</strong><small>{warehouse.code} · {warehouse.address || t('warehouseOps.noAddress')}</small></span>
                <em>{stockCount} {t(stockCount === 1 ? 'warehouseOps.sku' : 'warehouseOps.skus')}</em>
              </button>
            })}
          </div>
          {selectedWarehouse ? <>
            <EntityContext entityType="warehouse" entityId={selectedWarehouse.id} title={language==='sq'?'Daljet e magazinës':'Warehouse outbound'}/>
            <header className="warehouse-inventory-heading">
              <div><span><Building2 size={20} /></span><div><h3>{selectedWarehouse.name}</h3><p>{selectedWarehouse.code} · {selectedWarehouse.address || t('warehouseOps.noAddress')}</p></div></div>
              <div className="warehouse-inventory-count"><strong>{warehouseInventory.length}</strong><small>{t('warehouseOps.productsShown')} / {warehouseStockCount(selectedWarehouse.id)}</small></div>
            </header>
            <div className="warehouse-inventory-grid">
              {warehouseInventory.map((row, index) => <article key={`${row.product.id}-${row.id}`} className="warehouse-inventory-item" style={{ '--locator-color': activeLocatorColors[index % activeLocatorColors.length] }}>
                <header><div><span className="stock-locator-product-icon"><PackageCheck size={18} /></span><span><strong>{row.product.name}</strong><small>{row.product.sku || t('warehouseOps.locatorNoSku')}</small></span></div><div className="warehouse-item-total"><strong>{formatQuantity(row.total, row.product.unit)}</strong><small>{row.product.unit}</small></div></header>
                <div className="stock-location-badge"><MapPin size={16} /><span><small>{t('warehouseOps.location')}</small><strong>{row.location?.path || t('warehouseOps.locatorUnassigned')}</strong>{row.location?.floor_level ? <em>{t('warehouseOps.level')} {row.location.floor_level}</em> : null}</span></div>
                <div className="stock-state-chips">
                  <span>{t('warehouseOps.available')} <strong>{formatQuantity(row.available, row.product.unit)}</strong></span>
                  {row.reserved > 0 ? <span>{t('warehouseOps.reserved')} <strong>{formatQuantity(row.reserved, row.product.unit)}</strong></span> : null}
                  {row.damaged > 0 ? <span>{t('warehouseOps.damaged')} <strong>{formatQuantity(row.damaged, row.product.unit)}</strong></span> : null}
                  {row.quarantine > 0 ? <span>{t('warehouseOps.quarantine')} <strong>{formatQuantity(row.quarantine, row.product.unit)}</strong></span> : null}
                </div>
              </article>)}
            </div>
            {warehouseInventory.length === 0 ? <div className="stock-locator-prompt"><PackageCheck size={34} /><strong>{locatorQuery ? t('warehouseOps.locatorNoProducts') : t('warehouseOps.warehouseNoStock')}</strong></div> : null}
          </> : <div className="stock-locator-prompt"><Building2 size={34} /><strong>{t('warehouseOps.chooseWarehouse')}</strong></div>}
        </div>}
      </section>}

      {!loading && tab === 'locations' && <div className="warehouse-grid">
        {canManageWarehouses && <section className="card warehouse-side-form"><h2><Plus size={19} />{editingLocationId ? t('warehouseOps.editLocation') : t('warehouseOps.addLocation')}</h2><form onSubmit={saveLocation}>
          <label>{t('warehouseOps.warehouse')}<select required value={locationForm.warehouse_id} onChange={(event) => setLocationForm({ ...locationForm, warehouse_id: event.target.value, parent_id: '', floor_level: 1 })}><option value="">{t('common.select')}</option>{warehouses.filter((row) => row.is_active).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>
          <label>{t('warehouseOps.type')}<select value={locationForm.type} onChange={(event) => setLocationForm({ ...locationForm, type: event.target.value, parent_id: event.target.value === 'zone' ? '' : locationForm.parent_id })}>{['zone', 'rack', 'shelf', 'bin'].map((type) => <option key={type} value={type}>{t(`warehouseOps.type.${type}`)}</option>)}</select></label>
          {locationForm.type !== 'zone' && <label>{t('warehouseOps.parent')}<select required value={locationForm.parent_id} onChange={(event) => { const parent = locations.find((row) => String(row.id) === event.target.value); setLocationForm({ ...locationForm, parent_id: event.target.value, floor_level: Number(parent?.floor_level || locationForm.floor_level) }) }}><option value="">{t('common.select')}</option>{locationOptions.filter((row) => ({ rack: 'zone', shelf: 'rack', bin: 'shelf' })[locationForm.type] === row.type).map((row) => <option key={row.id} value={row.id}>{row.path} · {row.name}</option>)}</select></label>}
          <label>{t('warehouseOps.level')}<input type="number" min="1" max="100" step="1" value={locationForm.floor_level} disabled={Boolean(locationForm.parent_id)} onChange={(event) => setLocationForm({ ...locationForm, floor_level: event.target.value })} /><small>{locationForm.parent_id ? t('warehouseOps.levelInherited') : t('warehouseOps.levelHint')}</small></label>
          <label>{t('warehouseOps.code')}<input required value={locationForm.code} onChange={(event) => setLocationForm({ ...locationForm, code: event.target.value.toUpperCase() })} /></label>
          <label>{t('warehouseOps.name')}<input required value={locationForm.name} onChange={(event) => setLocationForm({ ...locationForm, name: event.target.value })} /></label>
          <button disabled={busy}>{t('common.save')}</button>
          {editingLocationId && <button type="button" className="secondary" onClick={() => { setEditingLocationId(null); setLocationForm((current) => ({ ...locationBlank, warehouse_id: current.warehouse_id })) }}>{t('common.cancel')}</button>}
        </form></section>}
        <section className="card warehouse-main-list"><h2>{t('warehouseOps.locationTree')}</h2><p className="warehouse-location-sync-note">{t('warehouseOps.layoutSync')}</p><div className="location-list">{locations.map((row) => <div key={row.id} className={`location-row level-${row.path?.split('/').length || 1}`}><span className="type-dot">{row.type.slice(0, 1).toUpperCase()}</span><div><strong>{row.path}</strong><small>{row.name} · {row.warehouse?.name} · {t('warehouseOps.level')} {row.floor_level || 1}</small></div>{canManageWarehouses && <div className="location-row-actions"><button type="button" className="secondary" onClick={() => editLocation(row)}>{t('common.edit')}</button><button type="button" className="icon-danger" aria-label={t('common.delete')} onClick={() => window.confirm(t('warehouseOps.deleteLocationConfirm')) && perform(() => deleteWarehouseLocation(row.id), t('warehouseOps.deleted'))}><X size={17} /></button></div>}</div>)}</div></section>
      </div>}

      {!loading && tab === 'transfers' && <div className="warehouse-grid transfers-layout">
        {canManageTransfers && <section className="card warehouse-side-form"><h2><Plus size={19} />{t('warehouseOps.newTransfer')}</h2><form onSubmit={saveTransfer}>
          <label>{t('warehouseOps.from')}<select required value={transferForm.source_warehouse_id} onChange={(event) => setTransferForm({ ...transferForm, source_warehouse_id: event.target.value, source_location_id: '' })}><option value="">{t('common.select')}</option>{warehouses.filter((row) => row.is_active).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>
          <label>{t('warehouseOps.to')}<select required value={transferForm.destination_warehouse_id} onChange={(event) => setTransferForm({ ...transferForm, destination_warehouse_id: event.target.value, destination_location_id: '' })}><option value="">{t('common.select')}</option>{warehouses.filter((row) => row.is_active && String(row.id) !== String(transferForm.source_warehouse_id)).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>
          {sourceLocations.length > 0 && <label>{t('warehouseOps.sourceLocation')}<select value={transferForm.source_location_id} onChange={(event) => setTransferForm({ ...transferForm, source_location_id: event.target.value })}><option value="">{t('warehouseOps.anyLocation')}</option>{sourceLocations.map((row) => <option key={row.id} value={row.id}>{row.path}</option>)}</select></label>}
          {destinationLocations.length > 0 && <label>{t('warehouseOps.destinationLocation')}<select value={transferForm.destination_location_id} onChange={(event) => setTransferForm({ ...transferForm, destination_location_id: event.target.value })}><option value="">{t('warehouseOps.anyLocation')}</option>{destinationLocations.map((row) => <option key={row.id} value={row.id}>{row.path}</option>)}</select></label>}
          <fieldset className="transfer-items-fieldset"><legend>{t('warehouseOps.items')}</legend><div className="transfer-items-list">{transferForm.items.map((line, index) => {
            const product = productFor(line.product_id)
            const available = sourceAvailable(product)
            const allowsDecimals = isMeterUnit(product?.unit)
            return <div className="transfer-line" key={index}>
              <label className="transfer-product-control"><span>{t('warehouseOps.selectProduct')}</span><select required value={line.product_id} onChange={(event) => setTransferForm((current) => ({ ...current, items: current.items.map((row, rowIndex) => rowIndex === index ? { ...row, product_id: event.target.value, quantity: '' } : row) }))}><option value="">{t('common.select')}</option>{products.map((option) => { const stock = sourceAvailable(option); return <option key={option.id} value={option.id}>{option.name}{stock == null ? '' : ` · ${t('warehouseOps.availableShort')} ${formatQuantity(stock, option.unit)} ${option.unit}`}</option> })}</select>{product && available != null ? <small className={available <= 0 ? 'is-empty' : ''}>{t('warehouseOps.sourceAvailable')}: {formatQuantity(available, product.unit)} {product.unit}</small> : null}</label>
              <label className="transfer-quantity-control"><span>{t('warehouseOps.quantity')}</span><input required type="number" min={allowsDecimals ? '0.001' : '1'} step={allowsDecimals ? '0.001' : '1'} max={available != null ? available : undefined} inputMode="decimal" placeholder="0" value={line.quantity} onChange={(event) => setTransferForm((current) => ({ ...current, items: current.items.map((row, rowIndex) => rowIndex === index ? { ...row, quantity: event.target.value } : row) }))}/></label>
              {transferForm.items.length > 1 && <button type="button" className="icon-danger transfer-remove-item" aria-label={t('common.delete')} onClick={() => setTransferForm((current) => ({ ...current, items: current.items.filter((_, rowIndex) => rowIndex !== index) }))}><X size={16} /></button>}
            </div>
          })}</div></fieldset>
          <button type="button" className="secondary" onClick={() => setTransferForm((current) => ({ ...current, items: [...current.items, transferLine()] }))}><Plus size={16} />{t('warehouseOps.addItem')}</button>
          <label>{t('warehouseOps.notes')}<textarea rows="2" value={transferForm.notes} onChange={(event) => setTransferForm({ ...transferForm, notes: event.target.value })} /></label>
          <button disabled={busy}>{t('warehouseOps.createDraft')}</button>
        </form></section>}
        <section className="card warehouse-main-list"><h2>{t('warehouseOps.transferHistory')}</h2><div className="transfer-list">{transfers.map((row) => <article key={row.id} className="transfer-card"><header><div><strong>{row.transfer_number}</strong><span className={`transfer-status ${row.status}`}>{t(`warehouseOps.status.${row.status}`)}</span></div><time>{new Date(row.created_at).toLocaleString()}</time></header><p>{row.source_warehouse?.name} <ArrowRightLeft size={15} /> {row.destination_warehouse?.name}</p><ul>{row.items?.map((item) => <li key={item.id}>{item.product?.name}: {formatQuantity(item.quantity, item.product?.unit)} {item.product?.unit} <small>({formatQuantity(Number(item.received_quantity || 0) + Number(item.damaged_quantity || 0), item.product?.unit)} {t('warehouseOps.received').toLowerCase()})</small></li>)}</ul><div className="row-actions">{row.status === 'draft' && canDispatch && <button disabled={busy} onClick={() => perform(async () => { const key = dispatchKeysRef.current.get(row.id) || requestKey(); dispatchKeysRef.current.set(row.id, key); await dispatchStockTransfer(row.id, key); dispatchKeysRef.current.delete(row.id) }, t('warehouseOps.dispatched'))}><Send size={16}/>{t('warehouseOps.dispatch')}</button>}{['in_transit', 'partially_received'].includes(row.status) && canReceive && <button disabled={busy} onClick={() => openReceive(row)}><PackageCheck size={16}/>{t('warehouseOps.receive')}</button>}{['draft', 'in_transit', 'partially_received'].includes(row.status) && canManageTransfers && <button className="danger" disabled={busy} onClick={() => { const reason = window.prompt(t('warehouseOps.cancelReason')); if (reason) void perform(() => cancelStockTransfer(row.id, reason), t('warehouseOps.cancelled')) }}>{t('common.cancel')}</button>}</div></article>)}</div></section>
      </div>}

      {!loading && tab === 'receipts' && (
        <section className="card">
          <div className="warehouse-operations-section-heading">
            <div>
              <h2>{t('warehouseOps.goodsReceipts')}</h2>
              <p>{t('warehouseOps.receiptsHint')}</p>
            </div>
          </div>
          <div className="table-wrap">
            <table>
              <thead><tr><th>{t('warehouseOps.receiptNumber')}</th><th>{t('warehouseOps.purchaseOrder')}</th><th>{t('warehouseOps.supplier')}</th><th>{t('warehouseOps.warehouse')}</th><th>{t('warehouseOps.receivedAt')}</th><th>{t('warehouseOps.items')}</th><th /></tr></thead>
              <tbody>{receipts.map((row) => <tr key={row.id}><td>{row.receipt_number}</td><td>{row.purchase_order?.po_number}</td><td>{row.purchase_order?.supplier?.name || '—'}</td><td>{row.warehouse?.name}</td><td>{new Date(row.received_at).toLocaleString()}</td><td>{row.items_count}</td><td className="row-actions"><button className="secondary" onClick={() => viewReceipt(row)}>{t('common.view')}</button><button className="secondary" onClick={() => downloadGoodsReceiptPdf(row.id, row.receipt_number)}><FileDown size={16}/>PDF</button></td></tr>)}</tbody>
            </table>
          </div>
          {receipts.length === 0 ? <p>{t('warehouseOps.noReceipts')}</p> : null}
        </section>
      )}

      {receiveTarget && <div className="modal-overlay" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) { receiveKeyRef.current = null; setReceiveTarget(null) } }}><section className="modal warehouse-action-modal" role="dialog" aria-modal="true"><header className="modal-header"><h2>{t('warehouseOps.receive')} {receiveTarget.transfer_number}</h2><button className="modal-close-btn" disabled={busy} onClick={() => { receiveKeyRef.current = null; setReceiveTarget(null) }}><X /></button></header><div className="modal-body"><p>{receiveTarget.destination_warehouse?.name}</p>{receiveLines.map((line, index) => <div className="receive-line" key={line.id}><strong>{line.name}</strong><small>{t('warehouseOps.remaining')}: {formatQuantity(line.remaining, line.unit)} {line.unit}</small><label>{t('warehouseOps.accepted')}<input type="number" min="0" max={line.remaining} step="0.001" value={line.accepted_quantity} onChange={(event) => setReceiveLines((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, accepted_quantity: event.target.value } : row))}/></label><label>{t('warehouseOps.damaged')}<input type="number" min="0" max={line.remaining} step="0.001" value={line.damaged_quantity} onChange={(event) => setReceiveLines((current) => current.map((row, rowIndex) => rowIndex === index ? { ...row, damaged_quantity: event.target.value } : row))}/></label></div>)}<button disabled={busy} onClick={() => perform(async () => { const key = receiveKeyRef.current || requestKey(); receiveKeyRef.current = key; await receiveStockTransfer(receiveTarget.id, { idempotency_key: key, items: receiveLines.map((row) => ({ id: row.id, accepted_quantity: Number(row.accepted_quantity || 0), damaged_quantity: Number(row.damaged_quantity || 0) })) }); receiveKeyRef.current = null; setReceiveTarget(null) }, t('warehouseOps.receivedSaved'))}>{t('warehouseOps.confirmReceipt')}</button></div></section></div>}

      {receiptDetail && <div className="modal-overlay" onMouseDown={(event) => event.target === event.currentTarget && setReceiptDetail(null)}><section className="modal warehouse-action-modal" role="dialog" aria-modal="true"><header className="modal-header"><div><h2>{receiptDetail.receipt_number}</h2><small>{receiptDetail.purchase_order?.po_number} · {receiptDetail.warehouse?.name}</small></div><button className="modal-close-btn" onClick={() => setReceiptDetail(null)}><X /></button></header><div className="modal-body"><EntityDocuments entityType="goods-receipt" entityId={receiptDetail.id}/><div className="table-wrap"><table><thead><tr><th>{t('warehouseOps.product')}</th><th>{t('warehouseOps.accepted')}</th><th>{t('warehouseOps.damaged')}</th><th>{t('warehouseOps.rejected')}</th></tr></thead><tbody>{receiptDetail.items?.map((item) => <tr key={item.id}><td>{item.product?.name || item.purchase_order_item?.description}</td><td>{formatQuantity(item.accepted_quantity)} {item.inventory_unit}</td><td>{formatQuantity(item.damaged_quantity)} {item.inventory_unit}</td><td>{formatQuantity(item.rejected_quantity)} {item.inventory_unit}</td></tr>)}</tbody></table></div><button onClick={() => downloadGoodsReceiptPdf(receiptDetail.id, receiptDetail.receipt_number)}><FileDown size={16}/> {t('warehouseOps.downloadPdf')}</button></div></section></div>}
    </main>
  )
}
