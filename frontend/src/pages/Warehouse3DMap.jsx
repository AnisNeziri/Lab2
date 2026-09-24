import { useUiText } from '../hooks/useUiText'
import { useRef, useState, useEffect, useMemo, useCallback } from 'react'
import { Canvas, useFrame } from '@react-three/fiber'
import { OrbitControls, Html, Float } from '@react-three/drei'
import { useAuthStore } from '../store/authStore'
import { useNavigate } from 'react-router-dom'
import { getEcho } from '../lib/echo'
import { apiRequest } from '../api/client'
import { getAllProducts } from '../api/products'
import { useDialog } from '../hooks/useDialog'
import { getWarehouseLayout } from '../api/warehouse'
import { productMatchesShelf, STANDARD_FLOOR_HEIGHT } from '../lib/warehouseLayout'
import { buildShelfStockMap, sectionsToShelves, sectionsToZones } from '../lib/warehouseStock'
import { getInventoryQuantity } from '../utils/inventoryQuantity'
import * as THREE from 'three'
import './Warehouse3DMap.css'

function stockColor(level, heatmap, score) {
  if (heatmap) {
    const t = Math.min((score ?? 0) / 20, 1)
    return new THREE.Color(t, 1 - t, 0.05)
  }
  if (level === null) return new THREE.Color('#475569')
  if (level === 0) return new THREE.Color('#dc2626')
  if (level < 100) return new THREE.Color('#ea580c')
  return new THREE.Color('#16a34a')
}

function RackFrame() {
  const metal = <meshStandardMaterial color="#2d3748" roughness={0.3} metalness={0.9} />
  const dark  = <meshStandardMaterial color="#1a202c" roughness={0.4} metalness={0.7} />
  const postPositions = [[-2.1, -0.95], [-2.1, 0.95], [2.1, -0.95], [2.1, 0.95]]
  const deckY = [0.08, 1.38, 2.68, 3.98]
  return (
    <group>
      {postPositions.map(([x, z], i) => (
        <mesh key={i} position={[x, 2.55, z]}>
          <boxGeometry args={[0.12, 5.1, 0.12]} />{metal}
        </mesh>
      ))}
      {deckY.map(y => (
        <group key={y}>
          <mesh position={[0, y, -0.95]}><boxGeometry args={[4.5, 0.08, 0.08]} />{metal}</mesh>
          <mesh position={[0, y,  0.95]}><boxGeometry args={[4.5, 0.08, 0.08]} />{metal}</mesh>
          <mesh position={[0, y + 0.04, 0]}><boxGeometry args={[4.4, 0.05, 1.82]} />{dark}</mesh>
        </group>
      ))}
      <mesh position={[0, 2.55, -0.95]}>
        <boxGeometry args={[4.5, 5.1, 0.04]} />
        <meshStandardMaterial color="#111827" roughness={0.6} metalness={0.5} transparent opacity={0.55} />
      </mesh>
      <mesh position={[0, 2.55, -0.95]} rotation={[0, 0, Math.atan2(5.1, 4.5)]}>
        <boxGeometry args={[0.06, 6.9, 0.06]} />{metal}
      </mesh>
    </group>
  )
}

function ShelfBoxGrid({ y, stockLevel }) {
  // Pre-generate stable random offsets keyed by slot index so they don't
  // jitter every frame — only recalc when stockLevel bucket changes
  const bucket = stockLevel === null ? 'u' : stockLevel === 0 ? '0' : stockLevel < 100 ? 'lo' : 'hi'

  const layout = useMemo(() => {
    const ALL_SLOTS = []
    const cols = 5, rows = 2
    for (let c = 0; c < cols; c++) {
      for (let r = 0; r < rows; r++) {
        ALL_SLOTS.push({
          x:   (c - (cols - 1) / 2) * 0.82,
          z:   (r - (rows - 1) / 2) * 0.72,
          w:   0.58 + Math.random() * 0.2,
          h:   0.42 + Math.random() * 0.2,
          d:   0.50 + Math.random() * 0.16,
          rot: (Math.random() - 0.5) * 0.2,
        })
      }
    }
    return ALL_SLOTS
  }, [bucket]) // eslint-disable-line react-hooks/exhaustive-deps

  // How many of the 10 slots to fill
  const fillCount = useMemo(() => {
    if (stockLevel === null) return 0          // no stock data: do not imply a full shelf
    if (stockLevel === 0)    return 0          // out of stock → empty
    if (stockLevel < 50) return 3
    if (stockLevel < 100) return 6
    return 10
  }, [stockLevel])

  const color = stockLevel !== null && stockLevel > 0 && stockLevel < 100 ? '#7c2d12' : '#92400e'

  return (
    <>
      {layout.slice(0, fillCount).map((b, i) => (
        <mesh key={i} position={[b.x, y + b.h / 2 + 0.05, b.z]} rotation={[0, b.rot, 0]}>
          <boxGeometry args={[b.w, b.h, b.d]} />
          <meshStandardMaterial color={color} roughness={0.82} metalness={0.0} />
        </mesh>
      ))}
    </>
  )
}

function LedStrip({ stockLevel, heatmap, activityScore }) {
  const meshRef = useRef()
  const color   = useMemo(() => stockColor(stockLevel, heatmap, activityScore), [stockLevel, heatmap, activityScore])
  const isAlert = !heatmap && stockLevel !== null && stockLevel > 0 && stockLevel < 100
  useFrame(({ clock }) => {
    if (meshRef.current && isAlert)
      meshRef.current.material.emissiveIntensity = 1.2 + Math.sin(clock.elapsedTime * 4) * 1.0
  })
  return (
    <group>
      <mesh ref={meshRef} position={[0, 5.18, 0]}>
        <boxGeometry args={[4.3, 0.09, 0.09]} />
        <meshStandardMaterial color={color} emissive={color} emissiveIntensity={isAlert ? 2.0 : 1.0} roughness={0.1} metalness={0.3} toneMapped={false} />
      </mesh>
      <mesh position={[0, 0.015, 0]} rotation={[-Math.PI / 2, 0, 0]}>
        <planeGeometry args={[4.2, 1.8]} />
        <meshStandardMaterial color={color} emissive={color} emissiveIntensity={0.35} transparent opacity={0.28} toneMapped={false} depthWrite={false} />
      </mesh>
    </group>
  )
}

function ShelfUnit({ shelf, stockLevel, heatmap, activityScore, onClick }) {
 const tx = useUiText()

  const [hovered, setHovered] = useState(false)
  const groupRef  = useRef()
  const scaleRef  = useRef(1)
  const targetRef = useRef(1)

  // Pulse-scale animation when stockLevel changes (brief bounce)
  const prevLevel = useRef(stockLevel)
  useEffect(() => {
    if (prevLevel.current !== stockLevel) {
      targetRef.current = 1.04
      prevLevel.current = stockLevel
    }
  }, [stockLevel])

  useFrame(() => {
    if (!groupRef.current) return
    targetRef.current += (1 - targetRef.current) * 0.12
    scaleRef.current  += (targetRef.current - scaleRef.current) * 0.18
    groupRef.current.scale.setScalar(scaleRef.current)
  })

  // stockLevel is a health percentage, not a physical unit quantity.
  const statusLabel = stockLevel === null ? '—'
    : stockLevel === 0 ? tx('Out of Stock')
    : stockLevel < 100 ? tx('Low Stock')
    : tx('Healthy')

  const statusColor = stockLevel === null ? '#94a3b8'
    : stockLevel === 0  ? '#ef4444'
    : stockLevel < 100  ? '#fb923c'
    : '#4ade80'

  return (
    <group ref={groupRef} position={[shelf.x, shelf.y ?? 0, shelf.z]}>
      <RackFrame />
      {[0.08, 1.38, 2.68, 3.98].map(y => (
        <ShelfBoxGrid key={y} y={y} stockLevel={stockLevel} />
      ))}
      <LedStrip stockLevel={stockLevel} heatmap={heatmap} activityScore={activityScore} />

      {/* shelf ID label — always visible */}
      <Html position={[0, 5.5, 0]} center distanceFactor={18} style={{ pointerEvents: 'none' }} zIndexRange={[0, 0]}>
        <div style={{ background: 'rgba(0,0,0,0.75)', color: '#e2e8f0', fontSize: '12px', fontWeight: 800, padding: '3px 9px', borderRadius: 5, border: '1px solid rgba(255,255,255,0.18)', whiteSpace: 'nowrap', pointerEvents: 'none', fontFamily: 'monospace', letterSpacing: '0.06em' }}>
          {shelf.id}
        </div>
      </Html>

      {/* hover tooltip — live stock badge */}
      {hovered && (
        <Html position={[0, 6.4, 0]} center distanceFactor={18} style={{ pointerEvents: 'none' }} zIndexRange={[1, 1]}>
          <div style={{
            background: 'rgba(2,6,23,0.92)', backdropFilter: 'blur(8px)',
            border: `1px solid ${statusColor}88`,
            color: statusColor, fontSize: '13px', fontWeight: 700,
            padding: '5px 14px', borderRadius: 7,
            whiteSpace: 'nowrap', pointerEvents: 'none',
            boxShadow: `0 0 14px ${statusColor}44`,
            fontFamily: 'monospace',
          }}> {tx("Shelf")} {shelf.id}: {tx(statusLabel)}
          </div>
        </Html>
      )}

      {/* invisible interaction surface */}
      <mesh
        position={[0, 2.55, 0]}
        onClick={e => { e.stopPropagation(); onClick(shelf) }}
        onPointerOver={e => { e.stopPropagation(); setHovered(true);  document.body.style.cursor = 'pointer' }}
        onPointerOut={e  => { e.stopPropagation(); setHovered(false); document.body.style.cursor = 'default'  }}
      >
        <boxGeometry args={[4.6, 5.2, 2.1]} />
        <meshStandardMaterial transparent opacity={0} depthWrite={false} />
      </mesh>
    </group>
  )
}

function ZoneLabel({ zone }) {
  const cx = zone.shelves.reduce((s, sh) => s + sh.x, 0) / zone.shelves.length
  const cz = zone.shelves.reduce((s, sh) => s + sh.z, 0) / zone.shelves.length
  return (
    <Float speed={0.7} floatIntensity={0.25} rotationIntensity={0}>
      <Html position={[cx, 7.5, cz]} center distanceFactor={24} style={{ pointerEvents: 'none' }} zIndexRange={[0, 0]}>
        <div style={{ background: 'rgba(0,0,0,0.8)', backdropFilter: 'blur(6px)', border: `1px solid ${zone.color}66`, color: zone.color, fontSize: '14px', fontWeight: 800, padding: '5px 16px', borderRadius: 8, whiteSpace: 'nowrap', pointerEvents: 'none', letterSpacing: '0.07em', textShadow: `0 0 14px ${zone.color}`, userSelect: 'none' }}>
          {zone.label}
        </div>
      </Html>
    </Float>
  )
}

function ZoneLights({ zones }) {
  return (zones ?? []).map(zone => {
    const cx = zone.shelves.reduce((s, sh) => s + sh.x, 0) / zone.shelves.length
    const cz = zone.shelves.reduce((s, sh) => s + sh.z, 0) / zone.shelves.length
    return <pointLight key={zone.id} position={[cx, 7, cz]} color={zone.lightColor} intensity={18} distance={18} decay={2} />
  })
}

function Forklift({ position = [0, 0, 0], rotation = [0, 0, 0] }) {
  const yellow  = <meshStandardMaterial color="#f59e0b" roughness={0.4} metalness={0.6} />
  const black   = <meshStandardMaterial color="#111827" roughness={0.8} metalness={0.3} />
  const chrome  = <meshStandardMaterial color="#9ca3af" roughness={0.2} metalness={0.9} />
  const dark    = <meshStandardMaterial color="#1f2937" roughness={0.5} metalness={0.7} />

  return (
    <group position={position} rotation={rotation}>
      {/* chassis */}
      <mesh position={[0, 0.45, 0]}><boxGeometry args={[1.8, 0.9, 3.2]} />{yellow}</mesh>
      {/* cab */}
      <mesh position={[0.1, 1.15, 0.6]}><boxGeometry args={[1.5, 0.6, 1.6]} />{yellow}</mesh>
      {/* overhead guard frame */}
      {[[-0.7, 0, -1.2], [0.7, 0, -1.2], [-0.7, 0, 0.8], [0.7, 0, 0.8]].map(([x, , z], i) => (
        <mesh key={i} position={[x, 2.05, z]}><boxGeometry args={[0.06, 1.5, 0.06]} />{chrome}</mesh>
      ))}
      <mesh position={[0, 2.78, -0.2]}><boxGeometry args={[1.46, 0.06, 2.06]} />{chrome}</mesh>
      {/* 4 wheels */}
      {[[-0.95, 0.22, 1.1], [0.95, 0.22, 1.1], [-0.95, 0.22, -1.1], [0.95, 0.22, -1.1]].map(([x, y, z], i) => (
        <mesh key={i} position={[x, y, z]} rotation={[0, 0, Math.PI / 2]}>
          <cylinderGeometry args={[0.28, 0.28, 0.22, 12]} />{black}
        </mesh>
      ))}
      {/* mast */}
      {[-0.3, 0.3].map((x, i) => (
        <mesh key={i} position={[x, 1.4, -1.75]}><boxGeometry args={[0.1, 2.8, 0.1]} />{dark}</mesh>
      ))}
      {/* forks */}
      {[-0.25, 0.25].map((x, i) => (
        <mesh key={i} position={[x, 0.55, -2.35]}><boxGeometry args={[0.08, 0.06, 1.1]} />{chrome}</mesh>
      ))}
      {/* pallet on forks */}
      <mesh position={[0, 0.72, -2.2]}><boxGeometry args={[0.8, 0.18, 0.75]} /><meshStandardMaterial color="#854d0e" roughness={0.9} metalness={0} /></mesh>
      <mesh position={[0, 0.95, -2.2]}><boxGeometry args={[0.7, 0.5, 0.65]} /><meshStandardMaterial color="#92400e" roughness={0.85} metalness={0} /></mesh>
      {/* headlights */}
      <mesh position={[0.5,  0.9, -1.8]}><sphereGeometry args={[0.09, 8, 8]} /><meshStandardMaterial emissive="#fff7d6" emissiveIntensity={3} toneMapped={false} /></mesh>
      <mesh position={[-0.5, 0.9, -1.8]}><sphereGeometry args={[0.09, 8, 8]} /><meshStandardMaterial emissive="#fff7d6" emissiveIntensity={3} toneMapped={false} /></mesh>
      <pointLight position={[0, 0.9, -2.4]} color="#fffbeb" intensity={6} distance={5} decay={2} />
    </group>
  )
}

function DeliveryTruck({ position = [0, 0, 0], rotation = [0, 0, 0] }) {
 const tx = useUiText()

  const white  = <meshStandardMaterial color="#e2e8f0" roughness={0.5} metalness={0.3} />
  const gray   = <meshStandardMaterial color="#475569" roughness={0.55} metalness={0.4} />
  const black  = <meshStandardMaterial color="#0f172a" roughness={0.8} metalness={0.2} />
  const glass  = <meshStandardMaterial color="#38bdf8" roughness={0.05} metalness={0.1} transparent opacity={0.6} />
  const red    = <meshStandardMaterial color="#dc2626" emissive="#dc2626" emissiveIntensity={0.8} roughness={0.3} metalness={0.3} toneMapped={false} />

  return (
    <group position={position} rotation={rotation}>
      {/* cargo body */}
      <mesh position={[0, 2.2, 2.5]}><boxGeometry args={[3.6, 3.4, 8.0]} />{gray}</mesh>
      {/* cabin */}
      <mesh position={[0, 1.6, -2.4]}><boxGeometry args={[3.6, 2.5, 2.2]} />{white}</mesh>
      {/* windshield */}
      <mesh position={[0, 2.1, -3.56]}><boxGeometry args={[2.8, 1.4, 0.06]} />{glass}</mesh>
      {/* rear door lines */}
      <mesh position={[0, 2.2, 6.52]}><boxGeometry args={[3.58, 3.38, 0.04]} />{white}</mesh>
      {/* 6 wheels */}
      {[[-1.88, 0.48, 1.2], [1.88, 0.48, 1.2], [-1.88, 0.48, 3.5], [1.88, 0.48, 3.5], [-1.88, 0.48, -2.2], [1.88, 0.48, -2.2]].map(([x, y, z], i) => (
        <mesh key={i} position={[x, y, z]} rotation={[0, 0, Math.PI / 2]}>
          <cylinderGeometry args={[0.48, 0.48, 0.3, 12]} />{black}
        </mesh>
      ))}
      {/* headlights */}
      <mesh position={[1.1,  1.6, -3.56]}><boxGeometry args={[0.55, 0.3, 0.06]} /><meshStandardMaterial emissive="#fffde7" emissiveIntensity={2.5} toneMapped={false} /></mesh>
      <mesh position={[-1.1, 1.6, -3.56]}><boxGeometry args={[0.55, 0.3, 0.06]} /><meshStandardMaterial emissive="#fffde7" emissiveIntensity={2.5} toneMapped={false} /></mesh>
      {/* tail lights */}
      <mesh position={[1.4,  1.8, 6.55]}><boxGeometry args={[0.3, 0.22, 0.04]} />{red}</mesh>
      <mesh position={[-1.4, 1.8, 6.55]}><boxGeometry args={[0.3, 0.22, 0.04]} />{red}</mesh>
      {/* AIMS branding */}
      <Html position={[1.82, 2.8, 2.5]} rotation={[0, Math.PI / 2, 0]} center distanceFactor={12} style={{ pointerEvents: 'none' }} zIndexRange={[0, 0]}>
        <div style={{ color: '#6366f1', fontSize: '16px', fontWeight: 900, letterSpacing: '0.15em', textShadow: '0 0 10px #6366f1', fontFamily: 'monospace', pointerEvents: 'none', whiteSpace: 'nowrap' }}> {tx("AIMS LOGISTICS")} </div>
      </Html>
      <pointLight position={[0, 1.6, -4.2]} color="#fffbeb" intensity={8} distance={7} decay={2} />
    </group>
  )
}

function AGV({ position = [0, 0, 0], rotation = [0, 0, 0], withBox = false, label = 'AGV' }) {
 const tx = useUiText()

  const bodyRef  = useRef()
  const glowRef  = useRef()

  useFrame(({ clock }) => {
    if (glowRef.current)
      glowRef.current.material.emissiveIntensity = 0.8 + Math.sin(clock.elapsedTime * 2.5) * 0.5
  })

  const metalDark = <meshStandardMaterial color="#1e293b" roughness={0.3} metalness={0.85} />
  const metalMid  = <meshStandardMaterial color="#334155" roughness={0.25} metalness={0.9} />

  return (
    <group position={position} rotation={rotation}>
      {/* body platform */}
      <mesh ref={bodyRef} position={[0, 0.22, 0]}><boxGeometry args={[1.1, 0.44, 1.5]} />{metalDark}</mesh>
      {/* top plate */}
      <mesh position={[0, 0.46, 0]}><boxGeometry args={[1.05, 0.06, 1.45]} />{metalMid}</mesh>
      {/* neon trim lines */}
      {[[-0.56, 0], [0.56, 0]].map(([x], i) => (
        <mesh ref={i === 0 ? glowRef : undefined} key={i} position={[x, 0.22, 0]}>
          <boxGeometry args={[0.04, 0.44, 1.52]} />
          <meshStandardMaterial color="#06b6d4" emissive="#06b6d4" emissiveIntensity={1.2} roughness={0.1} toneMapped={false} />
        </mesh>
      ))}
      {[0, 1].map(i => (
        <mesh key={i} position={[0, 0.22, i === 0 ? -0.76 : 0.76]}>
          <boxGeometry args={[1.12, 0.44, 0.04]} />
          <meshStandardMaterial color="#06b6d4" emissive="#06b6d4" emissiveIntensity={1.2} roughness={0.1} toneMapped={false} />
        </mesh>
      ))}
      {/* 4 small wheels */}
      {[[-0.42, -0.6], [0.42, -0.6], [-0.42, 0.6], [0.42, 0.6]].map(([x, z], i) => (
        <mesh key={i} position={[x, 0.1, z]} rotation={[Math.PI / 2, 0, 0]}>
          <cylinderGeometry args={[0.1, 0.1, 0.08, 8]} />
          <meshStandardMaterial color="#0f172a" roughness={0.9} metalness={0.2} />
        </mesh>
      ))}
      {/* optional cargo box on top */}
      {withBox && (
        <group position={[0, 0.52, 0]}>
          <mesh position={[0, 0.2, 0]}><boxGeometry args={[0.6, 0.16, 0.5]} /><meshStandardMaterial color="#854d0e" roughness={0.9} metalness={0} /></mesh>
          <mesh position={[0, 0.44, 0]}><boxGeometry args={[0.55, 0.38, 0.46]} /><meshStandardMaterial color="#92400e" roughness={0.85} metalness={0} /></mesh>
        </group>
      )}
      {/* label */}
      <Html position={[0, 1.1, 0]} center distanceFactor={10} style={{ pointerEvents: 'none' }} zIndexRange={[0, 0]}>
        <div style={{ background: 'rgba(6,182,212,0.15)', border: '1px solid #06b6d4', color: '#67e8f9', fontSize: '10px', fontWeight: 700, padding: '1px 7px', borderRadius: 4, whiteSpace: 'nowrap', pointerEvents: 'none', fontFamily: 'monospace', textShadow: '0 0 6px #06b6d4' }}>
          {label}{tx(": Delivering")} </div>
      </Html>
      <pointLight position={[0, 0.3, 0]} color="#06b6d4" intensity={4} distance={4} decay={2} />
    </group>
  )
}

function SkidMarks() {
  const marks = useMemo(() => [
    // large loop near zone centre (forklift turning)
    { x:  2,   z: -5,  r: 6.0, thick: 0.13, segments: 56, arc: Math.PI * 1.6, rot: 0.2  },
    // forklift near Zone C/D
    { x: 20,   z: -2,  r: 4.2, thick: 0.10, segments: 48, arc: Math.PI * 1.0, rot: -0.5 },
    // second forklift Zone A side
    { x: -18,  z: -3,  r: 3.8, thick: 0.09, segments: 40, arc: Math.PI * 0.8, rot: 1.8  },
    // truck turning arc near loading dock
    { x: -28,  z: 10,  r: 7.0, thick: 0.14, segments: 56, arc: Math.PI * 0.6, rot: 0.1  },
    { x: -26,  z:  8,  r: 5.5, thick: 0.08, segments: 44, arc: Math.PI * 0.5, rot: 0.15 },
    // AGV trails
    { x:  -7,  z: -3,  r: 2.0, thick: 0.06, segments: 28, arc: Math.PI * 1.8, rot: 0.9  },
    { x:  10,  z: -2,  r: 2.5, thick: 0.06, segments: 28, arc: Math.PI * 1.2, rot: -1.1 },
  ], [])

  return (
    <>
      {marks.map((m, idx) => {
        const pts = []
        for (let i = 0; i <= m.segments; i++) {
          const a = (i / m.segments) * m.arc
          pts.push(new THREE.Vector3(Math.cos(a) * m.r, 0, Math.sin(a) * m.r))
        }
        const curve  = new THREE.CatmullRomCurve3(pts)
        const geom   = new THREE.TubeGeometry(curve, m.segments, m.thick, 4, false)
        return (
          <mesh key={idx} geometry={geom} position={[m.x, 0.008, m.z]} rotation={[0, m.rot, 0]}>
            <meshStandardMaterial color="#0f172a" roughness={1} metalness={0} transparent opacity={0.55} depthWrite={false} />
          </mesh>
        )
      })}
    </>
  )
}

function PalletStack({ position }) {
  return (
    <group position={position}>
      <mesh position={[0, 0.09, 0]}><boxGeometry args={[1.2, 0.18, 0.9]} /><meshStandardMaterial color="#78350f" roughness={0.9} metalness={0} /></mesh>
      {[0.4, 0.85, 1.3].map((y, i) => (
        <mesh key={i} position={[0, y, 0]}><boxGeometry args={[1.0, 0.38, 0.78]} /><meshStandardMaterial color="#92400e" roughness={0.85} metalness={0} /></mesh>
      ))}
    </group>
  )
}

function shelfAvailableQuantity(product) {
  return Number(product?.shelf_available_quantity ?? getInventoryQuantity(product, 'available'))
}

function ShelfModal({ shelf, products, loading, onClose }) {
 const tx = useUiText()
 const dialogRef=useDialog(onClose)

  const totalQty   = products.reduce((s, p) => s + shelfAvailableQuantity(p), 0)
  const totalValue = products.reduce((s, p) => s + shelfAvailableQuantity(p) * parseFloat(p.price ?? 0), 0)
  const lowStockProducts = products.filter(p => shelfAvailableQuantity(p) <= Number(p.min_quantity ?? 0))
  const status     = loading ? null : totalQty === 0 ? 'out' : lowStockProducts.length > 0 ? 'low' : 'ok'
  const statusLabel = { ok: 'Healthy', low: 'Low Stock', out: 'Out of Stock', null: '…' }[status]
  const statusColor = { ok: '#22c55e', low: '#fb923c', out: '#ef4444', null: '#94a3b8' }[status]

  return (
    <div ref={dialogRef} role="dialog" aria-modal="true" aria-label={`${tx('Shelf')} ${shelf.id}`} style={{ position: 'fixed', inset: '0 0 0 auto', width: 'min(440px, 100vw)', background: '#0a0f1e', borderLeft: '1px solid #1e293b', zIndex: 100, display: 'flex', flexDirection: 'column', boxShadow: '-12px 0 48px rgba(0,0,0,0.8)' }}>

      {/* Header */}
      <div style={{ padding: '18px 20px 14px', borderBottom: '1px solid #1e293b', borderLeft: `3px solid ${shelf.zoneColor}` }}>
        <div style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.12em', marginBottom: 4 }}>
          {shelf.floorLevel > 1 ? `Level ${shelf.floorLevel} · ` : ''}{shelf.zoneLabel}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
          <div>
            <span style={{ fontSize: 24, fontWeight: 800, color: 'white', fontFamily: 'monospace' }}>{tx("Shelf")} {shelf.id}</span>
            <span style={{ marginLeft: 10, fontSize: 12, fontWeight: 700, color: statusColor, background: `${statusColor}18`, border: `1px solid ${statusColor}44`, padding: '2px 10px', borderRadius: 20 }}>
              {tx(statusLabel)}
            </span>
          </div>
          <button aria-label={tx('Close')} onClick={onClose} style={{ background: 'none', border: 'none', color: '#94a3b8', fontSize: 22, cursor: 'pointer', lineHeight: 1, padding: '4px 8px', borderRadius: 6 }}>×</button>
        </div>
      </div>

      {/* KPI strip */}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10, padding: '14px 16px', borderBottom: '1px solid #1e293b' }}>
        {[
          { label: 'Total Units', value: loading ? '…' : totalQty, color: statusColor },
          { label: 'SKUs',        value: loading ? '…' : products.length, color: '#94a3b8' },
          { label: 'Value (€)',   value: loading ? '…' : `€${totalValue.toFixed(0)}`, color: '#a78bfa' },
        ].map(({ label, value, color }) => (
          <div key={label} style={{ background: '#111827', borderRadius: 8, padding: '10px 12px', textAlign: 'center', border: '1px solid #1e293b' }}>
            <div style={{ fontSize: 10, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.1em', marginBottom: 4 }}>{tx(label)}</div>
            <div style={{ fontSize: 20, fontWeight: 800, color }}>{value}</div>
          </div>
        ))}
      </div>

      {/* Product table */}
      <div style={{ flex: 1, overflowY: 'auto', padding: '14px 16px' }}>
        {/* Table header */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 80px 70px 70px', gap: 6, padding: '0 10px 8px', borderBottom: '1px solid #1e293b', marginBottom: 6 }}>
          {['Product', 'SKU', 'Qty', 'Price'].map(h => (
            <div key={h} style={{ fontSize: 10, color: '#94a3b8', textTransform: 'uppercase', letterSpacing: '0.1em', fontWeight: 700 }}>{tx(h)}</div>
          ))}
        </div>

        {loading ? (
          <div style={{ textAlign: 'center', color: '#94a3b8', padding: '32px 0', fontSize: 13 }}>{tx("Fetching products…")}</div>
        ) : products.length === 0 ? (
          <div style={{ textAlign: 'center', color: '#94a3b8', padding: '32px 0' }}>
            <div style={{ fontSize: 32, marginBottom: 8 }}>📦</div>
            <div style={{ fontSize: 13, color: '#94a3b8' }}>{tx("No products assigned to shelf")} {shelf.id} {tx("yet.")}</div>
            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 4 }}>{tx("Assign products to this location in Warehouses & Transfers.")}</div>
          </div>
        ) : products.map(p => {
          const availableQuantity = shelfAvailableQuantity(p)
          const isLow = availableQuantity <= Number(p.min_quantity ?? 0)
          const isOut = availableQuantity === 0
          const qColor = isOut ? '#ef4444' : isLow ? '#fb923c' : '#4ade80'
          return (
            <div key={p.id} style={{ display: 'grid', gridTemplateColumns: '1fr 80px 70px 70px', gap: 6, alignItems: 'center', padding: '9px 10px', marginBottom: 4, borderRadius: 7, background: '#111827', border: `1px solid ${isOut ? '#ef444422' : isLow ? '#fb923c22' : '#1e293b'}`, transition: 'border-color .2s' }}>
              <div>
                <div style={{ color: '#e2e8f0', fontSize: 13, fontWeight: 600, lineHeight: 1.3 }}>{p.name}</div>
                {p.category?.name && <div style={{ color: '#94a3b8', fontSize: 10, marginTop: 2 }}>{p.category.name}</div>}
              </div>
              <div style={{ color: '#64748b', fontSize: 11, fontFamily: 'monospace' }}>{p.sku}</div>
              <div style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                <span style={{ fontSize: 16, fontWeight: 800, color: qColor }}>{availableQuantity}</span>
                <span style={{ fontSize: 10, color: '#94a3b8' }}>{p.unit ?? 'pcs'}</span>
              </div>
              <div style={{ color: '#94a3b8', fontSize: 13, fontWeight: 600 }}>€{parseFloat(p.price).toFixed(2)}</div>
            </div>
          )
        })}
      </div>

      {/* Footer — min_quantity warning summary */}
      {!loading && lowStockProducts.length > 0 && (
        <div style={{ padding: '10px 16px', background: '#1c0a0a', borderTop: '1px solid #7f1d1d', fontSize: 12, color: '#fca5a5', display: 'flex', alignItems: 'center', gap: 8 }}>
          <span>⚠</span>
          <span>{lowStockProducts.length} {tx("product(s) below minimum stock level")}</span>
        </div>
      )}
    </div>
  )
}

export default function Warehouse3DMap() {
 const tx = useUiText()

  const { token, user } = useAuthStore()
  const navigate = useNavigate()
  const [loadError,setLoadError] = useState('')

  const [zones, setZones] = useState([])
  const [allShelves, setAllShelves] = useState([])
  const [stockData, setStockData] = useState({})
  const [activityScores, setActivityScores] = useState({})
  const [heatmapActive, setHeatmapActive] = useState(false)
  const [selectedShelf, setSelectedShelf] = useState(null)
  const [shelfProducts, setShelfProducts] = useState([])
  const [loadingProducts, setLoadingProducts] = useState(false)
  const [layoutLoading, setLayoutLoading] = useState(true)
  const [warehouse, setWarehouse] = useState(null)
  const [warehouses, setWarehouses] = useState([])
  const [warehouseId, setWarehouseId] = useState('')
  const [floorFilter, setFloorFilter] = useState('all')

  const loadStockLevels = useCallback(async (shelfList) => {
    if (!token || !shelfList.length) return
    try {
      const products = await getAllProducts()
      const { levels } = buildShelfStockMap(products, shelfList)
      setStockData(levels)
    } catch (error) {
      setLoadError(error.message || tx('Could not load stock.'))
      setStockData({})
    }
  }, [token])

  useEffect(() => {
    if (!token) return
    setLayoutLoading(true)
    setLoadError('')
    getWarehouseLayout(warehouseId || undefined)
      .then((data) => {
        setWarehouse(data.warehouse ?? null)
        setWarehouses(data.warehouses ?? [])
        setWarehouseId((current) => current || String(data.warehouse?.id ?? ''))
        const shelves = sectionsToShelves(data.sections ?? [])
        setAllShelves(shelves)
        setZones(sectionsToZones(data.sections ?? []))
        return loadStockLevels(shelves)
      })
      .catch((error) => {
        setLoadError(error.message || tx('Could not load warehouse layout.'))
        setAllShelves([])
        setZones([])
      })
      .finally(() => setLayoutLoading(false))
  }, [token, loadStockLevels, warehouseId])

  useEffect(() => {
    if (!token) return
    getAllProducts().then((products) => {
      const locMap = {}
      products.forEach((p) => {
        if (!p.location_code) return
        const shelf = allShelves.find((s) => productMatchesShelf(p, s))
        if (shelf) locMap[p.id] = shelf.id
      })
      apiRequest('/dashboard/activity-feed?limit=100').then((data) => {
        const s = {}
        ;(data.feed || []).forEach((ev) => {
          const id = locMap[ev.entity_id]
          if (id) s[id] = (s[id] || 0) + 1
        })
        setActivityScores(s)
      }).catch(() => {})
    }).catch(() => {})
  }, [token, allShelves])

  useEffect(() => {
    const echo = getEcho()
    if (!echo || !user?.company_id) return
    const ch = echo.private(`company.${user.company_id}`)

    const refreshShelf = (product) => {
      const shelf = allShelves.find((s) => productMatchesShelf(product, s))
      if (!shelf) {
        loadStockLevels(allShelves)
        return
      }
      loadStockLevels(allShelves)
      setActivityScores((prev) => ({ ...prev, [shelf.id]: (prev[shelf.id] || 0) + 1 }))
    }

    ch.listen('.StockUpdated', (e) => {
      if (e?.product) refreshShelf(e.product)
      else loadStockLevels(allShelves)
    })

    ch.listen('.StockMovement', () => loadStockLevels(allShelves))

    ch.listen('.LowStockDetected', () => loadStockLevels(allShelves))

    return () => {
      ch.stopListening('.StockUpdated')
        .stopListening('.StockMovement')
        .stopListening('.LowStockDetected')
    }
  }, [user?.company_id, allShelves, loadStockLevels])

  const handleShelfClick = async (shelf) => {
    setSelectedShelf(shelf)
    setShelfProducts([])
    setLoadingProducts(true)
    try {
      // Use dedicated shelf endpoint — returns only products with location_code = shelfId
      const items = await apiRequest(`/shelves/${encodeURIComponent(shelf.id)}/products?warehouse_id=${warehouse.id}`)
      setShelfProducts(Array.isArray(items) ? items : [])
    } catch { setShelfProducts([]) }
    finally { setLoadingProducts(false) }
  }

  const lowStockCount = Object.values(stockData).filter((v) => v !== null && v > 0 && v < 100).length

  const lengthM = Number(warehouse?.length_m) || 80
  const widthM = Number(warehouse?.width_m) || 90
  const floorCount = Number(warehouse?.floor_count) || 1
  const floorLevels = Array.from({ length: floorCount }, (_, i) => i + 1)
  const visibleShelves = floorFilter === 'all'
    ? allShelves
    : allShelves.filter((s) => s.floorLevel === floorFilter)
  const visibleZones = floorFilter === 'all'
    ? zones
    : zones.filter((z) => z.shelves.some((s) => s.floorLevel === floorFilter))

  return (
    <div className="warehouse-3d-page" style={{ position: 'relative', width: '100%', height: '100vh', background: '#060c18', overflow: 'hidden' }}>

      {(layoutLoading||loadError)&&<p role={loadError?'alert':'status'} style={{position:'absolute',bottom:16,left:16,zIndex:20,background:'#111827',color:'#e2e8f0',padding:12,borderRadius:8}}>{loadError||tx('Loading layout...')}</p>}
      {/* Toolbar */}
      <div className="warehouse-3d-toolbar" style={{ position: 'absolute', top: 16, left: 16, right: 16, zIndex: 10, display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', pointerEvents: 'none' }}>
        <div className="warehouse-3d-heading">
          <div style={{ color: 'white', fontSize: 18, fontWeight: 700 }}>{tx("3D Warehouse Map")}</div>
          <div style={{ color: '#64748b', fontSize: 12, marginTop: 2 }}>
            {allShelves.length} {tx("sections ·")} {floorCount} {tx("Floors")} &nbsp;·&nbsp;
            <span style={{ color: lowStockCount > 0 ? '#fb923c' : '#4ade80' }}>{lowStockCount} {tx("low-stock sections")}</span>
            &nbsp;·&nbsp;
            <button type="button" onClick={() => navigate('/warehouse-layout')} style={{ background: 'none', border: 'none', color: '#60a5fa', cursor: 'pointer', fontSize: 12, padding: 0 }}>{tx("Edit layout")}</button>
          </div>
          <div style={{ display: 'flex', gap: 6, marginTop: 8, pointerEvents: 'auto', flexWrap: 'wrap' }}>
            {warehouses.length > 1 && (
              <select
                aria-label={tx("Warehouse")}
                value={warehouseId}
                onChange={(event) => { setSelectedShelf(null); setFloorFilter('all'); setWarehouseId(event.target.value) }}
                style={{ padding: '4px 10px', borderRadius: 6, fontSize: 11, fontWeight: 700, border: '1px solid #334155', background: 'rgba(6,12,24,0.92)', color: '#e2e8f0' }}
              >
                {warehouses.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.code}</option>)}
              </select>
            )}
            <button
              type="button"
              onClick={() => setFloorFilter('all')}
              style={{
                padding: '4px 10px', borderRadius: 6, fontSize: 11, fontWeight: 600, cursor: 'pointer',
                border: `1px solid ${floorFilter === 'all' ? '#60a5fa' : '#334155'}`,
                background: floorFilter === 'all' ? 'rgba(96,165,250,0.2)' : 'rgba(6,12,24,0.88)',
                color: floorFilter === 'all' ? '#93c5fd' : '#94a3b8',
              }}
            > {tx("All floors")} </button>
            {floorLevels.map((level) => (
              <button
                key={level}
                type="button"
                onClick={() => setFloorFilter(level)}
                style={{
                  padding: '4px 10px', borderRadius: 6, fontSize: 11, fontWeight: 600, cursor: 'pointer',
                  border: `1px solid ${floorFilter === level ? '#60a5fa' : '#334155'}`,
                  background: floorFilter === level ? 'rgba(96,165,250,0.2)' : 'rgba(6,12,24,0.88)',
                  color: floorFilter === level ? '#93c5fd' : '#94a3b8',
                }}
              > {tx("Level")} {level}
              </button>
            ))}
          </div>
        </div>
        <div className="warehouse-3d-actions" style={{ display: 'flex', alignItems: 'center', gap: 12, pointerEvents: 'auto' }}>
          <button
            onClick={() => navigate(-1)}
            style={{
              display: 'flex', alignItems: 'center', gap: 7, padding: '8px 16px', borderRadius: 8,
              background: 'rgba(6,12,24,0.88)', backdropFilter: 'blur(8px)',
              border: '1px solid #1e293b', cursor: 'pointer', fontSize: 13, fontWeight: 600,
              color: '#94a3b8',
            }}
            onMouseEnter={e => { e.currentTarget.style.background = 'rgba(30,48,80,0.95)'; e.currentTarget.style.color = '#e2e8f0' }}
            onMouseLeave={e => { e.currentTarget.style.background = 'rgba(6,12,24,0.88)'; e.currentTarget.style.color = '#94a3b8' }}
          > {tx("← Back")} </button>
          {!heatmapActive && (
            <div style={{ display: 'flex', gap: 12, background: 'rgba(6,12,24,0.88)', backdropFilter: 'blur(8px)', border: '1px solid #1e293b', borderRadius: 8, padding: '8px 14px', fontSize: 12, color: '#94a3b8', alignItems: 'center' }}>
              {[['#22c55e','Healthy'],['#fb923c','Low Stock'],['#ef4444','Out of Stock']].map(([c, l]) => (
                <span key={l} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <span style={{ width: 10, height: 10, borderRadius: '50%', background: c, boxShadow: `0 0 6px ${c}`, display: 'inline-block' }} />
                  {tx(l)}
                </span>
              ))}
            </div>
          )}
          <button
            onClick={() => setHeatmapActive(v => !v)}
            style={{
              display: 'flex', alignItems: 'center', gap: 8, padding: '8px 16px', borderRadius: 8,
              border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 600,
              background: heatmapActive ? 'linear-gradient(135deg,#f59e0b,#ef4444)' : 'rgba(6,12,24,0.88)',
              color: heatmapActive ? 'white' : '#94a3b8',
              boxShadow: heatmapActive ? '0 0 20px rgba(245,158,11,0.4)' : 'none',
              backdropFilter: 'blur(8px)', outline: heatmapActive ? 'none' : '1px solid #1e293b',
            }}
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            {heatmapActive ? tx("Heatmap ON") : tx("Toggle 3D Heatmap View")}
          </button>
        </div>
      </div>

      <Canvas
        camera={{ position: [28, 22, 28], fov: 48, near: 0.1, far: 300 }}
        style={{ height: '100%' }}
        gl={{ antialias: true, toneMapping: THREE.ACESFilmicToneMapping, toneMappingExposure: 1.2 }}
      >
        <color attach="background" args={['#060c18']} />
        <fog attach="fog" args={['#0d1626', 42, 90]} />

        <ambientLight intensity={0.85} color="#d6e0f5" />

        {/* Soft fill from top-right */}
        <directionalLight position={[20, 35, 20]} intensity={3.5} color="#eef2ff" />
        <directionalLight position={[-20, 20, 5]} intensity={1.2} color="#99aadd" />

        {/* Zone spot-lights — one per zone, high up, pointing down */}
        {[
          { pos: [-14.5, 20, -14], color: '#a5b4fc', zone: 'A' }, // Zone A — indigo
          { pos: [  1.5, 20, -14], color: '#fde68a', zone: 'B' }, // Zone B — amber
          { pos: [ 17.5, 20, -14], color: '#6ee7b7', zone: 'C' }, // Zone C — emerald
          { pos: [  2.5, 18,   4], color: '#c4b5fd', zone: 'D' }, // Zone D — violet
        ].map(({ pos, color }) => (
          <spotLight
            key={color}
            position={pos}
            target-position={[pos[0], 0, pos[2]]}
            color={color}
            intensity={120}
            angle={Math.PI / 5}
            penumbra={0.55}
            distance={30}
            decay={2}
            castShadow={false}
          />
        ))}

        {/* Overhead white floods (forklift + truck area) */}
        <pointLight position={[-28, 12,  4]}  intensity={22} color="#f0f4ff" distance={18} decay={2} />
        <pointLight position={[ 26, 12, -6]}  intensity={22} color="#f0f4ff" distance={18} decay={2} />
        <pointLight position={[  2, 16, -8]}  intensity={30} color="#f0f4ff" distance={38} decay={2} />

        <ZoneLights zones={visibleZones} />

        {floorLevels.map((level) => {
          const y = (level - 1) * STANDARD_FLOOR_HEIGHT
          const dimmed = floorFilter !== 'all' && floorFilter !== level
          return (
            <group key={level}>
              <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, y, 0]} receiveShadow>
                <planeGeometry args={[lengthM, widthM]} />
                <meshStandardMaterial
                  color="#2e3d50"
                  roughness={0.35}
                  metalness={0.15}
                  transparent={dimmed}
                  opacity={dimmed ? 0.25 : 1}
                />
              </mesh>
              {level < floorCount && (
                <mesh position={[0, y + STANDARD_FLOOR_HEIGHT, 0]}>
                  <boxGeometry args={[lengthM + 0.4, 0.35, widthM + 0.4]} />
                  <meshStandardMaterial color="#1e293b" roughness={0.9} metalness={0.1} transparent opacity={dimmed ? 0.2 : 0.85} />
                </mesh>
              )}
              <Html position={[-lengthM / 2 + 2, y + 6, -widthM / 2 + 2]} distanceFactor={28} style={{ pointerEvents: 'none' }} zIndexRange={[0, 0]}>
                <div style={{ background: 'rgba(0,0,0,0.7)', color: '#94a3b8', fontSize: 11, fontWeight: 700, padding: '3px 8px', borderRadius: 4, fontFamily: 'monospace' }}> {tx("LEVEL")} {level}
                </div>
              </Html>
            </group>
          )
        })}

        <mesh position={[0, floorCount * STANDARD_FLOOR_HEIGHT + 2, 0]} rotation={[Math.PI / 2, 0, 0]}>
          <planeGeometry args={[lengthM + 4, widthM + 4]} />
          <meshStandardMaterial color="#1e2a38" roughness={1} metalness={0} />
        </mesh>

        <SkidMarks />

        {/* racks */}
        {visibleZones.map(zone => <ZoneLabel key={zone.id} zone={zone} />)}
        {visibleShelves.map(shelf => (
          <ShelfUnit
            key={`${shelf.floorLevel}-${shelf.id}`}
            shelf={shelf}
            stockLevel={stockData[shelf.id] ?? null}
            heatmap={heatmapActive}
            activityScore={activityScores[shelf.id] ?? 0}
            onClick={handleShelfClick}
          />
        ))}

        <Forklift position={[24, 0, -6]}  rotation={[0, -0.6, 0]} />
        <Forklift position={[-22, 0, -4]} rotation={[0,  2.2, 0]} />

        <DeliveryTruck position={[-32, 0, 8]} rotation={[0, Math.PI / 2, 0]} />

        <AGV position={[-7,  0, -5]} rotation={[0,  0.4, 0]} withBox  label="AGV 01" />
        <AGV position={[10,  0, -4]} rotation={[0, -0.3, 0]}           label="AGV 02" />

        {/* static pallet stacks near loading dock */}
        <PalletStack position={[-28, 0,  2]} />
        <PalletStack position={[-28, 0, -4]} />
        <PalletStack position={[-28, 0,  8]} />

        <OrbitControls
          makeDefault
          enableDamping
          dampingFactor={0.06}
          minDistance={0.5}
          maxDistance={Math.max(120, lengthM * 1.5)}
          maxPolarAngle={Math.PI / 2.05}
          target={[0, (floorCount * STANDARD_FLOOR_HEIGHT) / 2, 0]}
        />
      </Canvas>

      {selectedShelf && (
        <>
          <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.45)', zIndex: 90 }} onClick={() => setSelectedShelf(null)} />
          <ShelfModal
            shelf={selectedShelf}
            products={shelfProducts}
            loading={loadingProducts}
            onClose={() => setSelectedShelf(null)}
          />
        </>
      )}
    </div>
  )
}
