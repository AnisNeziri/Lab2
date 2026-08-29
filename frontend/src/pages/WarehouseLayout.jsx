import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Box, Plus, Save, Trash2, ArrowLeft, Layers } from 'lucide-react'
import WarehouseFloorPlan from '../components/WarehouseFloorPlan'
import { footprintArea } from '../lib/warehouseLayout'
import {
  createWarehouseSection,
  deleteWarehouseSection,
  getWarehouseLayout,
  updateWarehouseLayout,
  updateWarehouseSection,
} from '../api/warehouse'

const STANDARD_HEIGHT = 8

const emptySection = {
  code: '',
  name: '',
  color: '#6366f1',
  light_color: '#818cf8',
  pos_x: 0,
  pos_z: 0,
  width: 4,
  depth: 4,
  floor_level: 1,
}

export default function WarehouseLayout() {
  const navigate = useNavigate()
  const [sections, setSections] = useState([])
  const [warehouses, setWarehouses] = useState([])
  const [warehouseId, setWarehouseId] = useState('')
  const [form, setForm] = useState(emptySection)
  const [dims, setDims] = useState({ length_m: 80, width_m: 90, floor_count: 1 })
  const [activeFloor, setActiveFloor] = useState(1)
  const [editingId, setEditingId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const areaSqm = useMemo(
    () => footprintArea(dims.length_m, dims.width_m),
    [dims.length_m, dims.width_m],
  )

  async function load(nextWarehouseId = warehouseId) {
    try {
      setLoading(true)
      setError('')
      const data = await getWarehouseLayout(nextWarehouseId || undefined)
      setSections(data.sections ?? [])
      setWarehouses(data.warehouses ?? [])
      setWarehouseId(String(data.warehouse?.id ?? ''))
      setDims({
        length_m: data.warehouse?.length_m ?? 80,
        width_m: data.warehouse?.width_m ?? 90,
        floor_count: data.warehouse?.floor_count ?? 1,
      })
      setActiveFloor((f) => Math.min(f, data.warehouse?.floor_count ?? 1))
    } catch (err) {
      setError(err.message || 'Could not load warehouse layout.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load('') }, []) // eslint-disable-line react-hooks/exhaustive-deps

  async function saveDimensions(e) {
    e.preventDefault()
    try {
      await updateWarehouseLayout({
        warehouse_id: Number(warehouseId),
        length_m: Number(dims.length_m),
        width_m: Number(dims.width_m),
        height_m: STANDARD_HEIGHT,
        floor_count: Number(dims.floor_count),
      })
      setMessage('Warehouse footprint saved.')
      load()
    } catch (err) {
      setError(err.message || 'Could not save dimensions.')
    }
  }

  function startEdit(section) {
    setEditingId(section.id)
    setActiveFloor(section.floor_level ?? 1)
    setForm({
      code: section.location_code ?? section.code,
      name: section.name,
      color: section.color,
      light_color: section.light_color,
      pos_x: section.pos_x,
      pos_z: section.pos_z,
      width: section.width,
      depth: section.depth,
      floor_level: section.floor_level ?? 1,
    })
  }

  async function handleSectionSubmit(e) {
    e.preventDefault()
    try {
      setError('')
      const { pos_x, pos_z, ...sectionForm } = form
      const payload = {
        ...sectionForm,
        warehouse_id: Number(warehouseId),
        floor_level: activeFloor,
        width: Number(form.width),
        depth: Number(form.depth),
      }
      if (editingId) {
        payload.pos_x = Number(pos_x)
        payload.pos_z = Number(pos_z)
        await updateWarehouseSection(editingId, payload)
        setMessage('Section updated.')
      } else {
        await createWarehouseSection(payload)
        setMessage('Section created and placed automatically — drag it to fine-tune the position.')
      }
      setForm({ ...emptySection, floor_level: activeFloor })
      setEditingId(null)
      load()
    } catch (err) {
      setError(err.message || 'Could not save section.')
    }
  }

  async function handleMoveSection(sectionId, pos, { silent = false } = {}) {
    try {
      await updateWarehouseSection(sectionId, {
        pos_x: pos.pos_x,
        pos_z: pos.pos_z,
      })
      setSections((prev) => prev.map((s) => (
        s.id === sectionId ? { ...s, pos_x: pos.pos_x, pos_z: pos.pos_z } : s
      )))
      if (editingId === sectionId) {
        setForm((f) => ({ ...f, pos_x: pos.pos_x, pos_z: pos.pos_z }))
      }
      if (!silent) setMessage(`Position saved — X ${pos.pos_x}m, Z ${pos.pos_z}m`)
    } catch (err) {
      setError(err.message || 'Could not save position.')
      load()
    }
  }

  async function handleDelete(section) {
    if (!window.confirm(`Delete location ${section.display_code ?? section.code} on level ${section.floor_level ?? 1}? It must be empty and have no child locations.`)) return
    try {
      await deleteWarehouseSection(section.id)
      setMessage('Section deleted.')
      load()
    } catch (err) {
      setError(err.message || 'Could not delete section.')
    }
  }

  async function addFloor() {
    const next = Number(dims.floor_count) + 1
    try {
      await updateWarehouseLayout({
        warehouse_id: Number(warehouseId),
        length_m: Number(dims.length_m),
        width_m: Number(dims.width_m),
        height_m: STANDARD_HEIGHT,
        floor_count: next,
      })
      setDims((d) => ({ ...d, floor_count: next }))
      setActiveFloor(next)
      setMessage(`Level ${next} added. Place sections on this floor in the plan below.`)
    } catch (err) {
      setError(err.message || 'Could not add floor.')
    }
  }

  const floorLevels = Array.from({ length: Number(dims.floor_count) || 1 }, (_, i) => i + 1)

  return (
    <main className="warehouse-layout-page">
      <div className="page-header-row">
        <div>
          <h2>Warehouse layout</h2>
          <p className="page-intro">
            Set the footprint in square meters, add floors, then drag sections anywhere on each level. Standard ceiling height is 8&nbsp;m per floor.
          </p>
        </div>
        <div className="header-actions">
          {warehouses.length > 1 && (
            <label className="warehouse-layout-selector">
              <span>Warehouse</span>
              <select value={warehouseId} onChange={(event) => { setEditingId(null); setActiveFloor(1); void load(event.target.value) }}>
                {warehouses.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.code}</option>)}
              </select>
            </label>
          )}
          <button type="button" className="btn-secondary" onClick={() => navigate('/warehouse-3d')}>
            <Box size={16} /> 3D map
          </button>
          <button type="button" className="btn-secondary" onClick={() => navigate('/dashboard')}>
            <ArrowLeft size={16} /> Back
          </button>
        </div>
      </div>

      {error && <div className="page-error">{error}</div>}
      {message && <div className="page-success">{message}</div>}

      {loading ? (
        <p className="page-message">Loading layout...</p>
      ) : (
        <>
          <section className="card">
            <h3>Building footprint</h3>
            <form className="product-form" onSubmit={saveDimensions}>
              <div className="form-row">
                <label>
                  Length (m)
                  <input
                    type="number"
                    min="1"
                    step="1"
                    value={dims.length_m}
                    onChange={(e) => setDims((d) => ({ ...d, length_m: e.target.value }))}
                  />
                </label>
                <label>
                  Width (m)
                  <input
                    type="number"
                    min="1"
                    step="1"
                    value={dims.width_m}
                    onChange={(e) => setDims((d) => ({ ...d, width_m: e.target.value }))}
                  />
                </label>
                <label>
                  Floors
                  <input
                    type="number"
                    min="1"
                    max="20"
                    step="1"
                    value={dims.floor_count}
                    onChange={(e) => setDims((d) => ({ ...d, floor_count: e.target.value }))}
                  />
                </label>
              </div>
              <div className="warehouse-dim-summary">
                <span><strong>{areaSqm.toLocaleString()} m²</strong> total footprint</span>
                <span>·</span>
                <span>{STANDARD_HEIGHT} m standard height per floor</span>
                <span>·</span>
                <span>{(areaSqm * STANDARD_HEIGHT * (Number(dims.floor_count) || 1)).toLocaleString()} m³ volume</span>
              </div>
              <button type="submit" className="btn-primary"><Save size={16} /> Save footprint</button>
            </form>
          </section>

          <div className="floor-tabs">
            {floorLevels.map((level) => (
              <button
                key={level}
                type="button"
                className={`floor-tab${activeFloor === level ? ' active' : ''}`}
                onClick={() => {
                  setActiveFloor(level)
                  setForm((f) => ({ ...f, floor_level: level }))
                }}
              >
                <Layers size={14} /> Level {level}
              </button>
            ))}
            <button type="button" className="floor-tab add-floor" onClick={addFloor}>
              <Plus size={14} /> Add floor
            </button>
          </div>

          <section className="card">
            <h3>{editingId ? 'Edit section' : `Add section — Level ${activeFloor}`}</h3>
              <form className="product-form" onSubmit={handleSectionSubmit}>
                <div className="form-row">
                  <label>
                    Code
                    <input
                      value={form.code}
                      onChange={(e) => setForm((f) => ({ ...f, code: e.target.value.toUpperCase() }))}
                      required
                      placeholder="A1"
                    />
                  </label>
                  <label>
                    Name
                    <input
                      value={form.name}
                      onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                      required
                      placeholder={`Level ${activeFloor} — Zone A`}
                    />
                  </label>
                </div>
                <div className="form-row">
                  <label>Color<input type="color" value={form.color} onChange={(e) => setForm((f) => ({ ...f, color: e.target.value }))} /></label>
                  <label>Width (m)<input type="number" min="1" step="0.5" value={form.width} onChange={(e) => setForm((f) => ({ ...f, width: e.target.value }))} /></label>
                  <label>Depth (m)<input type="number" min="1" step="0.5" value={form.depth} onChange={(e) => setForm((f) => ({ ...f, depth: e.target.value }))} /></label>
                </div>
                <p className="form-hint">
                  Drag the grip on the floor plan to place sections. Use arrow keys for fine nudging (hold Shift for larger steps).
                  {editingId && <> Position: X {form.pos_x}m, Z {form.pos_z}m</>}
                </p>
                <button type="submit" className="btn-primary">
                  <Plus size={16} /> {editingId ? 'Update section' : 'Add section'}
                </button>
                {editingId && (
                  <button type="button" className="btn-secondary" onClick={() => { setEditingId(null); setForm({ ...emptySection, floor_level: activeFloor }) }}>Cancel edit</button>
                )}
              </form>
          </section>

          <section className="card warehouse-preview-card">
              <h3>Level {activeFloor} — floor plan editor</h3>
              <WarehouseFloorPlan
                sections={sections}
                lengthM={Number(dims.length_m) || 80}
                widthM={Number(dims.width_m) || 90}
                activeFloor={activeFloor}
                selectedSectionId={editingId}
                onSelectSection={startEdit}
                onMoveSection={handleMoveSection}
              />
          </section>

          <section className="card">
            <h3>Sections ({sections.length})</h3>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Level</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Products</th>
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {sections.map((s) => (
                    <tr key={s.id} className={s.floor_level === activeFloor ? 'row-active-floor' : ''}>
                      <td>L{s.floor_level ?? 1}</td>
                      <td><strong>{s.display_code ?? s.code}</strong></td>
                      <td>{s.name}</td>
                      <td>X {s.pos_x}, Z {s.pos_z}</td>
                      <td>{s.product_count ?? 0}</td>
                      <td className="table-actions">
                        <button type="button" className="btn-link" onClick={() => { setActiveFloor(s.floor_level ?? 1); startEdit(s) }}>Edit</button>
                        <button type="button" className="btn-link danger" onClick={() => handleDelete(s)}><Trash2 size={14} /></button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </>
      )}
    </main>
  )
}
