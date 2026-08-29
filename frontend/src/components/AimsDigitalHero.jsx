import {
  Activity,
  BarChart3,
  CheckCircle2,
  Package,
  ScanLine,
  Ship,
  Warehouse,
} from 'lucide-react'

const chartBars = [44, 62, 51, 78, 66, 88, 74]
const inventoryBoxes = Array.from({ length: 9 }, (_, index) => index)

export default function AimsDigitalHero() {
  return (
    <div className="aims-digital-hero" aria-hidden="true">
      <div className="aims-hero-orbit aims-hero-orbit-one" />
      <div className="aims-hero-orbit aims-hero-orbit-two" />
      <div className="aims-hero-data-stream aims-hero-data-stream-one" />
      <div className="aims-hero-data-stream aims-hero-data-stream-two" />

      <div className="aims-hero-console">
        <div className="aims-hero-console-topbar">
          <div className="aims-hero-window-dots">
            <span />
            <span />
            <span />
          </div>
          <span className="aims-hero-console-title">AIMS CONTROL</span>
          <span className="aims-hero-live">
            <span />
            LIVE
          </span>
        </div>

        <div className="aims-hero-console-body">
          <div className="aims-hero-kpi">
            <Package size={16} />
            <span>
              <small>Products in stock</small>
              <strong>12,840</strong>
            </span>
            <em>+8.4%</em>
          </div>

          <div className="aims-hero-chart-panel">
            <div className="aims-hero-chart-heading">
              <span>
                <BarChart3 size={14} />
                Sales flow
              </span>
              <small>MON — SUN</small>
            </div>
            <div className="aims-hero-chart">
              {chartBars.map((height, index) => (
                <span
                  key={`hero-bar-${index}`}
                  style={{ '--hero-bar-height': `${height}%`, '--hero-bar-delay': `${index * 0.12}s` }}
                />
              ))}
            </div>
          </div>

          <div className="aims-hero-console-footer">
            <span><Activity size={13} /> Operations synced</span>
            <span>99.9%</span>
          </div>
        </div>
        <div className="aims-hero-console-scan"><ScanLine size={18} /></div>
      </div>

      <div className="aims-hero-warehouse">
        <div className="aims-hero-warehouse-title">
          <Warehouse size={15} />
          Smart warehouse
        </div>
        <div className="aims-hero-rack">
          {inventoryBoxes.map((box) => (
            <span key={box} style={{ '--box-delay': `${box * -0.42}s` }} />
          ))}
        </div>
        <div className="aims-hero-conveyor">
          <span className="aims-hero-conveyor-line" />
          <Package className="aims-hero-moving-package" size={22} />
        </div>
      </div>

      <div className="aims-hero-route">
        <span className="aims-hero-port aims-hero-port-origin" />
        <span className="aims-hero-route-line" />
        <Ship className="aims-hero-ship" size={25} />
        <span className="aims-hero-port aims-hero-port-destination" />
      </div>

      <div className="aims-hero-floating-card aims-hero-stock-card">
        <span className="aims-hero-floating-icon"><CheckCircle2 size={15} /></span>
        <span>
          <small>Inventory update</small>
          <strong>Stock synchronized</strong>
        </span>
      </div>

      <div className="aims-hero-floating-card aims-hero-shipment-card">
        <span className="aims-hero-floating-icon"><Ship size={15} /></span>
        <span>
          <small>Shipment network</small>
          <strong>Route active</strong>
        </span>
      </div>
    </div>
  )
}
