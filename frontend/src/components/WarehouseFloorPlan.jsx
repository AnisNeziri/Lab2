import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { GripHorizontal, Minus, Plus } from 'lucide-react'
import {
  PX_PER_METER,
  canvasSize,
  clampSectionPosition,
  clientToMeters,
  metersToPercent,
  sectionBoxPercent,
  sectionCenterClient,
  snapMeters,
} from '../lib/warehouseLayout'

const DRAG_THRESHOLD = 3
const ZOOM_STEP = 0.15
const MIN_ZOOM = 0.4
const MAX_ZOOM = 3

function FloorGrid({ lengthM, widthM, step }) {
  const lines = useMemo(() => {
    const items = []
    const halfL = lengthM / 2
    const halfW = widthM / 2
    const isMajor = (v) => Math.abs(v % 5) < 0.01
    for (let x = -halfL; x <= halfL + 0.001; x += step) {
      const major = isMajor(x)
      items.push({
        key: `v-${x}`,
        x1: metersToPercent(x, lengthM),
        y1: 0,
        x2: metersToPercent(x, lengthM),
        y2: 100,
        major,
      })
    }
    for (let z = -halfW; z <= halfW + 0.001; z += step) {
      const major = isMajor(z)
      items.push({
        key: `h-${z}`,
        x1: 0,
        y1: metersToPercent(z, widthM),
        x2: 100,
        y2: metersToPercent(z, widthM),
        major,
      })
    }
    return items
  }, [lengthM, widthM, step])

  return (
    <svg className="warehouse-floor-grid" viewBox="0 0 100 100" preserveAspectRatio="none">
      {lines.map((line) => (
        <line
          key={line.key}
          x1={line.x1}
          y1={line.y1}
          x2={line.x2}
          y2={line.y2}
          className={line.major ? 'grid-major' : 'grid-minor'}
        />
      ))}
      <line x1="50" y1="0" x2="50" y2="100" className="grid-axis" />
      <line x1="0" y1="50" x2="100" y2="50" className="grid-axis" />
    </svg>
  )
}

export default function WarehouseFloorPlan({
  sections,
  lengthM,
  widthM,
  activeFloor,
  selectedSectionId,
  onSelectSection,
  onMoveSection,
}) {
  const viewportRef = useRef(null)
  const floorRef = useRef(null)
  const [draggingId, setDraggingId] = useState(null)
  const [dragPos, setDragPos] = useState(null)
  const [zoom, setZoom] = useState(1)
  const [snapGrid, setSnapGrid] = useState(1)
  const [pan, setPan] = useState({ x: 0, y: 0 })
  const dragStart = useRef({ x: 0, y: 0, moved: false, offsetX: 0, offsetY: 0 })
  const panStart = useRef({ x: 0, y: 0, panX: 0, panY: 0 })
  const justDragged = useRef(false)
  const [isPanning, setIsPanning] = useState(false)

  const l = Number(lengthM) || 80
  const w = Number(widthM) || 90
  const { width: canvasW, height: canvasH } = canvasSize(l, w, PX_PER_METER)

  const floorSections = sections.filter((s) => (s.floor_level ?? 1) === activeFloor)
  const draggingSection = floorSections.find((s) => s.id === draggingId)

  const getPosition = useCallback((section) => {
    if (draggingId === section.id && dragPos) return dragPos
    return { pos_x: Number(section.pos_x), pos_z: Number(section.pos_z) }
  }, [draggingId, dragPos])

  const resolvePosition = useCallback((clientX, clientY, section, offsetX, offsetY) => {
    const rect = floorRef.current?.getBoundingClientRect()
    if (!rect) return null
    const raw = clientToMeters(clientX, clientY, rect, l, w, {
      snap: snapGrid,
      offsetX,
      offsetY,
    })
    return clampSectionPosition(raw.pos_x, raw.pos_z, section, l, w)
  }, [l, w, snapGrid])

  const finishDrag = useCallback(async (sectionId, pos, section) => {
    const moved = dragStart.current.moved
    setDraggingId(null)
    setDragPos(null)
    if (!moved || !pos) return
    justDragged.current = true
    await onMoveSection?.(sectionId, pos)
  }, [onMoveSection])

  const resetToAuto = useCallback(() => {
    setZoom(1)
    const vp = viewportRef.current
    if (!vp) {
      setPan({ x: 0, y: 0 })
      return
    }
    setPan({
      x: Math.max(0, (vp.clientWidth - canvasW) / 2),
      y: Math.max(0, (vp.clientHeight - canvasH) / 2),
    })
  }, [canvasW, canvasH])

  useEffect(() => {
    resetToAuto()
  }, [l, w, activeFloor, resetToAuto])

  useEffect(() => {
    const el = viewportRef.current
    if (!el) return undefined
    const blockWheel = (e) => e.preventDefault()
    el.addEventListener('wheel', blockWheel, { passive: false })
    return () => el.removeEventListener('wheel', blockWheel)
  }, [])

  useEffect(() => {
    if (!draggingId) return undefined

    const onMove = (e) => {
      const dx = e.clientX - dragStart.current.x
      const dy = e.clientY - dragStart.current.y
      if (Math.abs(dx) > DRAG_THRESHOLD || Math.abs(dy) > DRAG_THRESHOLD) {
        dragStart.current.moved = true
      }
      const pos = resolvePosition(
        e.clientX,
        e.clientY,
        draggingSection,
        dragStart.current.offsetX,
        dragStart.current.offsetY,
      )
      if (pos) setDragPos(pos)
    }

    const onUp = (e) => {
      const pos = dragPos ?? resolvePosition(
        e.clientX,
        e.clientY,
        draggingSection,
        dragStart.current.offsetX,
        dragStart.current.offsetY,
      )
      finishDrag(draggingId, pos, draggingSection)
    }

    window.addEventListener('pointermove', onMove)
    window.addEventListener('pointerup', onUp)
    return () => {
      window.removeEventListener('pointermove', onMove)
      window.removeEventListener('pointerup', onUp)
    }
  }, [draggingId, dragPos, draggingSection, finishDrag, resolvePosition])

  useEffect(() => {
    if (!selectedSectionId) return undefined

    const onKey = (e) => {
      const section = floorSections.find((s) => s.id === selectedSectionId)
      if (!section) return
      const step = e.shiftKey ? snapGrid * 5 : snapGrid
      let dx = 0
      let dz = 0
      if (e.key === 'ArrowLeft') dx = -step
      if (e.key === 'ArrowRight') dx = step
      if (e.key === 'ArrowUp') dz = -step
      if (e.key === 'ArrowDown') dz = step
      if (!dx && !dz) return
      e.preventDefault()
      const pos = clampSectionPosition(
        snapMeters(Number(section.pos_x) + dx, snapGrid),
        snapMeters(Number(section.pos_z) + dz, snapGrid),
        section,
        l,
        w,
      )
      onMoveSection?.(section.id, pos, { silent: true })
    }

    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [selectedSectionId, floorSections, snapGrid, l, w, onMoveSection])

  function handleHandlePointerDown(e, section) {
    e.preventDefault()
    e.stopPropagation()
    const pos = getPosition(section)
    const rect = floorRef.current?.getBoundingClientRect()
    if (!rect) return
    const center = sectionCenterClient(pos, rect, l, w)
    dragStart.current = {
      x: e.clientX,
      y: e.clientY,
      moved: false,
      offsetX: e.clientX - center.x,
      offsetY: e.clientY - center.y,
    }
    setDraggingId(section.id)
    setDragPos(pos)
    e.currentTarget.setPointerCapture(e.pointerId)
  }

  function handleViewportPointerDown(e) {
    if (e.target.closest('.warehouse-section-marker') || e.target.closest('.section-drag-handle')) return
    setIsPanning(true)
    panStart.current = { x: e.clientX, y: e.clientY, panX: pan.x, panY: pan.y }
    viewportRef.current?.setPointerCapture(e.pointerId)
  }

  function handleViewportPointerMove(e) {
    if (!isPanning) return
    setPan({
      x: panStart.current.panX + (e.clientX - panStart.current.x),
      y: panStart.current.panY + (e.clientY - panStart.current.y),
    })
  }

  function handleViewportPointerUp() {
    setIsPanning(false)
  }

  return (
    <div className="floor-workspace">
      <div className="floor-toolbar">
        <span className="floor-toolbar-hint">Drag the grip to move · +/- to zoom · arrow keys to nudge</span>
        <div className="floor-toolbar-actions">
          <label className="floor-snap-toggle">
            Snap
            <select value={snapGrid} onChange={(e) => setSnapGrid(Number(e.target.value))}>
              <option value={0.5}>0.5 m</option>
              <option value={1}>1 m</option>
              <option value={2}>2 m</option>
              <option value={5}>5 m</option>
            </select>
          </label>
          <button type="button" className="floor-tool-btn" onClick={() => setZoom((z) => Math.min(MAX_ZOOM, z + ZOOM_STEP))} title="Zoom in">
            <Plus size={14} />
          </button>
          <button type="button" className="floor-tool-btn" onClick={() => setZoom((z) => Math.max(MIN_ZOOM, z - ZOOM_STEP))} title="Zoom out">
            <Minus size={14} />
          </button>
          <button type="button" className="floor-tool-btn floor-tool-auto" onClick={resetToAuto} title="Reset to 100% zoom">
            Auto
          </button>
          <span className="floor-zoom-label">{Math.round(zoom * 100)}%</span>
        </div>
      </div>

      <div
        ref={viewportRef}
        className={`floor-viewport${isPanning ? ' is-panning' : ''}`}
        onPointerDown={handleViewportPointerDown}
        onPointerMove={handleViewportPointerMove}
        onPointerUp={handleViewportPointerUp}
      >
        <div
          className="floor-transform"
          style={{
            width: canvasW,
            height: canvasH,
            transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
          }}
        >
          <div
            ref={floorRef}
            className="warehouse-floor-preview warehouse-floor-surface"
            style={{ width: canvasW, height: canvasH }}
          >
            <FloorGrid lengthM={l} widthM={w} step={snapGrid} />
            <div className="warehouse-floor-grid-label">
              {l}m × {w}m · {PX_PER_METER}px/m
            </div>

            {floorSections.map((section) => {
              const pos = getPosition(section)
              const box = sectionBoxPercent(section, l, w)
              const isDragging = draggingId === section.id
              const isSelected = selectedSectionId === section.id

              return (
                <div
                  key={section.id}
                  className={`warehouse-section-marker${isDragging ? ' is-dragging' : ''}${isSelected ? ' is-selected' : ''}`}
                  style={{
                    left: `${metersToPercent(pos.pos_x, l)}%`,
                    top: `${metersToPercent(pos.pos_z, w)}%`,
                    width: box.width,
                    height: box.height,
                    background: section.color,
                  }}
                >
                  <button
                    type="button"
                    className="section-drag-handle"
                    onPointerDown={(e) => handleHandlePointerDown(e, section)}
                    title="Drag to move"
                  >
                    <GripHorizontal size={14} />
                  </button>
                  <button
                    type="button"
                    className="section-label-btn"
                    onClick={() => {
                      if (justDragged.current) {
                        justDragged.current = false
                        return
                      }
                      onSelectSection?.(section)
                    }}
                  >
                    {section.code}
                  </button>
                </div>
              )
            })}

            {floorSections.length === 0 && (
              <p className="warehouse-floor-empty">No sections on this level yet. Add one, then drag it into place.</p>
            )}
          </div>
        </div>
      </div>

      {draggingId && dragPos && (
        <div className="floor-coords-readout">
          X <strong>{dragPos.pos_x}m</strong> · Z <strong>{dragPos.pos_z}m</strong>
          {snapGrid > 0 && <span> (snap {snapGrid}m)</span>}
        </div>
      )}
    </div>
  )
}
