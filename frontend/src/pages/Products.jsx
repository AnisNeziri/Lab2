import { useEffect, useRef, useState } from 'react'
import { getCategories } from '../api/categories'
import { getSuppliers } from '../api/suppliers'
import {
  createProduct,
  deleteProduct,
  exportProducts,
  getProducts,
  uploadProductImage,
  updateProduct,
} from '../api/products'
import { importProducts } from '../api/import'
import StockBadge from '../components/StockBadge'
import { useAuthStore } from '../store/authStore'
import { Upload } from 'lucide-react'
import { getWarehouseSections } from '../api/warehouse'
import { getWarehouses } from '../api/warehouseOperations'
import { sectionLocationKey } from '../lib/warehouseLayout'
import { formatQuantity, isMeterUnit } from '../utils/formatQuantity'
import ProductDetail from '../components/ProductDetail'
import SuccessAnimation from '../components/SuccessAnimation'
import { useTranslation } from '../hooks/useTranslation'

const emptyForm = {
  category_id: '',
  supplier_id: '',
  name: '',
  sku: '',
  barcode: '',
  description: '',
  quantity: 0,
  quantity_change_reason: '',
  unit: 'pcs',
  default_warehouse_id: '',
  tracking_mode: 'none',
  near_expiry_days: 30,
  fefo_enabled: true,
  opening_lot_number: '',
  opening_supplier_batch: '',
  opening_manufactured_at: '',
  opening_expiry_at: '',
  opening_serial_numbers: '',
  unit_conversions: [],
  min_quantity: 5,
  safety_stock: 0,
  reorder_point: '',
  replenishment_history_days: 90,
  replenishment_review_days: 14,
  high_stock_threshold: 0,
  weight_kg: '',
  volume_m3: '',
  location_code: '',
  purchase_price: '',
  selling_price: '',
  image: null,
}

function Products() {
  const { t } = useTranslation()
  const userRole = useAuthStore((state) => state.role)
  const [products, setProducts] = useState([])
  const [pagination, setPagination] = useState(null)
  const [categories, setCategories] = useState([])
  const [suppliers, setSuppliers] = useState([])
  const [warehouseSections, setWarehouseSections] = useState([])
  const [warehouses, setWarehouses] = useState([])
  const [form, setForm] = useState(emptyForm)
  const [search, setSearch] = useState('')
  const [categoryFilter, setCategoryFilter] = useState('')
  const [supplierFilter, setSupplierFilter] = useState('')
  const [lowStockOnly, setLowStockOnly] = useState(false)
  const [sortBy, setSortBy] = useState('name')
  const [sortDirection, setSortDirection] = useState('asc')
  const [page, setPage] = useState(1)
  const [editingId, setEditingId] = useState(null)
  const [viewProductId, setViewProductId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const formSectionRef = useRef(null)
  const productsRequestRef = useRef(0)
  const [productSuccess, setProductSuccess] = useState(null)
  const [highlightedProductId, setHighlightedProductId] = useState(null)
  const [saving, setSaving] = useState(false)
  async function loadCategories() {
    const categoriesData = await getCategories()
    setCategories(categoriesData)
  }

  async function loadSuppliers() {
    const suppliersData = await getSuppliers()
    setSuppliers(suppliersData)
  }

  async function loadProducts(filters = {}, { silent = false } = {}) {
    const requestId = ++productsRequestRef.current
    try {
      if (!silent) {
        setLoading(true)
        setError('')
      }
      const response = await getProducts(filters)
      if (requestId !== productsRequestRef.current) return
      setProducts(response.data)
      setPagination({
        current_page: response.current_page,
        last_page: response.last_page,
        per_page: response.per_page,
        total: response.total,
      })
    } catch {
      if (!silent && requestId === productsRequestRef.current) {
        setError('Could not load products. Make sure the API is running.')
      }
    } finally {
      if (!silent) setLoading(false)
    }
  }

  useEffect(() => {
    Promise.all([
      loadCategories(),
      loadSuppliers(),
      getWarehouseSections().then(setWarehouseSections).catch(() => []),
      getWarehouses().then((data) => {
        setWarehouses(data || [])
        const primary = (data || []).find((warehouse) => warehouse.is_default)
        if (primary) setForm((current) => current.default_warehouse_id ? current : { ...current, default_warehouse_id: String(primary.id) })
      }).catch(() => []),
    ]).catch(() => {
      setError('Could not load categories or suppliers. Make sure the API is running.')
    })
  }, [])

  function getActiveFilters(targetPage = page) {
    return {
      search: search.trim(),
      category_id: categoryFilter,
      supplier_id: supplierFilter,
      low_stock: lowStockOnly,
      sort: sortBy,
      direction: sortDirection,
      page: targetPage,
      per_page: 10,
    }
  }

  useEffect(() => {
    setPage(1)
  }, [search, categoryFilter, supplierFilter, lowStockOnly, sortBy, sortDirection])

  useEffect(() => {
    const timer = setTimeout(() => {
      loadProducts(getActiveFilters(page))
    }, 300)

    return () => clearTimeout(timer)
  }, [search, categoryFilter, supplierFilter, lowStockOnly, sortBy, sortDirection, page])

  useEffect(() => {
    const refresh = () => loadProducts(getActiveFilters(page), { silent: true })
    window.addEventListener('database-refresh', refresh)
    return () => window.removeEventListener('database-refresh', refresh)
  }, [page, search, categoryFilter, supplierFilter, lowStockOnly, sortBy, sortDirection])

  useEffect(() => {
    if (!viewProductId) return undefined

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.body.style.overflow = previousOverflow
    }
  }, [viewProductId])

  function handleChange(event) {
    const { name, value, checked, type } = event.target
    setForm((current) => ({
      ...current,
      [name]: type === 'checkbox' ? checked : value,
    }))
  }

  function startEdit(product) {
    setEditingId(product.id)
    setFormError('')
    setForm({
      category_id: product.category_id ?? '',
      supplier_id: product.supplier_id ?? '',
      name: product.name,
      sku: product.sku || '',
      barcode: product.barcode ?? '',
      description: product.description ?? '',
      quantity: product.quantity,
      quantity_change_reason: '',
      unit: product.unit ?? 'pcs',
      default_warehouse_id: product.default_warehouse_id ? String(product.default_warehouse_id) : '',
      tracking_mode: product.tracking_mode || 'none',
      near_expiry_days: product.near_expiry_days ?? 30,
      fefo_enabled: product.fefo_enabled !== false,
      opening_lot_number: '',
      opening_supplier_batch: '',
      opening_manufactured_at: '',
      opening_expiry_at: '',
      opening_serial_numbers: '',
      unit_conversions: (product.units || []).filter((row) => row.is_active !== false && row.conversion_mode === 'fixed').map((row) => ({
        code: row.code || '', label: row.label || '',
        factor_to_base: row.factor_to_base ?? '',
        is_active: row.is_active !== false,
      })),
      min_quantity: product.min_quantity ?? 5,
      safety_stock: product.safety_stock ?? 0,
      reorder_point: product.reorder_point ?? '',
      replenishment_history_days: product.replenishment_history_days ?? 90,
      replenishment_review_days: product.replenishment_review_days ?? 14,
      high_stock_threshold: product.high_stock_threshold ?? 0,
      weight_kg: product.weight_kg ?? '',
      volume_m3: product.volume_m3 ?? '',
      location_code: product.location_code ?? '',
      purchase_price: product.purchase_price ?? '',
      selling_price: product.selling_price ?? product.price ?? '',
      image: null,
    })

    window.requestAnimationFrame(() => {
      formSectionRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      formSectionRef.current?.querySelector('input[name="name"]')?.focus({ preventScroll: true })
    })
  }

  function cancelEdit() {
    setEditingId(null)
    const primary = warehouses.find((warehouse) => warehouse.is_default)
    setForm({ ...emptyForm, default_warehouse_id: primary ? String(primary.id) : '' })
    setFormError('')
  }

  function clearFilters() {
    setSearch('')
    setCategoryFilter('')
    setSupplierFilter('')
    setLowStockOnly(false)
    setSortBy('name')
    setSortDirection('asc')
    setPage(1)
  }

  function updateConversion(index, field, value) {
    setForm((current) => ({
      ...current,
      unit_conversions: current.unit_conversions.map((row, rowIndex) => {
        if (rowIndex === index) return { ...row, [field]: value }
        return row
      }),
    }))
  }

  function handleImageChange(event) {
    setForm((current) => ({
      ...current,
      image: event.target.files?.[0] ?? null,
    }))
  }


  async function handleExport() {
    try {
      await exportProducts()
    } catch {
      setError('Could not export products.')
    }
  }

  async function handleImport(event) {
    const file = event.target.files?.[0]
    if (!file) return

    try {
      const result = await importProducts(file)
      alert(`Imported ${result.records_imported} of ${result.records_total} products.`)
      await loadProducts(getActiveFilters())
    } catch (err) {
      setError(err.message || 'Could not import products.')
    } finally {
      event.target.value = ''
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (saving) return
    setFormError('')
    const creating = !editingId
    const openingQuantity = Number(form.quantity || 0)
    const openingTraceAllocations = []

    if (creating && openingQuantity > 0 && ['batch', 'batch_expiry'].includes(form.tracking_mode)) {
      if (!form.opening_lot_number.trim()) {
        setFormError(t('productTracking.openingLotRequired'))
        return
      }
      if (form.tracking_mode === 'batch_expiry' && !form.opening_expiry_at) {
        setFormError(t('productTracking.openingExpiryRequired'))
        return
      }
      openingTraceAllocations.push({
        lot_number: form.opening_lot_number.trim(),
        supplier_batch: form.opening_supplier_batch.trim() || null,
        manufactured_at: form.opening_manufactured_at || null,
        expiry_at: form.opening_expiry_at || null,
        quantity: openingQuantity,
      })
    }

    if (creating && openingQuantity > 0 && form.tracking_mode === 'serial') {
      if (!Number.isInteger(openingQuantity)) {
        setFormError(t('productTracking.serialWholeQuantity'))
        return
      }
      if (openingQuantity > 1000) {
        setFormError(t('productTracking.serialOpeningLimit'))
        return
      }
      const serials = form.opening_serial_numbers
        .split(/[\r\n,]+/)
        .map((value) => value.trim())
        .filter(Boolean)
      if (serials.length !== openingQuantity) {
        setFormError(t('productTracking.serialCount').replace('{{count}}', String(openingQuantity)))
        return
      }
      if (new Set(serials.map((value) => value.toLowerCase())).size !== serials.length) {
        setFormError(t('productTracking.serialUnique'))
        return
      }
      openingTraceAllocations.push(...serials.map((serial_number) => ({ serial_number, quantity: 1 })))
    }

    setSaving(true)
    const trackedEdit = Boolean(editingId && form.tracking_mode !== 'none')

    const payload = {
      category_id: Number(form.category_id),
      supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
      name: form.name,
      ...(form.sku.trim() ? { sku: form.sku.trim() } : {}),
      barcode: form.barcode || null,
      description: form.description || null,
      ...(!trackedEdit ? { quantity: openingQuantity } : {}),
      ...(editingId ? { quantity_change_reason: form.quantity_change_reason.trim() || null } : {}),
      unit: form.unit || 'pcs',
      default_warehouse_id: form.default_warehouse_id ? Number(form.default_warehouse_id) : null,
      tracking_mode: form.tracking_mode || 'none',
      near_expiry_days: Number(form.near_expiry_days || 0),
      fefo_enabled: Boolean(form.fefo_enabled),
      ...(creating && openingTraceAllocations.length ? { opening_trace_allocations: openingTraceAllocations } : {}),
      unit_conversions: form.unit_conversions.map((row) => ({
        code: row.code.trim(), label: row.label.trim() || null,
        conversion_mode: 'fixed',
        factor_to_base: Number(row.factor_to_base),
        is_active: row.is_active !== false,
      })),
      min_quantity: Number(form.min_quantity),
      safety_stock: Number(form.safety_stock || 0),
      reorder_point: form.reorder_point === '' ? null : Number(form.reorder_point),
      replenishment_history_days: Number(form.replenishment_history_days || 90),
      replenishment_review_days: Number(form.replenishment_review_days || 14),
      high_stock_threshold: Number(form.high_stock_threshold),
      weight_kg: form.weight_kg === '' ? null : Number(form.weight_kg),
      volume_m3: form.volume_m3 === '' ? null : Number(form.volume_m3),
      location_code: form.location_code || null,
      // `price` remains the compatibility field used by existing inventory
      // calculations; the user-facing form exposes only purchase and selling.
      price: Number(form.selling_price),
      purchase_price: form.purchase_price ? Number(form.purchase_price) : null,
      selling_price: form.selling_price ? Number(form.selling_price) : null,
    }

    try {
      let savedProduct
      if (editingId) {
        savedProduct = await updateProduct(editingId, payload)
      } else {
        savedProduct = await createProduct(payload)
      }

      if (form.image && savedProduct?.id) {
        await uploadProductImage(savedProduct.id, form.image)
      }

      if (creating) {
        setProductSuccess({
          name: savedProduct?.name || form.name,
          sku: savedProduct?.sku || 'SKU generated automatically',
        })
        setHighlightedProductId(savedProduct?.id ?? null)
        window.setTimeout(() => setHighlightedProductId(null), 3200)
      }

      cancelEdit()
      await loadProducts(getActiveFilters())
    } catch (err) {
      if (err.errors) {
        const messages = Object.values(err.errors).flat().join(' ')
        setFormError(messages)
      } else {
        setFormError(editingId ? 'Could not update product.' : 'Could not save product.')
      }
    } finally {
      setSaving(false)
    }
  }

  async function handleDelete(product) {
    const confirmed = window.confirm(`Delete "${product.name}"? This cannot be undone.`)

    if (!confirmed) {
      return
    }

    try {
      await deleteProduct(product.id)
      if (editingId === product.id) {
        cancelEdit()
      }
      if (viewProductId === product.id) {
        setViewProductId(null)
      }
      await loadProducts(getActiveFilters())
    } catch {
      setError('Could not delete product.')
    }
  }

  const hasFilters =
    search.trim() !== '' ||
    categoryFilter !== '' ||
    supplierFilter !== '' ||
    lowStockOnly ||
    sortBy !== 'name' ||
    sortDirection !== 'asc'
  const editingProduct = editingId ? products.find((product) => product.id === editingId) : null
  const trackingModeLocked = Boolean(editingProduct && Number(editingProduct.quantity) > 0.0005)
  const trackedEdit = Boolean(editingId && form.tracking_mode !== 'none')
  const creating = !editingId
  const openingQuantity = Number(form.quantity || 0)

  return (
    <main className="products-page">
      <SuccessAnimation
        open={Boolean(productSuccess)}
        title={t('animations.productAddedTitle')}
        message={productSuccess ? t('animations.productAddedMessage').replace('{name}', productSuccess.name) : ''}
        reference={productSuccess?.sku}
        onClose={() => setProductSuccess(null)}
      />
      {viewProductId && (
        <ProductDetail productId={viewProductId} onClose={() => setViewProductId(null)} />
      )}

      <section className="card product-form-card" ref={formSectionRef}>
        <h2>{editingId ? 'Edit product' : 'Add product'}</h2>
        <form className="product-form" onSubmit={handleSubmit}>
          <label>
            Category
            <select name="category_id" value={form.category_id} onChange={handleChange} required>
              <option value="">Select a category</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Supplier
            <select name="supplier_id" value={form.supplier_id} onChange={handleChange}>
              <option value="">No supplier</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Name
            <input name="name" value={form.name} onChange={handleChange} required />
          </label>

          <label>
            <span>SKU <span className="field-optional">(optional)</span></span>
            <input name="sku" value={form.sku} onChange={handleChange} placeholder="Leave blank to generate automatically" />
          </label>

          <label>
            Warehouse section
            <select name="location_code" value={form.location_code} onChange={handleChange}>
              <option value="">No section assigned</option>
              {warehouseSections.map((section) => (
                <option key={section.id} value={sectionLocationKey(section.floor_level, section.code)}>
                  L{section.floor_level ?? 1} · {section.code} — {section.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Description
            <textarea
              name="description"
              value={form.description}
              onChange={handleChange}
              rows={3}
            />
          </label>

          <label>
            {t('productUnits.defaultWarehouse')}
            <select name="default_warehouse_id" value={form.default_warehouse_id} onChange={handleChange}>
              <option value="">{t('productUnits.useCompanyDefault')}</option>
              {warehouses.filter((warehouse) => warehouse.is_active).map((warehouse) => (
                <option key={warehouse.id} value={warehouse.id}>{warehouse.name} ({warehouse.code})</option>
              ))}
            </select>
          </label>

          <label>
            Product image <span className="field-optional">(optional)</span>
            <input
              name="image"
              type="file"
              accept="image/*"
              onChange={handleImageChange}
            />
            {form.image ? <small>{form.image.name}</small> : null}
          </label>

          <div className="form-row">
            <label>
              {t('productUnits.openingQuantity')}
              <input
                name="quantity"
                type="number"
                min="0"
                step={isMeterUnit(form.unit) ? '0.001' : '1'}
                value={form.quantity}
                onChange={handleChange}
                required={!trackedEdit}
                disabled={trackedEdit}
              />
              {trackedEdit ? <small className="field-hint">{t('productTracking.trackedQuantityHint')}</small> : null}
            </label>

            <label>
              Min quantity
              <input
                name="min_quantity"
                type="number"
                min="0"
                step={isMeterUnit(form.unit) ? '0.001' : '1'}
                value={form.min_quantity}
                onChange={handleChange}
                required
              />
            </label>

            <label>
              {t('productUnits.smallestUnit')}
              <input
                name="unit"
                value={form.unit}
                onChange={handleChange}
                placeholder="e.g. pcs, m, kg"
                required
              />
            </label>

            <label>
              High stock at
              <input
                name="high_stock_threshold"
                type="number"
                min="0"
                step={isMeterUnit(form.unit) ? '0.001' : '1'}
                value={form.high_stock_threshold}
                onChange={handleChange}
                placeholder="0 = auto"
              />
            </label>
          </div>

          <div className="form-row">
            <label>
              Purchase Price
              <input
                name="purchase_price"
                type="number"
                min="0"
                step="0.01"
                value={form.purchase_price}
                onChange={handleChange}
                placeholder="Cost price"
              />
            </label>

            <label>
              Selling Price
              <input
                name="selling_price"
                type="number"
                min="0"
                step="0.01"
                value={form.selling_price}
                onChange={handleChange}
                placeholder="Retail price"
                required
              />
            </label>
          </div>

          <fieldset className="inventory-settings-editor">
            <div className="inventory-settings-heading">
              <legend>{t('productTracking.title')}</legend>
              <small>{t('productTracking.help')}</small>
            </div>

            <div className="inventory-settings-grid">
              <label>
                {t('productTracking.mode')}
                <select
                  name="tracking_mode"
                  value={form.tracking_mode}
                  onChange={handleChange}
                  disabled={trackingModeLocked}
                >
                  <option value="none">{t('productTracking.none')}</option>
                  <option value="batch">{t('productTracking.batch')}</option>
                  <option value="serial">{t('productTracking.serial')}</option>
                  <option value="batch_expiry">{t('productTracking.batchExpiry')}</option>
                </select>
                {trackingModeLocked ? <small className="field-hint">{t('productTracking.modeLocked')}</small> : null}
              </label>

              {['batch', 'batch_expiry'].includes(form.tracking_mode) ? (
                <>
                  <label>
                    {t('productTracking.nearExpiryDays')}
                    <input name="near_expiry_days" type="number" min="0" max="3650" step="1" value={form.near_expiry_days} onChange={handleChange} />
                  </label>
                  <label className="inventory-settings-check">
                    <input name="fefo_enabled" type="checkbox" checked={form.fefo_enabled} onChange={handleChange} />
                    <span>{t('productTracking.fefo')}</span>
                  </label>
                </>
              ) : null}
            </div>

            {creating && openingQuantity > 0 && ['batch', 'batch_expiry'].includes(form.tracking_mode) ? (
              <div className="opening-trace-panel">
                <p>{t('productTracking.openingDetails')}</p>
                <div className="inventory-settings-grid">
                  <label>
                    {t('productTracking.lotNumber')}
                    <input name="opening_lot_number" value={form.opening_lot_number} onChange={handleChange} required />
                  </label>
                  <label>
                    {t('productTracking.supplierBatch')}
                    <input name="opening_supplier_batch" value={form.opening_supplier_batch} onChange={handleChange} />
                  </label>
                  <label>
                    {t('productTracking.manufacturedAt')}
                    <input name="opening_manufactured_at" type="date" value={form.opening_manufactured_at} onChange={handleChange} />
                  </label>
                  <label>
                    {t('productTracking.expiryAt')}
                    <input name="opening_expiry_at" type="date" value={form.opening_expiry_at} onChange={handleChange} required={form.tracking_mode === 'batch_expiry'} />
                  </label>
                </div>
              </div>
            ) : null}

            {creating && openingQuantity > 0 && form.tracking_mode === 'serial' ? (
              <label className="opening-serial-field">
                {t('productTracking.openingSerials')}
                <textarea
                  name="opening_serial_numbers"
                  value={form.opening_serial_numbers}
                  onChange={handleChange}
                  rows={5}
                  required
                  placeholder={t('productTracking.openingSerialsPlaceholder')}
                />
                <small className="field-hint">{t('productTracking.openingSerialsHint').replace('{{count}}', String(openingQuantity))}</small>
              </label>
            ) : null}
          </fieldset>

          <fieldset className="inventory-settings-editor">
            <div className="inventory-settings-heading">
              <legend>{t('productPlanning.title')}</legend>
              <small>{t('productPlanning.help')}</small>
            </div>
            <div className="inventory-settings-grid inventory-settings-grid-wide">
              <label>{t('productPlanning.safetyStock')}<input name="safety_stock" type="number" min="0" step={isMeterUnit(form.unit) ? '0.001' : '1'} value={form.safety_stock} onChange={handleChange} /></label>
              <label>{t('productPlanning.reorderPoint')}<input name="reorder_point" type="number" min="0" step={isMeterUnit(form.unit) ? '0.001' : '1'} value={form.reorder_point} onChange={handleChange} placeholder={t('productPlanning.automatic')} /></label>
              <label>{t('productPlanning.historyDays')}<input name="replenishment_history_days" type="number" min="1" max="3650" step="1" value={form.replenishment_history_days} onChange={handleChange} required /></label>
              <label>{t('productPlanning.reviewDays')}<input name="replenishment_review_days" type="number" min="1" max="365" step="1" value={form.replenishment_review_days} onChange={handleChange} required /></label>
              <label>{t('productPlanning.weight')}<input name="weight_kg" type="number" min="0" step="0.000001" value={form.weight_kg} onChange={handleChange} placeholder={t('productPlanning.optional')} /></label>
              <label>{t('productPlanning.volume')}<input name="volume_m3" type="number" min="0" step="0.000001" value={form.volume_m3} onChange={handleChange} placeholder={t('productPlanning.optional')} /></label>
            </div>
          </fieldset>

          <fieldset className="unit-conversion-editor">
            <div className="unit-conversion-heading">
              <div>
                <legend>{t('productUnits.title')}</legend>
                <small>{t('productUnits.help').replace('{unit}', form.unit || 'pcs')}</small>
              </div>
              <button type="button" className="secondary" onClick={() => setForm((current) => ({ ...current, unit_conversions: [...current.unit_conversions, { code: '', label: '', conversion_mode: 'fixed', factor_to_base: '', is_active: true }] }))}>
                {t('productUnits.add')}
              </button>
            </div>
            {form.unit_conversions.length === 0 ? <p className="field-hint">{t('productUnits.none')}</p> : null}
            {form.unit_conversions.map((row, index) => (
              <div className="unit-conversion-row" key={`${index}-${row.code}`}>
                <label>{t('productUnits.unitCode')}<input value={row.code} onChange={(event) => updateConversion(index, 'code', event.target.value.toLowerCase())} placeholder="box / roll" required /></label>
                <label>{t('productUnits.label')}<input value={row.label} onChange={(event) => updateConversion(index, 'label', event.target.value)} placeholder="Box of 65" /></label>
                <label>{t('productUnits.factor').replace('{unit}', form.unit || 'pcs')}<input type="number" min={isMeterUnit(form.unit) ? '0.001' : '1'} step={isMeterUnit(form.unit) ? '0.001' : '1'} value={row.factor_to_base} onChange={(event) => updateConversion(index, 'factor_to_base', event.target.value)} placeholder="100" required /></label>
                {Number(row.factor_to_base) > 0 && Number(form.quantity) >= 0 ? (
                  <p className="unit-conversion-preview">
                    {t('productUnits.stockPreview', {
                      quantity: formatQuantity(form.quantity, form.unit),
                      baseUnit: form.unit || 'pcs',
                      packs: Math.floor(Number(form.quantity || 0) / Number(row.factor_to_base)),
                      packUnit: row.code || t('productUnits.pack'),
                      remainder: formatQuantity(Number(form.quantity || 0) % Number(row.factor_to_base), form.unit),
                    })}
                  </p>
                ) : null}
                <button type="button" className="danger unit-remove" onClick={() => setForm((current) => ({ ...current, unit_conversions: current.unit_conversions.filter((_, rowIndex) => rowIndex !== index) }))}>{t('common.delete')}</button>
              </div>
            ))}
          </fieldset>

          {editingId && (
            <label>
              Reason for quantity change <span className="field-optional">(required only when quantity changes)</span>
              <input
                name="quantity_change_reason"
                value={form.quantity_change_reason}
                onChange={handleChange}
                placeholder="e.g. Physical recount, damaged stock correction"
              />
            </label>
          )}

          {formError && <p className="error">{formError}</p>}

          <div className="form-actions">
            <button type="submit" disabled={saving}>{saving ? 'Saving…' : (editingId ? 'Update product' : 'Save product')}</button>
            {editingId && (
              <button type="button" className="secondary" onClick={cancelEdit}>
                Cancel
              </button>
            )}
          </div>
        </form>
      </section>

      <section className="card">
        <div className="section-header">
          <h2>Product list</h2>
          <div className="section-header-actions">
            {!loading && pagination && (
              <p className="result-count">
                {pagination.total} product(s) - page {pagination.current_page} of{' '}
                {pagination.last_page}
              </p>
            )}
            <button type="button" className="secondary" onClick={handleExport}>
              Export CSV
            </button>
            <label className="secondary import-label">
              <Upload size={16} />
              Import CSV
              <input type="file" accept=".csv,text/csv" onChange={handleImport} hidden />
            </label>
          </div>
        </div>

        <div className="filters">
          <label>
            Search
            <input
              type="search"
              placeholder="Search by name or SKU"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </label>

          <label>
            Category
            <select
              value={categoryFilter}
              onChange={(event) => setCategoryFilter(event.target.value)}
            >
              <option value="">All categories</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Supplier
            <select
              value={supplierFilter}
              onChange={(event) => setSupplierFilter(event.target.value)}
            >
              <option value="">All suppliers</option>
              {suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </select>
          </label>

          <label className="filter-checkbox">
            <input
              type="checkbox"
              checked={lowStockOnly}
              onChange={(event) => setLowStockOnly(event.target.checked)}
            />
            Low stock only
          </label>

          <label>
            Sort by
            <select value={sortBy} onChange={(event) => setSortBy(event.target.value)}>
              <option value="name">Name</option>
              <option value="sku">SKU</option>
              <option value="quantity">Quantity</option>
              <option value="min_quantity">Min quantity</option>
              <option value="price">Price</option>
            </select>
          </label>

          <label>
            Order
            <select
              value={sortDirection}
              onChange={(event) => setSortDirection(event.target.value)}
            >
              <option value="asc">Ascending</option>
              <option value="desc">Descending</option>
            </select>
          </label>

          {hasFilters && (
            <button type="button" className="secondary" onClick={clearFilters}>
              Clear filters
            </button>
          )}
        </div>

        {loading && <p>Loading products...</p>}
        {error && <p className="error">{error}</p>}

        {!loading && !error && products.length === 0 && !hasFilters && (
          <p>No products yet. Add your first item above.</p>
        )}

        {!loading && !error && products.length === 0 && hasFilters && (
          <p>No products match your search or filter.</p>
        )}

        {!loading && products.length > 0 && (
          <>
            <table className="product-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Supplier</th>
                  <th>SKU</th>
                  <th>Section</th>
                  <th>Qty</th>
                  <th>Unit</th>
                  <th>Min</th>
                  <th>Selling price</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {products.map((product) => (
                  <tr key={product.id} className={`${editingId === product.id ? 'editing ' : ''}${highlightedProductId === product.id ? 'new-record-highlight' : ''}`}>
                    <td>{product.name}</td>
                    <td>{product.category?.name ?? '-'}</td>
                    <td>{product.supplier?.name ?? '-'}</td>
                    <td>{product.sku}</td>
                    <td>{product.location_code || '—'}</td>
                    <td>
                      <StockBadge
                        quantity={product.quantity}
                        minQuantity={product.min_quantity}
                        highStockThreshold={product.high_stock_threshold}
                      />
                      <span style={{ marginLeft: 8 }}>{formatQuantity(product.quantity, product.unit)}</span>
                    </td>
                    <td>{product.unit ?? 'pcs'}</td>
                    <td>{product.min_quantity}</td>
                    <td>${Number(product.selling_price ?? product.price ?? 0).toFixed(2)}</td>
                    <td className="actions">
                      <button
                        type="button"
                        className="secondary"
                        onClick={() => setViewProductId(product.id)}
                      >
                        View
                      </button>
                      <button type="button" className="secondary" onClick={() => startEdit(product)}>
                        Edit
                      </button>
                      {(userRole === 'admin' || userRole === 'manager') && (
                        <button
                          type="button"
                          className="danger"
                          onClick={() => handleDelete(product)}
                        >
                          Delete
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            {pagination && pagination.last_page > 1 && (
              <div className="pagination">
                <button
                  type="button"
                  className="secondary"
                  disabled={page <= 1}
                  onClick={() => setPage((current) => current - 1)}
                >
                  Previous
                </button>
                <span>
                  Page {pagination.current_page} of {pagination.last_page}
                </span>
                <button
                  type="button"
                  className="secondary"
                  disabled={page >= pagination.last_page}
                  onClick={() => setPage((current) => current + 1)}
                >
                  Next
                </button>
              </div>
            )}
          </>
        )}
      </section>

    </main>
  )
}

export default Products
