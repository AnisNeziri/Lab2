import { useCallback, useEffect, useMemo, useState } from 'react'
import { ClipboardCheck, ShieldAlert, BadgeCheck, FileWarning, Plus, X } from 'lucide-react'
import { getSuppliers } from '../api/suppliers'
import { getCategories } from '../api/categories'
import { getProducts } from '../api/products'
import {
  addDefect, configureQuality, correctInspection, createInspection, createSupplierClaim,
  finalizeInspection, getDefectCategories, getInspection, getInspections, getInspectionSources,
  getQualityDashboard, getQualityTemplates, getSupplierClaims, resolveSupplierClaim,
  saveDefectCategory, saveQualityTemplate, updateQualityTemplate,
  uploadQualityAttachment, downloadQualityAttachment,
} from '../api/quality'
import './QualityManagement.css'
import {useSearchParams} from 'react-router-dom'
import EntityDocuments from '../components/EntityDocuments'

import { useAuthStore } from '../store/authStore'
import { useUiText } from '../hooks/useUiText'
import { createPortal } from 'react-dom'
import { useDialog } from '../hooks/useDialog'
const number = (value) => Number(value || 0)
const failure = (error) => Object.values(error?.errors || {}).flat().join(' ') || error?.message || 'The request could not be completed.'
const emptyDisposition = { accepted_quantity: '', rejected_quantity: '0', quarantine_quantity: '0', damaged_quantity: '0', decision: '', notes: '', results: [] }

export default function QualityManagement() {
 const tx = useUiText()

  const permissions=useAuthStore(s=>s.permissions),can=p=>permissions.includes(p);
  const [editingTemplate,setEditingTemplate]=useState(null);

  const [documentParams]=useSearchParams()
  useEffect(()=>{const id=Number(documentParams.get('inspection'));if(id)openInspection(id);if(documentParams.has('claim'))setTab('claims')},[documentParams])
  const [tab, setTab] = useState('overview')
  const [dashboard, setDashboard] = useState(null)
  const [inspections, setInspections] = useState([])
  const [claims, setClaims] = useState([])
  const [templates, setTemplates] = useState([])
  const [categories, setCategories] = useState([])
  const [companyCategories, setCompanyCategories] = useState([])
  const [companyProducts, setCompanyProducts] = useState([])
  const [sources, setSources] = useState([])
  const [suppliers, setSuppliers] = useState([])
  const [selected, setSelected] = useState(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [newInspection, setNewInspection] = useState({ goods_receipt_item_id: '', inspected_quantity: '', inspection_scope: 'whole_receipt', quality_inspection_template_id: '', notes: '' })
  const [disposition, setDisposition] = useState(emptyDisposition)
  const [template, setTemplate] = useState({ name: '', description: '', items: [{ name: '', check_type: 'pass_fail', is_required: true }] })
  const [claim, setClaim] = useState({ quality_inspection_id: '', supplier_id: '', requested_outcome: 'replacement_requested', expected_resolution_date: '', communication_notes: '' })

  const load = useCallback(async () => {
    const [summary, inspectionData, claimData, templateData, categoryData, sourceData, supplierData, companyCategoryData, productData] = await Promise.all([
      getQualityDashboard(), getInspections({ per_page: 100 }), can('quality.claims.view')?getSupplierClaims({ per_page: 100 }):Promise.resolve({data:[]}),
      getQualityTemplates(), getDefectCategories(), getInspectionSources(), getSuppliers(), getCategories(), getProducts({ per_page: 50, sort: 'name' }),
    ])
    setDashboard(summary); setInspections(inspectionData.data || []); setClaims(claimData.data || [])
    setTemplates(templateData || []); setCategories(categoryData || []); setSources(sourceData || []); setSuppliers(supplierData || []); setCompanyCategories(companyCategoryData || []); setCompanyProducts(productData.data || [])
  }, [])

  useEffect(() => { load().catch((error) => setNotice(failure(error))) }, [load])

  const receiptItems = useMemo(() => sources.flatMap((receipt) => (receipt.items || []).map((item) => ({ ...item, receipt }))), [sources])

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
      setNotice('Inspection created and the receipt stock is safely quarantined.'); setNewInspection({ goods_receipt_item_id: '', inspected_quantity: '', inspection_scope: 'whole_receipt', quality_inspection_template_id: '', notes: '' }); await load()
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
    try { await (editingTemplate?updateQualityTemplate(editingTemplate,template):saveQualityTemplate(template)); setEditingTemplate(null); setTemplate({ name: '', description: '', items: [{ name: '', check_type: 'pass_fail', is_required: true }] }); setNotice(tx('Reusable checklist saved.')); await load() }
    catch (error) { setNotice(failure(error)) } finally { setBusy(false) }
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
    <header className="quality-hero"><div><span>{tx("Incoming quality control")}</span><h1>{tx("Quality Management")}</h1><p>{tx("Inspect receipts, preserve lot traceability, control quarantine and hold suppliers accountable.")}</p></div><ClipboardCheck size={46}/></header>
    {notice && <div className="quality-notice" role="status">{notice}<button onClick={() => setNotice('')}><X size={16}/></button></div>}
    <nav className="quality-tabs">{['overview','inspections','checklists',...(can('quality.claims.view')?['claims']:[]),...(can('quality.templates.manage')?['configuration']:[])].map((item) => <button key={item} className={tab === item ? 'active' : ''} onClick={() => setTab(item)}>{tx(item)}</button>)}</nav>

    {tab === 'overview' && <>
      <section className="quality-metrics">
        <Metric icon={ClipboardCheck} label={tx("Pending inspections")} value={dashboard?.pending_inspections}/>
        <Metric icon={ShieldAlert} label={tx("Quarantined quantity")} value={dashboard?.quarantined_quantity}/>
        <Metric icon={FileWarning} label={tx("Failed inspections")} value={dashboard?.failed_inspections}/>
        <Metric icon={BadgeCheck} label={tx("Open supplier claims")} value={dashboard?.open_claims}/>
      </section>
      <section className="quality-grid"><article className="card"><h2>{tx("Frequent defect categories")}</h2>{dashboard?.worst_defect_categories?.length ? dashboard.worst_defect_categories.map((item) => <p className="quality-line" key={item.name}><span>{item.name}</span><strong>{item.defect_count} · {item.affected_quantity} {tx("affected")}</strong></p>) : <p>{tx("No defects in this period.")}</p>}</article><article className="card"><h2>{tx("Supplier risk")}</h2>{dashboard?.suppliers_highest_defect_rates?.length ? dashboard.suppliers_highest_defect_rates.map((item) => <p className="quality-line" key={item.supplier_id}><span>{item.supplier_name}</span><strong>{item.quality.defect_rate}{tx("% defect rate")}</strong></p>) : <p>{tx("Insufficient supplier quality history.")}</p>}</article></section>
    </>}

    {tab === 'inspections' && <section className="quality-grid">
      <form className="card quality-form" style={!can("quality.inspections.create")?{display:"none"}:undefined} onSubmit={submitInspection}><h2>{tx("Start receipt inspection")}</h2><label>{tx("Receipt product")}<select required value={newInspection.goods_receipt_item_id} onChange={(e) => setNewInspection({ ...newInspection, goods_receipt_item_id: e.target.value })}><option value="">{tx("Select received line")}</option>{receiptItems.filter((item) => !item.quality_inspection).map((item) => <option value={item.id} key={item.id}>{item.receipt.receipt_number} · {item.product?.name} · {number(item.accepted_base_quantity) + number(item.damaged_base_quantity)} {item.inventory_unit}</option>)}</select></label><div className="form-row"><label>{tx("Scope")}<select value={newInspection.inspection_scope} onChange={(e) => setNewInspection({ ...newInspection, inspection_scope: e.target.value })}><option value="whole_receipt">{tx("Whole receipt")}</option><option value="sample">{tx("Sample")}</option></select></label><label>{tx("Sample quantity")}<input type="number" min="0.001" step="0.001" value={newInspection.inspected_quantity} onChange={(e) => setNewInspection({ ...newInspection, inspected_quantity: e.target.value })}/></label></div><label>{tx("Checklist")}<select value={newInspection.quality_inspection_template_id} onChange={(e) => setNewInspection({ ...newInspection, quality_inspection_template_id: e.target.value })}><option value="">{tx("Use configured checklist")}</option>{templates.filter((item) => item.is_active).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><label>{tx("Notes")}<textarea value={newInspection.notes} onChange={(e) => setNewInspection({ ...newInspection, notes: e.target.value })}/></label><button disabled={busy}>{tx("Create inspection")}</button></form>
      <article className="card quality-table-card"><h2>{tx("Inspection history")}</h2><div className="table-wrap"><table><thead><tr><th>{tx("Inspection")}</th><th>{tx("Receipt / product")}</th><th>{tx("Supplier")}</th><th>{tx("Outcome")}</th><th></th></tr></thead><tbody>{inspections.map((item) => <tr key={item.id}><td><strong>{item.inspection_number}</strong><small>{new Date(item.inspection_date).toLocaleDateString()}</small></td><td>{item.goods_receipt?.receipt_number}<small>{item.product?.name}</small></td><td>{item.supplier?.name || '—'}</td><td><span className={`quality-status ${item.status.toLowerCase()}`}>{tx(item.status)}</span></td><td><button className="secondary" onClick={() => openInspection(item.id)}>{tx("Open")}</button></td></tr>)}</tbody></table></div></article>
    </section>}

    {tab === 'checklists' && <section className="quality-grid"><form id="quality-template-form" className="card quality-form" style={!can("quality.templates.manage")?{display:"none"}:undefined} onSubmit={submitTemplate}><h2>{tx("New reusable checklist")}</h2><label>{tx("Name")}<input required value={template.name} onChange={(e) => setTemplate({ ...template, name: e.target.value })}/></label><label>{tx("Description")}<textarea value={template.description} onChange={(e) => setTemplate({ ...template, description: e.target.value })}/></label>{template.items.map((item, index) => <div className="check-editor" key={index}><input required placeholder={tx("Check name")} value={item.name} onChange={(e) => setTemplate({ ...template, items: template.items.map((row, i) => i === index ? { ...row, name: e.target.value } : row) })}/><select value={item.check_type} onChange={(e) => setTemplate({ ...template, items: template.items.map((row, i) => i === index ? { ...row, check_type: e.target.value } : row) })}><option value="pass_fail">{tx("Pass / fail")}</option><option value="numeric">{tx("Measurement")}</option><option value="text">{tx("Text result")}</option></select><label className="inline-check"><input type="checkbox" checked={item.is_required} onChange={(e) => setTemplate({ ...template, items: template.items.map((row, i) => i === index ? { ...row, is_required: e.target.checked } : row) })}/>{tx("Required")}</label></div>)}<div className="form-actions"><button type="button" className="secondary" onClick={() => setTemplate({ ...template, items: [...template.items, { name: '', check_type: 'pass_fail', is_required: true }] })}><Plus size={16}/> {tx("Add check")}</button><button disabled={busy}>{tx("Save checklist")}</button></div></form><article className="card"><h2>{tx("Available checklists")}</h2>{templates.map((item) => <div className="template-summary" key={item.id}><strong>{item.name}</strong><span>{item.items?.length || 0} {tx("checks ·")} {item.is_active ? tx("Active") : tx("Inactive")}</span><p>{item.description}</p>{can('quality.templates.manage')&&<div className="form-actions"><button type="button" className="secondary" onClick={()=>{setEditingTemplate(item.id);setTemplate({...item});document.getElementById('quality-template-form')?.scrollIntoView({behavior:'smooth',block:'start'})}}>{tx('Edit')}</button><button type="button" className="secondary" onClick={()=>{setEditingTemplate(null);setTemplate({...item,name:item.name+' (copy)'});document.getElementById('quality-template-form')?.scrollIntoView({behavior:'smooth',block:'start'})}}>{tx('Create copy')}</button><button type="button" disabled={busy} className="secondary" onClick={async()=>{setBusy(true);try{await updateQualityTemplate(item.id,{...item,is_active:!item.is_active});await load()}catch(error){setNotice(failure(error))}finally{setBusy(false)}}}>{tx(item.is_active?tx("Archive"):tx("Restore"))}</button></div>}</div>)}</article></section>}

    {tab === 'claims' && <section className="quality-grid"><form className="card quality-form" style={!can("quality.claims.create")?{display:"none"}:undefined} onSubmit={submitClaim}><h2>{tx("Open supplier claim")}</h2><label>{tx("Inspection")}<select required value={claim.quality_inspection_id} onChange={(e) => setClaim({ ...claim, quality_inspection_id: e.target.value })}><option value="">{tx("Select finalized inspection")}</option>{inspections.filter((item) => item.status !== 'PENDING' && (number(item.rejected_quantity) + number(item.damaged_quantity) + number(item.quarantine_quantity)) > 0).map((item) => <option value={item.id} key={item.id}>{item.inspection_number} · {item.supplier?.name} · {item.product?.name}</option>)}</select></label><label>{tx("Requested outcome")}<select value={claim.requested_outcome} onChange={(e) => setClaim({ ...claim, requested_outcome: e.target.value })}><option value="replacement_requested">{tx("Replacement")}</option><option value="refund">{tx("Refund")}</option><option value="supplier_credit">{tx("Supplier credit")}</option><option value="price_reduction">{tx("Price reduction")}</option><option value="goods_returned">{tx("Return goods")}</option></select></label><label>{tx("Expected resolution")}<input type="date" value={claim.expected_resolution_date} onChange={(e) => setClaim({ ...claim, expected_resolution_date: e.target.value })}/></label><label>{tx("Communication notes")}<textarea value={claim.communication_notes} onChange={(e) => setClaim({ ...claim, communication_notes: e.target.value })}/></label><button disabled={busy}>{tx("Create claim")}</button></form><article className="card quality-table-card"><h2>{tx("Supplier claims")}</h2>{claims.map((item) => <div className="claim-row" key={item.id}><EntityDocuments compact entityType="supplier-claim" entityId={item.id}/><div><strong>{item.claim_number} · {item.supplier?.name}</strong><small>{item.requested_outcome.replaceAll('_', ' ')} · {item.currency} {item.affected_value}</small></div><span className={`quality-status ${item.status.toLowerCase()}`}>{tx(item.status)}</span>{can('quality.claims.resolve') && item.status !== 'RESOLVED' && <button className="secondary" disabled={busy} onClick={async () => { const resolution = window.prompt('Resolution: replacement_agreed, refund, supplier_credit, price_reduction, goods_returned, claim_rejected or resolved', 'resolved'); if (!resolution) return; try { setBusy(true); await resolveSupplierClaim(item.id, { actual_resolution: resolution, resolution_notes: 'Resolved from Quality Management.' }); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }}>{tx("Resolve")}</button>}</div>)}</article></section>}

    {tab === 'configuration' && <Configuration products={companyProducts} suppliers={suppliers} templates={templates} categories={companyCategories} onSaved={load} setNotice={setNotice}/>}
    {selected && <InspectionModal canFinalize={can('quality.inspections.finalize')} canCorrect={can('quality.override')} canCreate={can('quality.inspections.create')} inspection={selected} disposition={disposition} setDisposition={setDisposition} setResult={setResult} onClose={() => setSelected(null)} onFinalize={submitFinalization} busy={busy} categories={categories} onDefect={async (payload) => { try { setBusy(true); await addDefect(selected.id, payload); await openInspection(selected.id); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }} onAttachment={async (file) => { try { setBusy(true); const data = new FormData(); data.append('quality_inspection_id', selected.id); data.append('document_type', 'evidence'); data.append('file', file); await uploadQualityAttachment(data); await openInspection(selected.id) } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }} onCorrection={async () => { const reason = window.prompt('Reason for correction'); if (!reason) return; try { setBusy(true); const revision = await correctInspection(selected.id, reason); setSelected(revision); setDisposition({ ...emptyDisposition, accepted_quantity: String(revision.received_quantity) }); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }}/>}
  </main>
}

function Metric({ icon: Icon, label, value }) { return <article className="quality-metric"><Icon size={22}/><span>{label}</span><strong>{value ?? '—'}</strong></article> }

function Configuration({ products, suppliers, templates, categories, onSaved, setNotice }) {
 const tx = useUiText()

  const [form, setForm] = useState({ entity_type: 'product', entity_id: '', quality_inspection_mode: 'optional', quality_inspection_template_id: '' })
  const options = form.entity_type === 'product' ? products : (form.entity_type === 'category' ? categories : suppliers)
  return <section className="quality-grid"><form className="card quality-form" onSubmit={async (e) => { e.preventDefault(); try { await configureQuality({ ...form, entity_id: Number(form.entity_id), quality_inspection_template_id: form.quality_inspection_template_id || null }); setNotice('Incoming inspection rule saved.'); await onSaved() } catch (error) { setNotice(failure(error)) } }}><h2>{tx("Incoming inspection rule")}</h2><label>{tx("Apply to")}<select value={form.entity_type} onChange={(e) => setForm({ ...form, entity_type: e.target.value, entity_id: '' })}><option value="product">{tx("Product")}</option><option value="category">{tx("Category")}</option><option value="supplier">{tx("Supplier")}</option></select></label><label>{tx("Record")}<select required value={form.entity_id} onChange={(e) => setForm({ ...form, entity_id: e.target.value })}><option value="">{tx("Select")}</option>{options.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><label>{tx("Inspection requirement")}<select value={form.quality_inspection_mode} onChange={(e) => setForm({ ...form, quality_inspection_mode: e.target.value })}><option value="not_required">{tx("Not required")}</option><option value="optional">{tx("Optional")}</option><option value="required">{tx("Required before availability")}</option></select></label><label>{tx("Checklist")}<select value={form.quality_inspection_template_id} onChange={(e) => setForm({ ...form, quality_inspection_template_id: e.target.value })}><option value="">{tx("No default checklist")}</option>{templates.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><button>{tx("Save rule")}</button></form><article className="card"><h2>{tx("How it works")}</h2><p><strong>{tx("Required")}</strong> {tx("receipts enter quarantine automatically. Only a finalized quality decision can release accepted stock.")}</p><p><strong>{tx("Optional")}</strong> {tx("keeps normal receiving, but starting an inspection safely moves the selected receipt into quarantine.")}</p><p><strong>{tx("Not required")}</strong> {tx("preserves the normal PO receiving workflow.")}</p></article></section>
}

function InspectionModal({ canFinalize, canCorrect, canCreate, inspection, disposition, setDisposition, setResult, onClose, onFinalize, busy, categories, onDefect, onCorrection, onAttachment }) {
 const tx = useUiText()

  const [defect, setDefect] = useState({ quality_defect_category_id: '', severity: 'MINOR', affected_quantity: '', description: '' })
  const dialogRef=useDialog(onClose,busy)
  const finalized = Boolean(inspection.finalized_at)
  return createPortal(<div className="quality-modal-backdrop"><section ref={dialogRef} className="quality-modal" role="dialog" aria-modal="true" aria-label={inspection.inspection_number}><button className="quality-close" disabled={busy} aria-label={tx("Close")} onClick={onClose}><X/></button><header><span>{inspection.inspection_number}</span><h2>{inspection.product?.name}</h2><p>{inspection.goods_receipt?.receipt_number} · {inspection.supplier?.name}</p></header><div className="inspection-facts"><span>{tx("Received")} <strong>{inspection.received_quantity}</strong></span><span>{tx("Sample")} <strong>{inspection.inspected_quantity}</strong></span><span>{tx("Status")} <strong>{tx(inspection.status)}</strong></span></div>{!finalized && <form onSubmit={onFinalize} className="quality-form"><h3>{tx("Checklist")}</h3>{inspection.template?.items?.map((check) => <label key={check.id}>{check.name}{check.check_type === 'pass_fail' ? <select required={check.is_required} onChange={(e) => setResult(check, 'passed', e.target.value === 'true')} defaultValue=""><option value="">{tx("Select")}</option><option value="true">{tx("Pass")}</option><option value="false">{tx("Fail")}</option></select> : check.check_type === 'numeric' ? <input required={check.is_required} type="number" step="any" onChange={(e) => setResult(check, 'numeric_value', e.target.value)}/> : <input required={check.is_required} onChange={(e) => setResult(check, 'text_value', e.target.value)}/>}</label>)}<h3>{tx("Disposition (must equal")} {inspection.received_quantity})</h3><div className="disposition-grid">{['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'].map((field) => <label key={field}>{tx(field.replace('_quantity',''))}<input required type="number" min="0" step="0.001" value={disposition[field]} onChange={(e) => setDisposition({ ...disposition, [field]: e.target.value })}/></label>)}</div><label>{tx("Decision notes")}<textarea value={disposition.notes} onChange={(e) => setDisposition({ ...disposition, notes: e.target.value })}/></label><button disabled={busy||!canFinalize}>{tx("Finalize inspection")}</button></form>}{finalized && <><section className="finalized-summary"><h3>{tx("Finalized decision")}</h3><div className="disposition-grid">{['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'].map((field) => <span key={field}>{tx(field.replace('_quantity',''))}<strong>{inspection[field]}</strong></span>)}</div><button className="secondary" disabled={busy||!canCorrect} onClick={onCorrection}>{tx("Create correction revision")}</button></section><form className="quality-form" onSubmit={(e) => { e.preventDefault(); onDefect({ ...defect, quality_defect_category_id: defect.quality_defect_category_id || null }) }}><h3>{tx("Record defect")}</h3><label>{tx("Category")}<select value={defect.quality_defect_category_id} onChange={(e) => setDefect({ ...defect, quality_defect_category_id: e.target.value })}><option value="">{tx("Uncategorized")}</option>{categories.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><div className="form-row"><label>{tx("Severity")}<select value={defect.severity} onChange={(e) => setDefect({ ...defect, severity: e.target.value })}><option value="MINOR">{tx("MINOR")}</option><option value="MAJOR">{tx("MAJOR")}</option><option value="CRITICAL">{tx("CRITICAL")}</option></select></label><label>{tx("Affected quantity")}<input required type="number" min="0.001" step="0.001" value={defect.affected_quantity} onChange={(e) => setDefect({ ...defect, affected_quantity: e.target.value })}/></label></div><label>{tx("Description")}<textarea required value={defect.description} onChange={(e) => setDefect({ ...defect, description: e.target.value })}/></label><button disabled={busy||!canCreate}>{tx("Record defect")}</button></form></>}<section className="quality-form"><h3>{tx("Evidence")}</h3><EntityDocuments entityType="quality-inspection" entityId={inspection.id}/>{inspection.attachments?.map((item) => <button type="button" className="secondary" key={item.id} onClick={() => downloadQualityAttachment(item)}>{item.filename}</button>)}<label>{tx("Attach photo, certificate or report")}<input type="file" accept="image/*,.pdf,.xlsx,.csv,.txt,.docx" disabled={busy||!canCreate} onChange={(e) => e.target.files?.[0] && onAttachment(e.target.files[0])}/></label></section></section></div>, document.body)
}
