import { useEffect, useState, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import { getDashboard } from '../api/dashboard'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../store/authStore'
import { getEcho } from '../lib/echo'
import {
  AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts'
import {
  TrendingUp, Package, AlertTriangle, DollarSign, Activity,
  Zap, RefreshCw, Search, FileText, ArrowUpRight, ArrowDownRight,
  Boxes, BarChart3, ShieldCheck, Clock, ChevronRight, Flame,
} from 'lucide-react'

// ─── Design tokens ────────────────────────────────────────────────────────────
const C = {
  bg:      '#f1f5f9',
  surface: '#ffffff',
  card:    '#ffffff',
  border:  '#e2e8f0',
  text:    '#0f172a',
  muted:   '#64748b',
  green:   '#16a34a',
  amber:   '#d97706',
  red:     '#dc2626',
  blue:    '#2563eb',
  indigo:  '#4f46e5',
  cyan:    '#0891b2',
  purple:  '#7c3aed',
}

const ZONE_COLORS = ['#6366f1', '#f59e0b', '#10b981', '#8b5cf6']

// ─── Helpers ──────────────────────────────────────────────────────────────────
const fmtMoney = (n) =>
  n == null ? '—' : `€${Number(n).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`

const fmt = (n) =>
  n == null ? '—' : Number(n).toLocaleString('en')

function timeAgo(ts) {
  if (!ts) return ''
  const secs = Math.floor((Date.now() - new Date(ts).getTime()) / 1000)
  if (secs < 60)    return `${secs}s ago`
  if (secs < 3600)  return `${Math.floor(secs / 60)}m ago`
  if (secs < 86400) return `${Math.floor(secs / 3600)}h ago`
  return new Date(ts).toLocaleDateString()
}

// ─── KPI card ─────────────────────────────────────────────────────────────────
function KpiCard({ icon: Icon, label, value, sub, color, glow, trend }) {
  return (
    <div style={{
      background: C.card, border: `1px solid ${C.border}`, borderRadius: 14,
      padding: '20px 22px', display: 'flex', flexDirection: 'column', gap: 10,
      boxShadow: glow ? `0 0 28px ${color}22` : 'none',
      position: 'relative', overflow: 'hidden',
    }}>
      <div style={{ position: 'absolute', top: -18, right: -18, width: 80, height: 80, borderRadius: '50%', background: `${color}18`, filter: 'blur(14px)', pointerEvents: 'none' }} />
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <div style={{ background: `${color}1a`, border: `1px solid ${color}44`, borderRadius: 10, padding: 9, display: 'inline-flex' }}>
          <Icon size={18} color={color} />
        </div>
        {trend != null && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 4, fontSize: 12, fontWeight: 700, color: trend >= 0 ? C.green : C.red }}>
            {trend >= 0 ? <ArrowUpRight size={14} /> : <ArrowDownRight size={14} />}
            {Math.abs(trend)}%
          </div>
        )}
      </div>
      <div>
        <div style={{ fontSize: 26, fontWeight: 800, color: C.text, letterSpacing: '-0.02em', lineHeight: 1.1 }}>{value}</div>
        <div style={{ fontSize: 13, color: C.muted, marginTop: 4 }}>{label}</div>
        {sub && <div style={{ fontSize: 11, color, marginTop: 3, fontWeight: 600 }}>{sub}</div>}
      </div>
    </div>
  )
}

// ─── Section header ────────────────────────────────────────────────────────────
function SectionTitle({ children, icon: Icon, color = C.cyan, badge }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: 16 }}>
      <div style={{ background: `${color}18`, border: `1px solid ${color}44`, borderRadius: 8, padding: 7, display: 'inline-flex' }}>
        <Icon size={15} color={color} />
      </div>
      <span style={{ fontSize: 15, fontWeight: 700, color: C.text }}>{children}</span>
      {badge != null && (
        <span style={{ fontSize: 11, background: `${color}1a`, color, border: `1px solid ${color}33`, borderRadius: 99, padding: '2px 9px', marginLeft: 2 }}>
          {badge}
        </span>
      )}
    </div>
  )
}

// ─── Chart wrapper ─────────────────────────────────────────────────────────────
function ChartCard({ title, icon, color, children, style }) {
  return (
    <div style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 14, padding: '20px 22px', ...style }}>
      <SectionTitle icon={icon} color={color}>{title}</SectionTitle>
      {children}
    </div>
  )
}

// ─── Custom recharts tooltip ───────────────────────────────────────────────────
function ChartTip({ active, payload, label }) {
  if (!active || !payload?.length) return null
  return (
    <div style={{ background: '#0d1829', border: `1px solid ${C.border}`, borderRadius: 8, padding: '10px 14px', fontSize: 12 }}>
      {label && <div style={{ color: C.muted, marginBottom: 6 }}>{label}</div>}
      {payload.map(p => (
        <div key={p.name} style={{ color: p.color, fontWeight: 700 }}>{p.name}: {p.value}</div>
      ))}
    </div>
  )
}

// ─── Activity feed item ────────────────────────────────────────────────────────
function FeedItem({ item, isNew }) {
  const actionColor = {
    create: C.green, update: C.cyan, delete: C.red,
    stock_in: C.green, stock_out: C.amber,
  }[String(item.action).toLowerCase()] ?? C.indigo

  return (
    <div style={{
      display: 'flex', gap: 12, padding: '10px 14px', borderRadius: 9,
      background: isNew ? `${actionColor}0d` : 'transparent',
      border: `1px solid ${isNew ? actionColor + '33' : 'transparent'}`,
      transition: 'all .4s ease',
      animation: isNew ? 'slideIn .35s ease' : 'none',
    }}>
      <div style={{ width: 8, height: 8, borderRadius: '50%', background: actionColor, marginTop: 5, flexShrink: 0, boxShadow: `0 0 6px ${actionColor}` }} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13, color: C.text, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
          <span style={{ color: actionColor, textTransform: 'capitalize' }}>{item.action}</span>
          {' · '}
          {item.entity ?? 'Product'} #{item.entity_id ?? item.id}
        </div>
        <div style={{ fontSize: 11, color: C.muted, marginTop: 2 }}>
          {item.user ?? item.causer_name ?? 'System'} · {timeAgo(item.ts ?? item.created_at)}
        </div>
      </div>
    </div>
  )
}

// ─── Low stock alert ───────────────────────────────────────────────────────────
function AlertCard({ product }) {
  const navigate = useNavigate()
  const pct = product.min_quantity > 0
    ? Math.round((product.quantity / product.min_quantity) * 100)
    : 0
  const isOut = product.quantity === 0
  const color = isOut ? C.red : C.amber

  return (
    <div style={{
      background: `${color}0a`, border: `1px solid ${color}44`,
      borderRadius: 10, padding: '12px 14px', marginBottom: 8,
      animation: 'fadeIn .3s ease',
    }}>
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 8, marginBottom: 8 }}>
        <div>
          <div style={{ fontSize: 13, fontWeight: 700, color: C.text }}>{product.name}</div>
          <div style={{ fontSize: 11, color: C.muted, marginTop: 2 }}>{product.sku} · min {product.min_quantity}</div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 5, flexShrink: 0 }}>
          <Flame size={13} color={color} />
          <span style={{ fontSize: 16, fontWeight: 800, color }}>{product.quantity}</span>
          <span style={{ fontSize: 11, color: C.muted }}>left</span>
        </div>
      </div>
      <div style={{ background: C.border, borderRadius: 99, height: 5, overflow: 'hidden' }}>
        <div style={{
          height: '100%', borderRadius: 99, width: `${Math.min(pct, 100)}%`,
          background: `linear-gradient(90deg, ${color}, ${color}88)`,
          transition: 'width .6s ease', boxShadow: `0 0 6px ${color}`,
        }} />
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 6 }}>
        <span style={{ fontSize: 10, color: C.muted }}>{pct}% of minimum</span>
        <button
          onClick={() => navigate('/stock')}
          style={{ fontSize: 11, color, background: 'none', border: 'none', cursor: 'pointer', fontWeight: 700, padding: 0 }}
        >
          + Restock →
        </button>
      </div>
    </div>
  )
}

// ─── Quick actions panel ───────────────────────────────────────────────────────
function QuickActions() {
  const navigate  = useNavigate()
  const [query,   setQuery]    = useState('')
  const [results, setResults]  = useState([])
  const [busy,    setBusy]     = useState(false)
  const timer = useRef(null)

  const handleSearch = (v) => {
    setQuery(v)
    clearTimeout(timer.current)
    if (!v.trim()) { setResults([]); return }
    timer.current = setTimeout(async () => {
      setBusy(true)
      try {
        const data = await apiRequest(`/search?q=${encodeURIComponent(v)}&limit=5`)
        setResults(data.results ?? data ?? [])
      } catch { setResults([]) }
      finally { setBusy(false) }
    }, 320)
  }

  const ACTIONS = [
    { label: 'Adjust Stock',  icon: Package,  color: C.cyan,   path: '/stock' },
    { label: 'New Invoice',   icon: FileText, color: C.green,  path: '/invoices' },
    { label: 'View Reports',  icon: BarChart3, color: C.indigo, path: '/reports' },
  ]

  return (
    <div style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 14, padding: '20px 22px' }}>
      <SectionTitle icon={Zap} color={C.amber}>Quick Actions</SectionTitle>

      <div style={{ position: 'relative', marginBottom: 14 }}>
        <Search size={14} color={C.muted} style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', pointerEvents: 'none' }} />
        <input
          value={query}
          onChange={e => handleSearch(e.target.value)}
          placeholder="Search products, SKUs…"
          style={{
            width: '100%', background: C.surface, border: `1px solid ${C.border}`,
            borderRadius: 8, padding: '9px 36px 9px 34px', color: C.text,
            fontSize: 13, outline: 'none', boxSizing: 'border-box',
          }}
          onFocus={e => e.target.style.borderColor = C.cyan}
          onBlur={e => { e.target.style.borderColor = C.border; setTimeout(() => setResults([]), 200) }}
        />
        {busy && <RefreshCw size={12} color={C.muted} style={{ position: 'absolute', right: 12, top: '50%', transform: 'translateY(-50%)', animation: 'spin 1s linear infinite' }} />}
      </div>

      {results.length > 0 && (
        <div style={{ background: C.surface, border: `1px solid ${C.border}`, borderRadius: 8, marginBottom: 14, overflow: 'hidden' }}>
          {results.map((r, i) => (
            <div
              key={i}
              onMouseDown={() => { setQuery(''); setResults([]); navigate('/products') }}
              style={{ padding: '9px 14px', borderBottom: i < results.length - 1 ? `1px solid ${C.border}` : 'none', cursor: 'pointer', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}
              onMouseEnter={e => e.currentTarget.style.background = C.card}
              onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
            >
              <div>
                <div style={{ fontSize: 13, color: C.text, fontWeight: 600 }}>{r.name ?? r}</div>
                {r.sku && <div style={{ fontSize: 11, color: C.muted }}>{r.sku}</div>}
              </div>
              <ChevronRight size={13} color={C.muted} />
            </div>
          ))}
        </div>
      )}

      {ACTIONS.map(({ label, icon: Icon, color, path }) => (
        <button
          key={label}
          onClick={() => navigate(path)}
          style={{
            width: '100%', display: 'flex', alignItems: 'center', gap: 10,
            background: `${color}0f`, border: `1px solid ${color}33`,
            borderRadius: 9, padding: '11px 14px', color, fontSize: 13, fontWeight: 700,
            cursor: 'pointer', marginBottom: 8, transition: 'all .2s',
          }}
          onMouseEnter={e => { e.currentTarget.style.background = `${color}1a`; e.currentTarget.style.borderColor = `${color}66` }}
          onMouseLeave={e => { e.currentTarget.style.background = `${color}0f`; e.currentTarget.style.borderColor = `${color}33` }}
        >
          <Icon size={15} />
          {label}
          <ChevronRight size={13} style={{ marginLeft: 'auto' }} />
        </button>
      ))}
    </div>
  )
}

// ─── Main dashboard ────────────────────────────────────────────────────────────
export default function Dashboard() {
  const navigate = useNavigate()
  const { user }  = useAuthStore()

  const [data,        setData]       = useState(null)
  const [loading,     setLoading]    = useState(true)
  const [feed,        setFeed]       = useState([])
  const [lowAlerts,   setLowAlerts]  = useState([])
  const [newFeedIds,  setNewFeedIds] = useState(new Set())
  const [echoStatus,  setEchoStatus] = useState('connecting')
  const [lastRefresh, setLastRefresh] = useState(null)

  const loadDashboard = useCallback(async () => {
    try {
      const summary = await getDashboard()
      setData(summary)
      setLastRefresh(new Date())
    } catch { /* keep stale */ }
    finally { setLoading(false) }
  }, [])

  const loadFeed = useCallback(async () => {
    try {
      const res = await apiRequest('/dashboard/activity-feed?limit=100')
      setFeed(res.feed ?? [])
    } catch { setFeed([]) }
  }, [])

  const loadAlerts = useCallback(async () => {
    try {
      const res = await apiRequest('/dashboard/low-stock-alerts')
      setLowAlerts(res.alerts ?? [])
    } catch { setLowAlerts([]) }
  }, [])

  useEffect(() => {
    loadDashboard()
    loadFeed()
    loadAlerts()
  }, [loadDashboard, loadFeed, loadAlerts])

  // Window events fired by App.jsx Echo handler
  useEffect(() => {
    const refresh = () => { loadDashboard(); loadFeed(); loadAlerts() }
    window.addEventListener('dashboard-refresh', refresh)
    window.addEventListener('stock-refresh',     refresh)
    return () => {
      window.removeEventListener('dashboard-refresh', refresh)
      window.removeEventListener('stock-refresh',     refresh)
    }
  }, [loadDashboard, loadFeed, loadAlerts])

  // Echo connection status probe
  useEffect(() => {
    const probe = () => {
      const echo = getEcho()
      if (!echo) { setEchoStatus('disconnected'); return }
      const state = echo.connector?.pusher?.connection?.state
      setEchoStatus(state === 'connected' ? 'connected' : state === 'connecting' ? 'connecting' : 'disconnected')
    }
    probe()
    const id = setInterval(probe, 2500)
    return () => clearInterval(id)
  }, [])

  // Echo: push StockUpdated into feed live
  useEffect(() => {
    const echo = getEcho()
    if (!echo || !user?.company_id) return
    const ch = echo.private(`company.${user.company_id}`)

    ch.listen('.StockUpdated', (e) => {
      if (!e?.movement) return
      const item = {
        action: e.movement.type === 'in' ? 'stock_in' : 'stock_out',
        entity: 'Product', entity_id: e.movement.product_id,
        user: user?.name ?? 'You', ts: new Date().toISOString(),
        _rid: Date.now(),
      }
      setFeed(prev => [item, ...prev].slice(0, 100))
      setNewFeedIds(prev => new Set([...prev, item._rid]))
      setTimeout(() => setNewFeedIds(prev => { const n = new Set(prev); n.delete(item._rid); return n }), 3500)
      loadDashboard()
      loadAlerts()
    })

    ch.listen('.LowStockDetected', () => { loadAlerts(); loadDashboard() })

    return () => ch.stopListening('.StockUpdated').stopListening('.LowStockDetected')
  }, [user?.company_id, loadDashboard, loadAlerts])

  // ── Derived chart data ─────────────────────────────────────────────────
  const movementChart = (() => {
    if (!data?.recent_movements?.length) return []
    const map = {}
    ;[...data.recent_movements].reverse().forEach(m => {
      const d = new Date(m.created_at).toLocaleDateString('en', { month: 'short', day: 'numeric' })
      if (!map[d]) map[d] = { date: d, In: 0, Out: 0 }
      if (m.type === 'in')  map[d].In  += m.quantity
      if (m.type === 'out') map[d].Out += m.quantity
    })
    return Object.values(map).slice(-10)
  })()

  // "Top Selling Categories" — aggregate outbound movements by category
  const categoryChart = (() => {
    const movements = data?.recent_movements ?? []
    const map = {}
    movements
      .filter(m => m.type === 'out')
      .forEach(m => {
        const k = m.product?.category?.name ?? m.category_name ?? 'Other'
        map[k] = (map[k] ?? 0) + (m.quantity ?? 0)
      })
    // fallback: if no movement category data, use all products by category
    if (!Object.keys(map).length) {
      ;(data?.low_stock_products ?? []).forEach(p => {
        const k = p.category?.name ?? 'Other'
        map[k] = (map[k] ?? 0) + 1
      })
    }
    return Object.entries(map).map(([name, value]) => ({ name, value }))
      .sort((a, b) => b.value - a.value).slice(0, 6)
  })()

  const zoneData = [
    { name: 'Zone A', value: 8 },
    { name: 'Zone B', value: 8 },
    { name: 'Zone C', value: 8 },
    { name: 'Zone D', value: 8 },
  ]

  const totalValue  = data?.total_value      ?? 0
  const totalProds  = data?.total_products   ?? 0
  const lowCnt      = (data?.low_stock_products?.length ?? 0) + (data?.out_of_stock_products?.length ?? 0)
  const turnover    = data?.stock_turnover   ?? 0
  const accuracy    = totalProds > 0 ? Math.round(((totalProds - lowCnt) / totalProds) * 100) : 100

  const sdot  = { connected: C.green, connecting: C.amber, disconnected: C.red }[echoStatus]
  const slabel = { connected: 'Live Sync', connecting: 'Connecting…', disconnected: 'Offline' }[echoStatus]

  if (loading) return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', height: '100vh', background: C.bg, color: C.muted, gap: 12, fontSize: 14 }}>
      <RefreshCw size={16} style={{ animation: 'spin 1s linear infinite' }} />
      Loading Command Center…
    </div>
  )

  const allAlerts = [...(data?.out_of_stock_products ?? []), ...(data?.low_stock_products ?? []), ...lowAlerts]
  const uniqueAlerts = allAlerts.filter((p, i, a) => a.findIndex(x => x.id === p.id) === i).slice(0, 10)

  return (
    <div style={{ background: C.bg, minHeight: '100vh', color: C.text, fontFamily: 'system-ui,sans-serif' }}>
      <style>{`
        @keyframes spin    { to { transform:rotate(360deg) } }
        @keyframes pulse   { 0%,100%{opacity:1}50%{opacity:.35} }
        @keyframes slideIn { from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)} }
        @keyframes fadeIn  { from{opacity:0}to{opacity:1} }
        ::-webkit-scrollbar{width:4px}
        ::-webkit-scrollbar-track{background:transparent}
        ::-webkit-scrollbar-thumb{background:${C.border};border-radius:4px}
      `}</style>

      {/* ── Top bar ── */}
      <div style={{
        position: 'sticky', top: 0, zIndex: 50,
        background: `${C.surface}ee`, backdropFilter: 'blur(12px)',
        borderBottom: `1px solid ${C.border}`,
        padding: '12px 28px', display: 'flex', alignItems: 'center', justifyContent: 'space-between',
      }}>
        <div>
          <div style={{ fontSize: 18, fontWeight: 800, letterSpacing: '-0.02em' }}>Inventory Command Center</div>
          <div style={{ fontSize: 11, color: C.muted, marginTop: 1 }}>
            Welcome back, <span style={{ color: C.cyan }}>{user?.name}</span>
            {lastRefresh && <span> · Updated {timeAgo(lastRefresh)}</span>}
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 7, background: `${sdot}12`, border: `1px solid ${sdot}44`, borderRadius: 99, padding: '5px 13px', fontSize: 12, fontWeight: 700, color: sdot }}>
            <span style={{ width: 7, height: 7, borderRadius: '50%', background: sdot, display: 'inline-block', boxShadow: `0 0 6px ${sdot}`, animation: echoStatus === 'connected' ? 'pulse 2s infinite' : 'none' }} />
            {slabel}
          </div>
          <button onClick={() => { loadDashboard(); loadFeed(); loadAlerts() }} style={{ background: `${C.cyan}15`, border: `1px solid ${C.cyan}44`, borderRadius: 8, padding: '7px 14px', color: C.cyan, fontSize: 12, fontWeight: 700, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
            <RefreshCw size={13} />Refresh
          </button>
          <button onClick={() => navigate('/warehouse-3d')} style={{ background: `${C.indigo}15`, border: `1px solid ${C.indigo}44`, borderRadius: 8, padding: '7px 14px', color: C.indigo, fontSize: 12, fontWeight: 700, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
            <Boxes size={13} />3D Map
          </button>
        </div>
      </div>

      <div style={{ padding: '24px 28px', maxWidth: 1600, margin: '0 auto' }}>

        {/* ── KPI row ── */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 16, marginBottom: 24 }}>
          <KpiCard icon={DollarSign}  label="Total Stock Value"        value={fmtMoney(totalValue)}             color={C.green}  glow trend={4}                          sub={`${fmt(totalProds)} SKUs tracked`} />
          <KpiCard icon={TrendingUp}  label="Inventory Turnover Rate"  value={turnover ? `${turnover}×` : '—'} color={C.cyan}       trend={turnover > 2 ? 8 : -3}        sub="Inventory cycles / period" />
          <KpiCard icon={Boxes}       label="Active Warehouses & Zones" value="4 Zones"                        color={C.purple} glow sub="A · B · C · D — All Operational" />
          <KpiCard icon={ShieldCheck} label="Fulfillment Accuracy Rate" value={`${accuracy}%`}                 color={C.indigo}     glow={accuracy < 85} trend={accuracy >= 90 ? 2 : -5} sub={lowCnt > 0 ? `${lowCnt} item${lowCnt !== 1 ? 's' : ''} need attention` : 'All stock healthy'} />
        </div>

        {/* ── Charts row ── */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 320px', gap: 16, marginBottom: 24 }}>

          {/* Inbound vs Outbound */}
          <ChartCard title="Inbound vs Outbound Movements" icon={Activity} color={C.cyan}>
            {movementChart.length === 0 ? (
              <div style={{ height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center', color: C.muted, fontSize: 13 }}>No movement data yet</div>
            ) : (
              <ResponsiveContainer width="100%" height={200}>
                <AreaChart data={movementChart}>
                  <defs>
                    <linearGradient id="gIn"  x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%"  stopColor={C.green} stopOpacity={0.3} />
                      <stop offset="95%" stopColor={C.green} stopOpacity={0} />
                    </linearGradient>
                    <linearGradient id="gOut" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%"  stopColor={C.red}   stopOpacity={0.3} />
                      <stop offset="95%" stopColor={C.red}   stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke={C.border} />
                  <XAxis dataKey="date" tick={{ fontSize: 11, fill: C.muted }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 11, fill: C.muted }} axisLine={false} tickLine={false} />
                  <Tooltip content={<ChartTip />} />
                  <Area type="monotone" dataKey="In"  stroke={C.green} strokeWidth={2} fill="url(#gIn)"  name="Inbound" />
                  <Area type="monotone" dataKey="Out" stroke={C.red}   strokeWidth={2} fill="url(#gOut)" name="Outbound" />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </ChartCard>

          {/* Category bar chart */}
          <ChartCard title="Top Selling Categories" icon={BarChart3} color={C.indigo}>
            {categoryChart.length === 0 ? (
              <div style={{ height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center', color: C.muted, fontSize: 13 }}>No category data</div>
            ) : (
              <ResponsiveContainer width="100%" height={200}>
                <BarChart data={categoryChart} barCategoryGap="30%">
                  <CartesianGrid strokeDasharray="3 3" stroke={C.border} vertical={false} />
                  <XAxis dataKey="name" tick={{ fontSize: 10, fill: C.muted }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 10, fill: C.muted }} axisLine={false} tickLine={false} />
                  <Tooltip content={<ChartTip />} />
                  <Bar dataKey="value" name="Units" radius={[4, 4, 0, 0]}>
                    {categoryChart.map((_, i) => <Cell key={i} fill={ZONE_COLORS[i % ZONE_COLORS.length]} />)}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            )}
          </ChartCard>

          {/* Zone donut */}
          <ChartCard title="Zone Distribution" icon={Boxes} color={C.purple}>
            <ResponsiveContainer width="100%" height={200}>
              <PieChart>
                <Pie data={zoneData} cx="50%" cy="50%" innerRadius={52} outerRadius={80} dataKey="value" paddingAngle={3}>
                  {zoneData.map((_, i) => <Cell key={i} fill={ZONE_COLORS[i]} stroke="none" />)}
                </Pie>
                <Tooltip content={<ChartTip />} />
                <Legend iconType="circle" iconSize={8} formatter={v => <span style={{ fontSize: 11, color: C.muted }}>{v}</span>} />
              </PieChart>
            </ResponsiveContainer>
          </ChartCard>
        </div>

        {/* ── Bottom row ── */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 300px', gap: 16 }}>

          {/* Activity feed */}
          <div style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 14, padding: '20px 22px' }}>
            <SectionTitle icon={Clock} color={C.cyan} badge={feed.length}>Live Activity Feed</SectionTitle>
            <div style={{ overflowY: 'auto', maxHeight: 360, display: 'flex', flexDirection: 'column', gap: 2 }}>
              {feed.length === 0 ? (
                <div style={{ textAlign: 'center', color: C.muted, padding: '40px 0', fontSize: 13 }}>
                  No activity yet. Actions appear here in real-time.
                </div>
              ) : feed.map((item, i) => (
                <FeedItem key={item._rid ?? item.id ?? i} item={item} isNew={newFeedIds.has(item._rid)} />
              ))}
            </div>
          </div>

          {/* Alerts */}
          <div style={{ background: C.card, border: `1px solid ${C.border}`, borderRadius: 14, padding: '20px 22px' }}>
            <SectionTitle icon={AlertTriangle} color={C.amber} badge={uniqueAlerts.length > 0 ? uniqueAlerts.length : undefined}>Critical Alerts</SectionTitle>
            <div style={{ overflowY: 'auto', maxHeight: 360 }}>
              {uniqueAlerts.length === 0 ? (
                <div style={{ textAlign: 'center', padding: '40px 0' }}>
                  <ShieldCheck size={28} color={C.green} style={{ margin: '0 auto 10px', display: 'block' }} />
                  <div style={{ fontSize: 13, color: C.muted }}>All stock levels healthy</div>
                </div>
              ) : uniqueAlerts.map(p => <AlertCard key={p.id} product={p} />)}
            </div>
          </div>

          {/* Quick actions */}
          <QuickActions />
        </div>
      </div>
    </div>
  )
}
