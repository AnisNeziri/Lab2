import { useCallback, useEffect, useMemo, useState } from 'react'
import { ClipboardCheck, ShieldAlert, BadgeCheck, FileWarning, Plus, X } from 'lucide-react'
import { getSuppliers } from '../api/suppliers'
import { getCategories } from '../api/categories'
import { getProducts } from '../api/products'
import {
  addDefect, configureQuality, correctInspection, createInspection, createSupplierClaim,
  finalizeInspection, getDefectCategories, getInspection, getInspections, getInspectionSources,
  getQualityDashboard, getQualityTemplates, getSupplierClaims, resolveSupplierClaim,
  saveDefectCategory, saveQualityTemplate,
  uploadQualityAttachment, downloadQualityAttachment,
} from '../api/quality'
import './QualityManagement.css'

const number = (value) => Number(value || 0)
const failure = (error) => Object.values(error?.errors || {}).flat().join(' ') || error?.message || 'The request could not be completed.'
const emptyDisposition = { accepted_quantity: '', rejected_quantity: '0', quarantine_quantity: '0', damaged_quantity: '0', decision: '', notes: '', results: [] }

export default function QualityManagement() {
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
      getQualityDashboard(), getInspections({ per_page: 100 }), getSupplierClaims({ per_page: 100 }),
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
    try { await saveQualityTemplate(template); setTemplate({ name: '', description: '', items: [{ name: '', check_type: 'pass_fail', is_required: true }] }); setNotice('Reusable checklist saved.'); await load() }
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
    <header className="quality-hero"><div><span>Incoming quality control</span><h1>Quality Management</h1><p>Inspect receipts, preserve lot traceability, control quarantine and hold suppliers accountable.</p></div><ClipboardCheck size={46}/></header>
    {notice && <div className="quality-notice" role="status">{notice}<button onClick={() => setNotice('')}><X size={16}/></button></div>}
    <nav className="quality-tabs">{['overview','inspections','checklists','claims','configuration'].map((item) => <button key={item} className={tab === item ? 'active' : ''} onClick={() => setTab(item)}>{item}</button>)}</nav>

    {tab === 'overview' && <>
      <section className="quality-metrics">
        <Metric icon={ClipboardCheck} label="Pending inspections" value={dashboard?.pending_inspections}/>
        <Metric icon={ShieldAlert} label="Quarantined quantity" value={dashboard?.quarantined_quantity}/>
        <Metric icon={FileWarning} label="Failed inspections" value={dashboard?.failed_inspections}/>
        <Metric icon={BadgeCheck} label="Open supplier claims" value={dashboard?.open_claims}/>
      </section>
      <section className="quality-grid"><article className="card"><h2>Frequent defect categories</h2>{dashboard?.worst_defect_categories?.length ? dashboard.worst_defect_categories.map((item) => <p className="quality-line" key={item.name}><span>{item.name}</span><strong>{item.defect_count} · {item.affected_quantity} affected</strong></p>) : <p>No defects in this period.</p>}</article><article className="card"><h2>Supplier risk</h2>{dashboard?.suppliers_highest_defect_rates?.length ? dashboard.suppliers_highest_defect_rates.map((item) => <p className="quality-line" key={item.supplier_id}><span>{item.supplier_name}</span><strong>{item.quality.defect_rate}% defect rate</strong></p>) : <p>Insufficient supplier quality history.</p>}</article></section>
    </>}

    {tab === 'inspections' && <section className="quality-grid">
      <form className="card quality-form" onSubmit={submitInspection}><h2>Start receipt inspection</h2><label>Receipt product<select required value={newInspection.goods_receipt_item_id} onChange={(e) => setNewInspection({ ...newInspection, goods_receipt_item_id: e.target.value })}><option value="">Select received line</option>{receiptItems.filter((item) => !item.quality_inspection).map((item) => <option value={item.id} key={item.id}>{item.receipt.receipt_number} · {item.product?.name} · {number(item.accepted_base_quantity) + number(item.damaged_base_quantity)} {item.inventory_unit}</option>)}</select></label><div className="form-row"><label>Scope<select value={newInspection.inspection_scope} onChange={(e) => setNewInspection({ ...newInspection, inspection_scope: e.target.value })}><option value="whole_receipt">Whole receipt</option><option value="sample">Sample</option></select></label><label>Sample quantity<input type="number" min="0.001" step="0.001" value={newInspection.inspected_quantity} onChange={(e) => setNewInspection({ ...newInspection, inspected_quantity: e.target.value })}/></label></div><label>Checklist<select value={newInspection.quality_inspection_template_id} onChange={(e) => setNewInspection({ ...newInspection, quality_inspection_template_id: e.target.value })}><option value="">Use configured checklist</option>{templates.filter((item) => item.is_active).map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><label>Notes<textarea value={newInspection.notes} onChange={(e) => setNewInspection({ ...newInspection, notes: e.target.value })}/></label><button disabled={busy}>Create inspection</button></form>
      <article className="card quality-table-card"><h2>Inspection history</h2><div className="table-wrap"><table><thead><tr><th>Inspection</th><th>Receipt / product</th><th>Supplier</th><th>Outcome</th><th></th></tr></thead><tbody>{inspections.map((item) => <tr key={item.id}><td><strong>{item.inspection_number}</strong><small>{new Date(item.inspection_date).toLocaleDateString()}</small></td><td>{item.goods_receipt?.receipt_number}<small>{item.product?.name}</small></td><td>{item.supplier?.name || '—'}</td><td><span className={`quality-status ${item.status.toLowerCase()}`}>{item.status}</span></td><td><button className="secondary" onClick={() => openInspection(item.id)}>Open</button></td></tr>)}</tbody></table></div></article>
    </section>}

    {tab === 'checklists' && <section className="quality-grid"><form className="card quality-form" onSubmit={submitTemplate}><h2>New reusable checklist</h2><label>Name<input required value={template.name} onChange={(e) => setTemplate({ ...template, name: e.target.value })}/></label><label>Description<textarea value={template.description} onChange={(e) => setTemplate({ ...template, description: e.target.value })}/></label>{template.items.map((item, index) => <div className="check-editor" key={index}><input required placeholder="Check name" value={item.name} onChange={(e) => setTemplate({ ...template, items: template.items.map((row, i) => i === index ? { ...row, name: e.target.value } : row) })}/><select value={item.check_type} onChange={(e) => setTemplate({ ...template, items: template.items.map((row, i) => i === index ? { ...row, check_type: e.target.value } : row) })}><option value="pass_fail">Pass / fail</option><option value="numeric">Measurement</option><option value="text">Text result</option></select><label className="inline-check"><input type="checkbox" checked={item.is_required} onChange={(e) => setTemplate({ ...template, items: template.items.map((row, i) => i === index ? { ...row, is_required: e.target.checked } : row) })}/>Required</label></div>)}<div className="form-actions"><button type="button" className="secondary" onClick={() => setTemplate({ ...template, items: [...template.items, { name: '', check_type: 'pass_fail', is_required: true }] })}><Plus size={16}/> Add check</button><button disabled={busy}>Save checklist</button></div></form><article className="card"><h2>Available checklists</h2>{templates.map((item) => <div className="template-summary" key={item.id}><strong>{item.name}</strong><span>{item.items?.length || 0} checks · {item.is_active ? 'Active' : 'Inactive'}</span><p>{item.description}</p></div>)}</article></section>}

    {tab === 'claims' && <section className="quality-grid"><form className="card quality-form" onSubmit={submitClaim}><h2>Open supplier claim</h2><label>Inspection<select required value={claim.quality_inspection_id} onChange={(e) => setClaim({ ...claim, quality_inspection_id: e.target.value })}><option value="">Select finalized inspection</option>{inspections.filter((item) => item.status !== 'PENDING' && (number(item.rejected_quantity) + number(item.damaged_quantity) + number(item.quarantine_quantity)) > 0).map((item) => <option value={item.id} key={item.id}>{item.inspection_number} · {item.supplier?.name} · {item.product?.name}</option>)}</select></label><label>Requested outcome<select value={claim.requested_outcome} onChange={(e) => setClaim({ ...claim, requested_outcome: e.target.value })}><option value="replacement_requested">Replacement</option><option value="refund">Refund</option><option value="supplier_credit">Supplier credit</option><option value="price_reduction">Price reduction</option><option value="goods_returned">Return goods</option></select></label><label>Expected resolution<input type="date" value={claim.expected_resolution_date} onChange={(e) => setClaim({ ...claim, expected_resolution_date: e.target.value })}/></label><label>Communication notes<textarea value={claim.communication_notes} onChange={(e) => setClaim({ ...claim, communication_notes: e.target.value })}/></label><button disabled={busy}>Create claim</button></form><article className="card quality-table-card"><h2>Supplier claims</h2>{claims.map((item) => <div className="claim-row" key={item.id}><div><strong>{item.claim_number} · {item.supplier?.name}</strong><small>{item.requested_outcome.replaceAll('_', ' ')} · {item.currency} {item.affected_value}</small></div><span className={`quality-status ${item.status.toLowerCase()}`}>{item.status}</span>{item.status !== 'RESOLVED' && <button className="secondary" disabled={busy} onClick={async () => { const resolution = window.prompt('Resolution: replacement_agreed, refund, supplier_credit, price_reduction, goods_returned, claim_rejected or resolved', 'resolved'); if (!resolution) return; try { setBusy(true); await resolveSupplierClaim(item.id, { actual_resolution: resolution, resolution_notes: 'Resolved from Quality Management.' }); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }}>Resolve</button>}</div>)}</article></section>}

    {tab === 'configuration' && <Configuration products={companyProducts} suppliers={suppliers} templates={templates} categories={companyCategories} onSaved={load} setNotice={setNotice}/>} 
    {selected && <InspectionModal inspection={selected} disposition={disposition} setDisposition={setDisposition} setResult={setResult} onClose={() => setSelected(null)} onFinalize={submitFinalization} busy={busy} categories={categories} onDefect={async (payload) => { try { setBusy(true); await addDefect(selected.id, payload); await openInspection(selected.id); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }} onAttachment={async (file) => { try { setBusy(true); const data = new FormData(); data.append('quality_inspection_id', selected.id); data.append('document_type', 'evidence'); data.append('file', file); await uploadQualityAttachment(data); await openInspection(selected.id) } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }} onCorrection={async () => { const reason = window.prompt('Reason for correction'); if (!reason) return; try { setBusy(true); const revision = await correctInspection(selected.id, reason); setSelected(revision); setDisposition({ ...emptyDisposition, accepted_quantity: String(revision.received_quantity) }); await load() } catch (error) { setNotice(failure(error)) } finally { setBusy(false) } }}/>} 
  </main>
}

function Metric({ icon: Icon, label, value }) { return <article className="quality-metric"><Icon size={22}/><span>{label}</span><strong>{value ?? '—'}</strong></article> }

function Configuration({ products, suppliers, templates, categories, onSaved, setNotice }) {
  const [form, setForm] = useState({ entity_type: 'product', entity_id: '', quality_inspection_mode: 'optional', quality_inspection_template_id: '' })
  const options = form.entity_type === 'product' ? products : (form.entity_type === 'category' ? categories : suppliers)
  return <section className="quality-grid"><form className="card quality-form" onSubmit={async (e) => { e.preventDefault(); try { await configureQuality({ ...form, entity_id: Number(form.entity_id), quality_inspection_template_id: form.quality_inspection_template_id || null }); setNotice('Incoming inspection rule saved.'); await onSaved() } catch (error) { setNotice(failure(error)) } }}><h2>Incoming inspection rule</h2><label>Apply to<select value={form.entity_type} onChange={(e) => setForm({ ...form, entity_type: e.target.value, entity_id: '' })}><option value="product">Product</option><option value="category">Category</option><option value="supplier">Supplier</option></select></label><label>Record<select required value={form.entity_id} onChange={(e) => setForm({ ...form, entity_id: e.target.value })}><option value="">Select</option>{options.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><label>Inspection requirement<select value={form.quality_inspection_mode} onChange={(e) => setForm({ ...form, quality_inspection_mode: e.target.value })}><option value="not_required">Not required</option><option value="optional">Optional</option><option value="required">Required before availability</option></select></label><label>Checklist<select value={form.quality_inspection_template_id} onChange={(e) => setForm({ ...form, quality_inspection_template_id: e.target.value })}><option value="">No default checklist</option>{templates.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><button>Save rule</button></form><article className="card"><h2>How it works</h2><p><strong>Required</strong> receipts enter quarantine automatically. Only a finalized quality decision can release accepted stock.</p><p><strong>Optional</strong> keeps normal receiving, but starting an inspection safely moves the selected receipt into quarantine.</p><p><strong>Not required</strong> preserves the normal PO receiving workflow.</p></article></section>
}

function InspectionModal({ inspection, disposition, setDisposition, setResult, onClose, onFinalize, busy, categories, onDefect, onCorrection, onAttachment }) {
  const [defect, setDefect] = useState({ quality_defect_category_id: '', severity: 'MINOR', affected_quantity: '', description: '' })
  const finalized = Boolean(inspection.finalized_at)
  return <div className="quality-modal-backdrop"><section className="quality-modal"><button className="quality-close" onClick={onClose}><X/></button><header><span>{inspection.inspection_number}</span><h2>{inspection.product?.name}</h2><p>{inspection.goods_receipt?.receipt_number} · {inspection.supplier?.name}</p></header><div className="inspection-facts"><span>Received <strong>{inspection.received_quantity}</strong></span><span>Sample <strong>{inspection.inspected_quantity}</strong></span><span>Status <strong>{inspection.status}</strong></span></div>{!finalized && <form onSubmit={onFinalize} className="quality-form"><h3>Checklist</h3>{inspection.template?.items?.map((check) => <label key={check.id}>{check.name}{check.check_type === 'pass_fail' ? <select required={check.is_required} onChange={(e) => setResult(check, 'passed', e.target.value === 'true')} defaultValue=""><option value="">Select</option><option value="true">Pass</option><option value="false">Fail</option></select> : check.check_type === 'numeric' ? <input required={check.is_required} type="number" step="any" onChange={(e) => setResult(check, 'numeric_value', e.target.value)}/> : <input required={check.is_required} onChange={(e) => setResult(check, 'text_value', e.target.value)}/>}</label>)}<h3>Disposition (must equal {inspection.received_quantity})</h3><div className="disposition-grid">{['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'].map((field) => <label key={field}>{field.replace('_quantity','')}<input required type="number" min="0" step="0.001" value={disposition[field]} onChange={(e) => setDisposition({ ...disposition, [field]: e.target.value })}/></label>)}</div><label>Decision notes<textarea value={disposition.notes} onChange={(e) => setDisposition({ ...disposition, notes: e.target.value })}/></label><button disabled={busy}>Finalize inspection</button></form>}{finalized && <><section className="finalized-summary"><h3>Finalized decision</h3><div className="disposition-grid">{['accepted_quantity','rejected_quantity','quarantine_quantity','damaged_quantity'].map((field) => <span key={field}>{field.replace('_quantity','')}<strong>{inspection[field]}</strong></span>)}</div><button className="secondary" onClick={onCorrection}>Create correction revision</button></section><form className="quality-form" onSubmit={(e) => { e.preventDefault(); onDefect({ ...defect, quality_defect_category_id: defect.quality_defect_category_id || null }) }}><h3>Record defect</h3><label>Category<select value={defect.quality_defect_category_id} onChange={(e) => setDefect({ ...defect, quality_defect_category_id: e.target.value })}><option value="">Uncategorized</option>{categories.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select></label><div className="form-row"><label>Severity<select value={defect.severity} onChange={(e) => setDefect({ ...defect, severity: e.target.value })}><option>MINOR</option><option>MAJOR</option><option>CRITICAL</option></select></label><label>Affected quantity<input required type="number" min="0.001" step="0.001" value={defect.affected_quantity} onChange={(e) => setDefect({ ...defect, affected_quantity: e.target.value })}/></label></div><label>Description<textarea required value={defect.description} onChange={(e) => setDefect({ ...defect, description: e.target.value })}/></label><button disabled={busy}>Record defect</button></form></>}<section className="quality-form"><h3>Evidence</h3>{inspection.attachments?.map((item) => <button type="button" className="secondary" key={item.id} onClick={() => downloadQualityAttachment(item)}>{item.filename}</button>)}<label>Attach photo, certificate or report<input type="file" accept="image/*,.pdf,.xlsx,.xls,.csv,.txt,.doc,.docx" disabled={busy} onChange={(e) => e.target.files?.[0] && onAttachment(e.target.files[0])}/></label></section></section></div>
}
