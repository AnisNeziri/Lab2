import { useCallback, useEffect, useMemo, useState } from 'react'
import { ClipboardCheck, ShieldAlert, BadgeCheck, FileWarning, Plus, X } from 'lucide-react'
import { getSuppliers } from '../api/suppliers'
import { getCategories } from '../api/categories'
import { getProducts } from '../api/products'
import {
  addDefect, configureQuality, correctInspection, createInspection, createSupplierClaim,
  finalizeInspection, getDefectCategories, getInspection, getInspections, getInspectionSources,
  getQualityDashboard, getQualityTemplates, getSupplierClaims, resolveSupplierClaim,
  saveQualityTemplate, updateQualityTemplate,
  uploadQualityAttachment, downloadQualityAttachment,
} from '../api/quality'
import './QualityManagement.css'
import {useSearchParams} from 'react-router-dom'
import EntityDocuments from '../components/EntityDocuments'

import { useAuthStore } from '../store/authStore'
import { useUiText } from '../hooks/useUiText'
import { createPortal } from 'react-dom'
import { useDialog } from '../hooks/useDialog'
import { useRevealTarget } from '../hooks/useRevealTarget'
import PageHeader from '../components/PageHeader'
const number = (value) => Number(value || 0)
const failure = (error) => Object.values(error?.errors || {}).flat().join(' ') || error?.message || 'The request could not be completed.'
const emptyDisposition = { accepted_quantity: '', rejected_quantity: '0', quarantine_quantity: '0', damaged_quantity: '0', decision: '', notes: '', results: [] }
const blankCheck = { name: '', check_type: 'pass_fail', is_required: true }
const blankTemplate = { name: '', description: '', items: [blankCheck] }
const blankInspection = { goods_receipt_item_id: '', inspected_quantity: '', inspection_scope: 'whole_receipt', quality_inspection_template_id: '', notes: '' }

export default function QualityManagement() {
  const tx = useUiText()
  const reveal = useRevealTarget()

  const permissions=useAuthStore(s=>s.permissions),can=p=>permissions.includes(p)
  const canViewClaims = can('quality.claims.view')
  const canStartInspection = can('quality.inspections.create')
  const [showInspectionForm, setShowInspectionForm] = useState(false)
  const [showTemplateForm, setShowTemplateForm] = useState(false)
  const [editingTemplate,setEditingTemplate]=useState(null)

  const [documentParams]=useSearchParams()
  useEffect(()=>{const id=Number(documentParams.get('inspection'));if(id)openInspection(id);if(documentParams.has('claim') && canViewClaims)setTab('claims')},[documentParams, canViewClaims])
  const [tab, setTab] = useState('inspections')
  const [dashboard, setDashboard] = useState(null)
  const [inspections, setInspections] = useState([])
  const [claims, setClaims] = useState([])
  const [templates, setTemplates] = useState([])
  const [categories, setCategories] = useState([])
  const [sources, setSources] = useState([])
  const [selected, setSelected] = useState(null)
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [notice, setNotice] = useState('')
  const [newInspection, setNewInspection] = useState(blankInspection)
  const [disposition, setDisposition] = useState(emptyDisposition)
  const [template, setTemplate] = useState(blankTemplate)
  const [claim, setClaim] = useState({ quality_inspection_id: '', supplier_id: '', requested_outcome: 'replacement_requested', expected_resolution_date: '', communication_notes: '' })

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError('')
    try {
      const [summary, inspectionData, claimData, templateData, categoryData, sourceData] = await Promise.all([
        getQualityDashboard(), getInspections({ per_page: 100 }), canViewClaims ? getSupplierClaims({ per_page: 100 }) : null,
        getQualityTemplates(), getDefectCategories(), canStartInspection ? getInspectionSources() : null,
      ])
      setDashboard(summary); setInspections(inspectionData.data || []); setClaims(claimData?.data || [])
      setTemplates(templateData || []); setCategories(categoryData || []); setSources(sourceData || [])
    } catch (error) {
      setLoadError(failure(error))
    } finally {
      setLoading(false)
    }
  }, [canViewClaims, canStartInspection])

  useEffect(() => { void load() }, [load])

  const receiptItems = useMemo(() => sources.flatMap(receipt => (receipt.items || [])
    .filter(item => !item.quality_inspection).map(item => ({ ...item, receipt }))), [sources])

  async function openInspection(id) {
    try {
      setBusy(true); const data = await getInspection(id); setSelected(data)
      setDisposition({ ...emptyDisposition, accepted_quantity: String(data.received_quantity || '') })
    } catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
  }

  async function submitInspection(event) {
    event.preventDefault(); setBusy(true); setNotice('')
    try {
      await createInspection({ ...newInspection, goods_receipt_item_id: Number(newInspection.goods_receipt_item_id), inspected_quantity: newInspection.inspected_quantity || undefined, quality_inspection_template_id: newInspection.quality_inspection_template_id || null })
      setNotice(tx('Inspection started. The stock is on hold.')); setNewInspection(blankInspection); setShowInspectionForm(false)
      reveal('quality-inspection-list'); await load()
    } catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
  }

  async function submitFinalization(event) {
    event.preventDefault(); setBusy(true); setNotice('')
    try {
      const results = (selected.template?.items || []).map((check) => {
        const existing = disposition.results.find((item) => item.quality_checklist_item_id === check.id) || {}
        return { quality_checklist_item_id: check.id, ...existing }
      })
      await finalizeInspection(selected.id, { ...disposition, results }); setSelected(null); setNotice('Inspection finalized and inventory states reconciled.'); await load()
    } catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
  }

  function setResult(check, field, value) {
    setDisposition((current) => ({ ...current, results: [...current.results.filter((item) => item.quality_checklist_item_id !== check.id), { ...(current.results.find((item) => item.quality_checklist_item_id === check.id) || {}), quality_checklist_item_id: check.id, [field]: value }] }))
  }

  async function submitTemplate(event) {
    event.preventDefault(); setBusy(true)
    try {
      await (editingTemplate ? updateQualityTemplate(editingTemplate,template) : saveQualityTemplate(template))
      setShowTemplateForm(false); setNotice(tx('Reusable checklist saved.'))
      reveal('quality-checklist-list'); await load()
    }
    catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
  }

  function openTemplate(item, editing = false) {
    setEditingTemplate(editing ? item.id : null)
    setTemplate(item ? { name: editing ? item.name : item.name + tx(' (copy)'), description: item.description || '', items: item.items, is_active: editing ? item.is_active : true } : blankTemplate)
    setShowTemplateForm(true)
    reveal('quality-template-form')
  }

  function updateCheck(index, changes) {
    setTemplate(current => ({ ...current, items: current.items.map((item, i) => i === index ? { ...item, ...changes } : item) }))
  }

  async function submitClaim(event) {
    event.preventDefault(); const inspection = inspections.find((item) => String(item.id) === String(claim.quality_inspection_id))
    if (!inspection) return setNotice('Choose an inspection.')
    const affected = number(inspection.rejected_quantity) + number(inspection.damaged_quantity) + number(inspection.quarantine_quantity)
    if (affected <= 0) return setNotice('This inspection has no rejected, damaged or quarantined quantity to claim.')
    setBusy(true)
    try {
      await createSupplierClaim({ ...claim, supplier_id: inspection.supplier_id, quality_inspection_id: inspection.id, purchase_order_id: inspection.purchase_order_id, goods_receipt_id: inspection.goods_receipt_id, expected_resolution_date: claim.expected_resolution_date || null, defect_ids: (inspection.defects || []).map((item) => item.id), currency: inspection.purchase_order?.currency || 'EUR', items: [{ product_id: inspection.product_id, goods_receipt_item_id: inspection.goods_receipt_item_id, affected_quantity: affected }] })
      setClaim({ quality_inspection_id: '', supplier_id: '', requested_outcome: 'replacement_requested', expected_resolution_date: '', communication_notes: '' }); setNotice('Supplier claim created.'); await load()
    } catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
  }

  return <main className="quality-page">
    <PageHeader title={tx('Quality Management')} description={tx('Inspect deliveries, hold problem stock and track supplier claims.')}/>
    {notice && <div className="quality-notice" role="status">{notice}<button type="button" aria-label={tx('Close')} onClick={() => setNotice('')}><X size={16}/></button></div>}
    {loading && <p role="status">{tx('Loading quality records…')}</p>}
    {loadError && <div className="quality-notice" role="alert"><span>{loadError}</span><button type="button" className="secondary" onClick={load}>{tx('Try again')}</button></div>}
    <nav className="quality-tabs" aria-label={tx('Quality Management')}>
      {[['inspections','Inspections'],['checklists','Checklists'],
        ...(canViewClaims ? [['claims','Supplier claims']] : []),
        ...(can('quality.templates.manage') ? [['configuration','Inspection rules']] : []),
        ['overview','Overview'],
      ].map(([id,label]) => <button type="button" key={id} aria-current={tab === id ? 'page' : undefined} className={tab === id ? 'active' : ''} onClick={() => setTab(id)}>{tx(label)}</button>)}
    </nav>

    {tab === 'overview' && <>
      <section className="quality-metrics">
        <Metric icon={ClipboardCheck} label={tx("Pending inspections")} value={dashboard?.pending_inspections}/>
        <Metric icon={ShieldAlert} label={tx("Quarantined quantity")} value={dashboard?.quarantined_quantity}/>
        <Metric icon={FileWarning} label={tx("Failed inspections")} value={dashboard?.failed_inspections}/>
        {canViewClaims && <Metric icon={BadgeCheck} label={tx("Open supplier claims")} value={dashboard?.open_claims}/>}
      </section>
      <div className="quality-actions"><button type="button" onClick={() => {setTab('inspections'); reveal('quality-inspection-list')}}>{tx('Open inspections')}</button></div>
      <section className="quality-grid"><article className="card"><h2>{tx("Frequent defect categories")}</h2>{dashboard?.worst_defect_categories?.length ? dashboard.worst_defect_categories.map((item) => <p className="quality-line" key={item.name}><span>{item.name}</span><strong>{item.defect_count} · {item.affected_quantity} {tx("affected")}</strong></p>) : <p>{tx("No defects in this period.")}</p>}</article><article className="card"><h2>{tx("Supplier risk")}</h2>{dashboard?.suppliers_highest_defect_rates?.length ? dashboard.suppliers_highest_defect_rates.map((item) => <p className="quality-line" key={item.supplier_id}><span>{item.supplier_name}</span><strong>{item.quality.defect_rate}{tx("% defect rate")}</strong></p>) : <p>{tx("Insufficient supplier quality history.")}</p>}</article></section>
    </>}

    {tab === 'inspections' && <section className="quality-workspace">
      {canStartInspection && showInspectionForm && <form id="quality-inspection-form" tabIndex={-1} className="card quality-form" onSubmit={submitInspection}>
        <div className="quality-panel-header"><div><h2>{tx('Start receipt inspection')}</h2><p>{tx('Starting an inspection puts the selected stock on hold.')}</p></div>
          <button type="button" className="secondary" disabled={busy} onClick={() => {setShowInspectionForm(false); reveal('quality-new-inspection')}}>{tx('Cancel')}</button>
        </div>
        <label>{tx('Receipt product')}<select required value={newInspection.goods_receipt_item_id} onChange={e => setNewInspection({ ...newInspection, goods_receipt_item_id: e.target.value })}>
          <option value="">{tx('Select received line')}</option>
          {receiptItems.map(item => <option value={item.id} key={item.id}>{item.receipt.receipt_number} · {item.product?.name} · {number(item.accepted_base_quantity) + number(item.damaged_base_quantity)} {item.inventory_unit}</option>)}
        </select></label>
        <details className="quality-extra" onInvalid={event => {event.currentTarget.open = true}}>
          <summary>{tx('Inspection options')}</summary>
          <div className="quality-fields">
            <label>{tx('Scope')}<select value={newInspection.inspection_scope} onChange={e => setNewInspection({ ...newInspection, inspection_scope: e.target.value, inspected_quantity: '' })}><option value="whole_receipt">{tx('Whole receipt')}</option><option value="sample">{tx('Sample')}</option></select></label>
            {newInspection.inspection_scope === 'sample' && <label>{tx('Sample quantity')}<input required type="number" min="0.001" step="0.001" value={newInspection.inspected_quantity} onChange={e => setNewInspection({ ...newInspection, inspected_quantity: e.target.value })}/></label>}
            <label>{tx('Checklist')}<select value={newInspection.quality_inspection_template_id} onChange={e => setNewInspection({ ...newInspection, quality_inspection_template_id: e.target.value })}><option value="">{tx('Use configured checklist')}</option>{templates.filter(item => item.is_active).map(item => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label>
          </div>
          <label>{tx('Notes')}<textarea value={newInspection.notes} onChange={e => setNewInspection({ ...newInspection, notes: e.target.value })}/></label>
        </details>
        <div className="quality-actions"><button disabled={busy}>{tx('Start inspection')}</button></div>
      </form>}
      <article className="card quality-table-card" id="quality-inspection-list" tabIndex={-1}>
        <div className="quality-panel-header"><div><h2>{tx('Receipt inspections')}</h2></div>
          {canStartInspection && !showInspectionForm && <button id="quality-new-inspection" type="button" disabled={loading || Boolean(loadError) || receiptItems.length === 0} onClick={() => {setShowInspectionForm(true); reveal('quality-inspection-form')}}><Plus size={16}/>{tx('Start inspection')}</button>}
        </div>
        {canStartInspection && !loading && !loadError && receiptItems.length === 0 && <p className="quality-empty">{tx('No uninspected items in recent receipts.')}</p>}
        {inspections.length > 0 ? <div className="table-wrap"><table><thead><tr><th>{tx('Inspection')}</th><th>{tx('Receipt / product')}</th><th>{tx('Supplier')}</th><th>{tx('Outcome')}</th><th></th></tr></thead><tbody>
          {[...inspections.filter(item => item.status === 'PENDING'), ...inspections.filter(item => item.status !== 'PENDING')].map(item => <tr key={item.id}>
            <td><strong>{item.inspection_number}</strong><small>{new Date(item.inspection_date).toLocaleDateString()}</small></td>
            <td>{item.goods_receipt?.receipt_number}<small>{item.product?.name}</small></td>
            <td>{item.supplier?.name || '—'}</td>
            <td><span className={`quality-status ${item.status.toLowerCase()}`}>{tx(item.status)}</span></td>
            <td><button className="secondary" onClick={() => openInspection(item.id)}>{tx('Open')}</button></td>
          </tr>)}
        </tbody></table></div> : !loading && !loadError && <p className="quality-empty">{tx('No inspections recorded yet.')}</p>}
      </article>
    </section>}

    {tab === 'checklists' && <section className="quality-workspace">
      {can('quality.templates.manage') && showTemplateForm && <form id="quality-template-form" tabIndex={-1} className="card quality-form" onSubmit={submitTemplate}>
        <div className="quality-panel-header">
          <div><h2>{tx(editingTemplate ? 'Edit checklist' : 'New checklist')}</h2><p>{tx('Each check is a question or measurement to record during an inspection.')}</p></div>
          <button type="button" className="secondary" disabled={busy} onClick={() => {setShowTemplateForm(false); reveal('quality-new-checklist')}}>{tx('Cancel')}</button>
        </div>
        <label>{tx('Name')}<input required value={template.name} onChange={e => setTemplate({ ...template, name: e.target.value })}/></label>
        <label>{tx('Description (optional)')}<textarea value={template.description} onChange={e => setTemplate({ ...template, description: e.target.value })}/></label>
        {template.items.map((item,index) => <div className="check-editor" key={index}>
          <label>{tx('Check name')}<input id={'quality-check-name-' + index} required placeholder={tx('Check name')} value={item.name} onChange={e => updateCheck(index,{name:e.target.value})}/></label>
          <label>{tx('Answer type')}<select value={item.check_type} onChange={e => updateCheck(index,{check_type:e.target.value})}><option value="pass_fail">{tx('Pass / fail')}</option><option value="numeric">{tx('Measurement')}</option><option value="text">{tx('Text result')}</option></select></label>
          <div className="quality-check-actions">
            <label className="inline-check"><input type="checkbox" checked={item.is_required} onChange={e => updateCheck(index,{is_required:e.target.checked})}/>{tx('Required')}</label>
            {template.items.length > 1 && <button type="button" className="secondary quality-remove-check" aria-label={tx('Remove check') + ' ' + (index + 1)} onClick={() => setTemplate(current => ({...current,items:current.items.filter((_,i) => i !== index)}))}><X size={16}/></button>}
          </div>
        </div>)}
        <div className="quality-actions">
          <button type="button" className="secondary" onClick={() => {setTemplate(current => ({...current,items:[...current.items,blankCheck]})); reveal('quality-check-name-' + template.items.length)}}><Plus size={16}/>{tx('Add check')}</button>
          <button disabled={busy}>{tx('Save checklist')}</button>
        </div>
      </form>}
      <article className="card" id="quality-checklist-list" tabIndex={-1}>
        <div className="quality-panel-header">
          <div><h2>{tx('Available checklists')}</h2><p>{tx('Reuse the same checks for similar deliveries.')}</p></div>
          {can('quality.templates.manage') && !showTemplateForm && <button id="quality-new-checklist" type="button" onClick={() => openTemplate()}><Plus size={16}/>{tx('New checklist')}</button>}
        </div>
        {!loading && !loadError && templates.length === 0 && <p className="quality-empty">{tx('No checklists yet.')}</p>}
        {templates.map(item => <div className="template-summary" key={item.id}>
          <strong>{item.name}</strong>
          <span>{item.items?.length || 0} {tx('checks ·')} {item.is_active ? tx('Active') : tx('Inactive')}</span>
          {item.description && <p>{item.description}</p>}
          {can('quality.templates.manage') && <div className="quality-actions">
            <button type="button" className="secondary" onClick={() => openTemplate(item,true)}>{tx('Edit')}</button>
            <button type="button" className="secondary" onClick={() => openTemplate(item)}>{tx('Create copy')}</button>
            <button type="button" disabled={busy} className="secondary" onClick={async () => {
              setBusy(true)
              try { await updateQualityTemplate(item.id,{...item,is_active:!item.is_active}); await load() }
              catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
            }}>{tx(item.is_active ? 'Archive' : 'Restore')}</button>
          </div>}
        </div>)}
      </article>
    </section>}

    {tab === 'claims' && <section className="quality-grid"><form className="card quality-form" style={!can("quality.claims.create")?{display:"none"}:undefined} onSubmit={submitClaim}><h2>{tx("Open supplier claim")}</h2><label>{tx("Inspection")}<select required value={claim.quality_inspection_id} onChange={(e) => setClaim({ ...claim, quality_inspection_id: e.target.value })}><option value="">{tx("Select finalized inspection")}</option>{inspections.filter((item) => item.status !== 'PENDING' && (number(item.rejected_quantity) + number(item.damaged_quantity) + number(item.quarantine_quantity)) > 0).map((item) => <option value={item.id} key={item.id}>{item.inspection_number} · {item.supplier?.name} · {item.product?.name}</option>)}</select></label><label>{tx("Requested outcome")}<select value={claim.requested_outcome} onChange={(e) => setClaim({ ...claim, requested_outcome: e.target.value })}><option value="replacement_requested">{tx("Replacement")}</option><option value="refund">{tx("Refund")}</option><option value="supplier_credit">{tx("Supplier credit")}</option><option value="price_reduction">{tx("Price reduction")}</option><option value="goods_returned">{tx("Return goods")}</option></select></label><label>{tx("Expected resolution")}<input type="date" value={claim.expected_resolution_date} onChange={(e) => setClaim({ ...claim, expected_resolution_date: e.target.value })}/></label><label>{tx("Communication notes")}<textarea value={claim.communication_notes} onChange={(e) => setClaim({ ...claim, communication_notes: e.target.value })}/></label><button disabled={busy}>{tx("Create claim")}</button></form><article className="card quality-table-card"><h2>{tx("Supplier claims")}</h2>{claims.map((item) => <div className="claim-row" key={item.id}><EntityDocuments compact entityType="supplier-claim" entityId={item.id}/><div><strong>{item.claim_number} · {item.supplier?.name}</strong><small>{item.requested_outcome.replaceAll('_', ' ')} · {item.currency} {item.affected_value}</small></div><span className={`quality-status ${item.status.toLowerCase()}`}>{tx(item.status)}</span>{can('quality.claims.resolve') && item.status !== 'RESOLVED' && <button className="secondary" disabled={busy} onClick={async () => { const resolution = window.prompt('Resolution: replacement_agreed, refund, supplier_credit, price_reduction, goods_returned, claim_rejected or resolved', 'resolved'); if (!resolution) return; try { setBusy(true); await resolveSupplierClaim(item.id, { actual_resolution: resolution, resolution_notes: 'Resolved from Quality Management.' }); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }}>{tx("Resolve")}</button>}</div>)}</article></section>}

    {tab === 'configuration' && can('quality.templates.manage') && <Configuration templates={templates} onSaved={load} setNotice={setNotice}/>}
    {selected && <InspectionModal canFinalize={can('quality.inspections.finalize')} canCorrect={can('quality.override')} canCreate={can('quality.inspections.create')} inspection={selected} disposition={disposition} setDisposition={setDisposition} setResult={setResult} onClose={() => setSelected(null)} onFinalize={submitFinalization} busy={busy} categories={categories} onDefect={async (payload) => { try { setBusy(true); await addDefect(selected.id, payload); await openInspection(selected.id); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }} onAttachment={async (file) => { try { setBusy(true); const data = new FormData(); data.append('quality_inspection_id', selected.id); data.append('document_type', 'evidence'); data.append('file', file); await uploadQualityAttachment(data); await openInspection(selected.id) } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }} onCorrection={async () => { const reason = window.prompt('Reason for correction'); if (!reason) return; try { setBusy(true); const revision = await correctInspection(selected.id, reason); setSelected(revision); setDisposition({ ...emptyDisposition, accepted_quantity: String(revision.received_quantity) }); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }}/>}
  </main>
}

function Metric({ icon: Icon, label, value }) { return <article className="quality-metric"><Icon size={22}/><span>{label}</span><strong>{value ?? '—'}</strong></article> }

function Configuration({ templates, onSaved, setNotice }) {
  const tx = useUiText()
  const [form, setForm] = useState({ entity_type: 'product', entity_id: '', quality_inspection_mode: 'optional', quality_inspection_template_id: '' })
  const [options, setOptions] = useState([])
  const [search, setSearch] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  useEffect(() => {
    let current = true
    const timer = setTimeout(async () => {
      try {
        const records = await (form.entity_type === 'product'
          ? getProducts({ per_page: 50, sort: 'name', reference_only: true, search })
          : form.entity_type === 'category' ? getCategories() : getSuppliers())
        if (current) { setOptions(records.data || records); setError('') }
      } catch (problem) {
        if (current) setError(failure(problem))
      } finally {
        if (current) setLoading(false)
      }
    }, search ? 250 : 0)
    return () => { current = false; clearTimeout(timer) }
  }, [form.entity_type, search])

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    try {
      await configureQuality({ ...form, entity_id: Number(form.entity_id), quality_inspection_template_id: form.quality_inspection_template_id || null })
      setNotice(tx('Incoming inspection rule saved.'))
      await onSaved()
    } catch (problem) {
      setNotice(failure(problem))
    } finally {
      setSaving(false)
    }
  }

  function reloadOptions(changes) {
    setForm(current => ({...current, ...changes, entity_id: ''}))
    setOptions([])
    setError('')
    setLoading(true)
  }

  return <form className="card quality-form" onSubmit={save}>
    <h2>{tx('Incoming inspection rule')}</h2>
    <div className="quality-fields">
      <label>{tx('Apply to')}<select value={form.entity_type} onChange={e => {setSearch(''); reloadOptions({entity_type: e.target.value})}}><option value="product">{tx('Product')}</option><option value="category">{tx('Category')}</option><option value="supplier">{tx('Supplier')}</option></select></label>
      {form.entity_type === 'product' && <label>{tx('Find a product')}<input type="search" value={search} onChange={e => {setSearch(e.target.value); reloadOptions({})}}/></label>}
      <label>{tx('Record')}<select required disabled={loading || Boolean(error)} value={form.entity_id} onChange={e => setForm({ ...form, entity_id: e.target.value })}><option value="">{tx(loading ? 'Loading…' : 'Select')}</option>{options.map(item => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label>
      <label>{tx('Inspection requirement')}<select value={form.quality_inspection_mode} onChange={e => setForm({ ...form, quality_inspection_mode: e.target.value })}><option value="not_required">{tx('Not required')}</option><option value="optional">{tx('Optional')}</option><option value="required">{tx('Required before availability')}</option></select></label>
      <label>{tx('Checklist')}<select value={form.quality_inspection_template_id} onChange={e => setForm({ ...form, quality_inspection_template_id: e.target.value })}><option value="">{tx('No default checklist')}</option>{templates.filter(item => item.is_active).map(item => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label>
    </div>
    {error && <p role="alert">{error}</p>}
    <p>{tx('Required inspections hold stock until someone records the outcome.')}</p>
    <div className="quality-actions"><button disabled={loading || saving || !form.entity_id}>{tx('Save rule')}</button></div>
  </form>
}


function InspectionModal({ canFinalize, canCorrect, canCreate, inspection, disposition, setDisposition, setResult, onClose, onFinalize, busy, categories, onDefect, onCorrection, onAttachment }) {
 const tx = useUiText()

  const [defect, setDefect] = useState({ quality_defect_category_id: '', severity: 'MINOR', affected_quantity: '', description: '' })
  const dialogRef=useDialog(onClose,busy)
  const finalized = Boolean(inspection.finalized_at)
  return createPortal(<div className="quality-modal-backdrop"><section ref={dialogRef} className="quality-modal" role="dialog" aria-modal="true" aria-label={inspection.inspection_number}><button className="quality-close" disabled={busy} aria-label={tx("Close")} onClick={onClose}><X/></button><header><span>{inspection.inspection_number}</span><h2>{inspection.product?.name}</h2><p>{inspection.goods_receipt?.receipt_number} · {inspection.supplier?.name}</p></header><div className="inspection-facts"><span>{tx("Received")} <strong>{inspection.received_quantity}</strong></span><span>{tx("Sample")} <strong>{inspection.inspected_quantity}</strong></span><span>{tx("Status")} <strong>{tx(inspection.status)}</strong></span></div>{!finalized && <form onSubmit={onFinalize} className="quality-form"><h3>{tx("Checklist")}</h3>{inspection.template?.items?.map((check) => <label key={check.id}>{check.name}{check.check_type === 'pass_fail' ? <select required={check.is_required} onChange={(e) => setResult(check, 'passed', e.target.value === 'true')} defaultValue=""><option value="">{tx("Select")}</option><option value="true">{tx("Pass")}</option><option value="false">{tx("Fail")}</option></select> : check.check_type === 'numeric' ? <input required={check.is_required} type="number" step="any" onChange={(e) => setResult(check, 'numeric_value', e.target.value)}/> : <input required={check.is_required} onChange={(e) => setResult(check, 'text_value', e.target.value)}/>}</label>)}<h3>{tx("Disposition (must equal")} {inspection.received_quantity})</h3><div className="disposition-grid">{['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'].map((field) => <label key={field}>{tx(field.replace('_quantity',''))}<input required type="number" min="0" step="0.001" value={disposition[field]} onChange={(e) => setDisposition({ ...disposition, [field]: e.target.value })}/></label>)}</div><label>{tx("Decision notes")}<textarea value={disposition.notes} onChange={(e) => setDisposition({ ...disposition, notes: e.target.value })}/></label><button disabled={busy||!canFinalize}>{tx("Finalize inspection")}</button></form>}{finalized && <><section className="finalized-summary"><h3>{tx("Finalized decision")}</h3><div className="disposition-grid">{['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'].map((field) => <span key={field}>{tx(field.replace('_quantity',''))}<strong>{inspection[field]}</strong></span>)}</div><button className="secondary" disabled={busy||!canCorrect} onClick={onCorrection}>{tx("Create correction revision")}</button></section><form className="quality-form" onSubmit={(e) => { e.preventDefault(); onDefect({ ...defect, quality_defect_category_id: defect.quality_defect_category_id || null }) }}><h3>{tx("Record defect")}</h3><label>{tx("Category")}<select value={defect.quality_defect_category_id} onChange={(e) => setDefect({ ...defect, quality_defect_category_id: e.target.value })}><option value="">{tx("Uncategorized")}</option>{categories.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><div className="form-row"><label>{tx("Severity")}<select value={defect.severity} onChange={(e) => setDefect({ ...defect, severity: e.target.value })}><option value="MINOR">{tx("MINOR")}</option><option value="MAJOR">{tx("MAJOR")}</option><option value="CRITICAL">{tx("CRITICAL")}</option></select></label><label>{tx("Affected quantity")}<input required type="number" min="0.001" step="0.001" value={defect.affected_quantity} onChange={(e) => setDefect({ ...defect, affected_quantity: e.target.value })}/></label></div><label>{tx("Description")}<textarea required value={defect.description} onChange={(e) => setDefect({ ...defect, description: e.target.value })}/></label><button disabled={busy||!canCreate}>{tx("Record defect")}</button></form></>}<section className="quality-form"><h3>{tx("Evidence")}</h3><EntityDocuments entityType="quality-inspection" entityId={inspection.id}/>{inspection.attachments?.map((item) => <button type="button" className="secondary" key={item.id} onClick={() => downloadQualityAttachment(item)}>{item.filename}</button>)}<label>{tx("Attach photo, certificate or report")}<input type="file" accept="image/*,.pdf,.xlsx,.csv,.txt,.docx" disabled={busy||!canCreate} onChange={(e) => e.target.files?.[0] && onAttachment(e.target.files[0])}/></label></section></section></div>, document.body)
}
