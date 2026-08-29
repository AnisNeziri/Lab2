import { useEffect, useState } from 'react'
import {
  BarChart3,
  Boxes,
  ChevronLeft,
  ChevronRight,
  CircleDollarSign,
  PackageCheck,
  Ship,
} from 'lucide-react'

const slides = [
  {
    id: 'inventory',
    eyebrow: '01 / Inventory control',
    title: 'See every product movement as it happens.',
    description: 'Quantities, thresholds, categories, and warehouse locations stay connected in one clear operational view.',
    icon: Boxes,
    accent: '#22d3ee',
    metrics: [['12,840', 'Units tracked'], ['18', 'Low-stock alerts'], ['99.8%', 'Stock accuracy']],
    bars: [48, 68, 55, 82, 72, 91],
  },
  {
    id: 'orders',
    eyebrow: '02 / Purchase workflow',
    title: 'Move an order from supplier to shelf without losing context.',
    description: 'Edit products, record partial payments, receive stock, and preserve every important change.',
    icon: PackageCheck,
    accent: '#60a5fa',
    metrics: [['24', 'Open orders'], ['7', 'In transit'], ['94%', 'On-time receiving']],
    bars: [32, 47, 63, 71, 84, 96],
  },
  {
    id: 'profit',
    eyebrow: '03 / Profit intelligence',
    title: 'Understand revenue, cost, and margin—not only sales.',
    description: 'AIMS captures the purchase cost at sale time so weekly, monthly, and yearly profit remains historically accurate.',
    icon: CircleDollarSign,
    accent: '#34d399',
    metrics: [['€28.4K', 'Revenue'], ['€18.1K', 'Cost'], ['36.3%', 'Gross margin']],
    bars: [42, 61, 52, 73, 69, 89],
  },
  {
    id: 'shipments',
    eyebrow: '04 / Shipment network',
    title: 'Keep international inventory movement visible.',
    description: 'Follow shipment progress, vessel positions, alerts, and arrival activity from the same workspace.',
    icon: Ship,
    accent: '#818cf8',
    metrics: [['11', 'Active routes'], ['3', 'Arriving soon'], ['2', 'Attention needed']],
    bars: [70, 59, 76, 62, 86, 78],
  },
]

export default function AimsShowcase() {
  const [activeIndex, setActiveIndex] = useState(0)
  const [paused, setPaused] = useState(false)
  const [pageVisible, setPageVisible] = useState(() => !document.hidden)
  const [reducedMotion, setReducedMotion] = useState(
    () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false,
  )
  const active = slides[activeIndex]
  const Icon = active.icon

  useEffect(() => {
    const handleVisibility = () => setPageVisible(!document.hidden)
    const motionQuery = window.matchMedia?.('(prefers-reduced-motion: reduce)')
    const handleMotionChange = (event) => setReducedMotion(event.matches)

    document.addEventListener('visibilitychange', handleVisibility)
    motionQuery?.addEventListener?.('change', handleMotionChange)

    return () => {
      document.removeEventListener('visibilitychange', handleVisibility)
      motionQuery?.removeEventListener?.('change', handleMotionChange)
    }
  }, [])

  useEffect(() => {
    if (paused || !pageVisible || reducedMotion) return undefined
    const timer = window.setTimeout(() => {
      setActiveIndex((current) => (current + 1) % slides.length)
    }, 5600)
    return () => window.clearTimeout(timer)
  }, [activeIndex, pageVisible, paused, reducedMotion])

  const move = (direction) => {
    setActiveIndex((current) => (current + direction + slides.length) % slides.length)
  }

  return (
    <section
      className="landing-showcase landing-reveal-card"
      aria-labelledby="landing-showcase-title"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={() => setPaused(false)}
    >
      <div className="landing-showcase-heading">
        <div>
          <span className="landing-section-label">Built around real operations</span>
          <h2 id="landing-showcase-title">One system. Every important business movement.</h2>
        </div>
        <div className="landing-showcase-controls">
          <button type="button" onClick={() => move(-1)} aria-label="Previous AIMS capability">
            <ChevronLeft size={19} />
          </button>
          <button type="button" onClick={() => move(1)} aria-label="Next AIMS capability">
            <ChevronRight size={19} />
          </button>
        </div>
      </div>

      <div className="landing-showcase-stage" style={{ '--showcase-accent': active.accent }}>
        <div className="landing-showcase-copy" key={`${active.id}-copy`}>
          <span className="landing-showcase-eyebrow">{active.eyebrow}</span>
          <div className="landing-showcase-icon"><Icon size={24} /></div>
          <h3>{active.title}</h3>
          <p>{active.description}</p>
          <div className="landing-showcase-metrics">
            {active.metrics.map(([value, label]) => (
              <span key={label}>
                <strong>{value}</strong>
                <small>{label}</small>
              </span>
            ))}
          </div>
        </div>

        <div className="landing-showcase-screen" key={`${active.id}-screen`} aria-hidden="true">
          <div className="landing-showcase-screen-bar">
            <span><i /><i /><i /></span>
            <em>AIMS / {active.id.toUpperCase()}</em>
            <b>LIVE</b>
          </div>
          <div className="landing-showcase-screen-body">
            <div className="landing-showcase-screen-kpi">
              <span><Icon size={17} /></span>
              <div>
                <small>{active.metrics[0][1]}</small>
                <strong>{active.metrics[0][0]}</strong>
              </div>
              <i>+8.4%</i>
            </div>
            <div className="landing-showcase-screen-chart">
              <div className="landing-showcase-chart-title">
                <span><BarChart3 size={14} /> Activity trend</span>
                <small>LIVE DATA</small>
              </div>
              <div className="landing-showcase-bars">
                {active.bars.map((height, index) => (
                  <span
                    key={`${active.id}-bar-${index}`}
                    style={{ '--showcase-bar-height': `${height}%`, '--showcase-bar-delay': `${index * .08}s` }}
                  />
                ))}
              </div>
            </div>
            <div className="landing-showcase-status">
              <span><PackageCheck size={14} /> Data synchronized</span>
              <strong>100%</strong>
            </div>
          </div>
          <span className="landing-showcase-screen-scan" />
        </div>
      </div>

      <div className="landing-showcase-tabs" role="tablist" aria-label="AIMS capability showcase">
        {slides.map((slide, index) => (
          <button
            key={slide.id}
            type="button"
            role="tab"
            aria-selected={index === activeIndex}
            className={index === activeIndex ? 'is-active' : ''}
            onClick={() => setActiveIndex(index)}
          >
            <span>{String(index + 1).padStart(2, '0')}</span>
            {slide.eyebrow.split(' / ')[1]}
          </button>
        ))}
      </div>
    </section>
  )
}
