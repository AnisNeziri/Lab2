import {
  BarChart3,
  CheckCircle2,
  CircleDollarSign,
  Package,
  ScanLine,
  Ship,
  Warehouse,
} from 'lucide-react'

const rackBoxes = Array.from({ length: 12 }, (_, index) => index)
const activityBars = [38, 58, 46, 72, 64, 88, 78]

export default function AimsOperationsVisual() {
  return (
    <div className="aims-about-visual landing-reveal-card" aria-label="Animated AIMS inventory workflow">
      <div className="aims-about-grid" aria-hidden="true" />
      <div className="aims-about-scan" aria-hidden="true"><ScanLine size={18} /></div>

      <div className="aims-about-header">
        <span className="aims-about-brand">AIMS / OPERATIONS</span>
        <span className="aims-about-online"><i /> SYSTEM LIVE</span>
      </div>

      <div className="aims-about-system">
        <div className="aims-about-warehouse">
          <div className="aims-about-module-title">
            <Warehouse size={15} />
            Warehouse
          </div>
          <div className="aims-about-racks">
            {rackBoxes.map((box) => (
              <span key={box} style={{ '--about-box-delay': `${box * -0.27}s` }} />
            ))}
          </div>
          <div className="aims-about-stock-line">
            <span>Stock accuracy</span>
            <strong>99.8%</strong>
          </div>
        </div>

        <div className="aims-about-core">
          <div className="aims-about-core-ring aims-about-core-ring-one" />
          <div className="aims-about-core-ring aims-about-core-ring-two" />
          <div className="aims-about-core-mark">A</div>
          <span>SYNC</span>
        </div>

        <div className="aims-about-analytics">
          <div className="aims-about-module-title">
            <BarChart3 size={15} />
            Business pulse
          </div>
          <div className="aims-about-bars">
            {activityBars.map((height, index) => (
              <span
                key={`operations-bar-${index}`}
                style={{ '--about-bar-height': `${height}%`, '--about-bar-delay': `${index * .09}s` }}
              />
            ))}
          </div>
          <div className="aims-about-profit">
            <CircleDollarSign size={14} />
            <span>Margin visibility</span>
            <strong>LIVE</strong>
          </div>
        </div>
      </div>

      <div className="aims-about-route" aria-hidden="true">
        <span className="aims-about-route-dot aims-about-route-start" />
        <span className="aims-about-route-path" />
        <Package className="aims-about-route-package" size={20} />
        <span className="aims-about-route-dot aims-about-route-end" />
      </div>

      <div className="aims-about-footer">
        <span><CheckCircle2 size={14} /> Inventory synchronized</span>
        <span><Ship size={14} /> Shipments connected</span>
        <span><CircleDollarSign size={14} /> Profit measured</span>
      </div>
    </div>
  )
}
