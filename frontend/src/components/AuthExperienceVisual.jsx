import {
  Activity,
  BarChart3,
  Boxes,
  CheckCircle2,
  PackageCheck,
  ShieldCheck,
  Ship,
} from 'lucide-react'

const chartBars = [42, 64, 52, 78, 61, 88, 72]

export default function AuthExperienceVisual({ variant = 'login' }) {
  const isRegister = variant === 'register'

  return (
    <aside className={`auth-showcase auth-showcase-${variant}`} aria-hidden="true">
      <div className="auth-showcase-copy">
        <span className="auth-showcase-eyebrow">
          <span className="auth-live-dot" />
          AIMS digital operations
        </span>
        <h2>
          {isRegister
            ? 'Build a smarter company workspace.'
            : 'Run every operation from one live view.'}
        </h2>
        <p>
          {isRegister
            ? 'Connect stock, purchasing, sales, debts, and shipments from day one.'
            : 'Inventory, sales, suppliers, purchasing, and shipments stay connected in real time.'}
        </p>
      </div>

      <div className="auth-visual-stage">
        <div className="auth-visual-orbit auth-visual-orbit-one" />
        <div className="auth-visual-orbit auth-visual-orbit-two" />

        <div className="auth-visual-console">
          <div className="auth-console-topline">
            <span className="auth-console-brand">
              <Activity size={14} />
              Operations live
            </span>
            <span className="auth-console-signal">
              <i />
              Synced
            </span>
          </div>

          <div className="auth-console-kpis">
            <div>
              <span>Inventory</span>
              <strong>24,680</strong>
              <small><Boxes size={12} /> units tracked</small>
            </div>
            <div>
              <span>Sales flow</span>
              <strong>+18.4%</strong>
              <small><BarChart3 size={12} /> live analysis</small>
            </div>
          </div>

          <div className="auth-console-chart">
            <div className="auth-chart-heading">
              <span>Weekly movement</span>
              <b>MON — SUN</b>
            </div>
            <div className="auth-chart-bars">
              {chartBars.map((height, index) => (
                <i
                  key={`auth-bar-${index}`}
                  style={{ '--auth-bar-height': `${height}%`, '--auth-bar-delay': `${index * 90}ms` }}
                />
              ))}
            </div>
          </div>
          <span className="auth-console-scan" />
        </div>

        <div className="auth-warehouse-mini">
          <span className="auth-rack-title">WAREHOUSE A</span>
          <div className="auth-rack-shelf auth-rack-shelf-one">
            <i /><i /><i />
          </div>
          <div className="auth-rack-shelf auth-rack-shelf-two">
            <i /><i /><i /><i />
          </div>
          <div className="auth-rack-floor" />
        </div>

        <div className="auth-route-mini">
          <span className="auth-route-line" />
          <span className="auth-route-port auth-route-port-one" />
          <Ship className="auth-route-ship" size={25} />
          <span className="auth-route-port auth-route-port-two" />
        </div>

        <div className="auth-floating-status auth-floating-status-stock">
          <PackageCheck size={17} />
          <span><b>Stock received</b><small>Inventory updated</small></span>
          <CheckCircle2 size={15} />
        </div>

        <div className="auth-floating-status auth-floating-status-secure">
          <ShieldCheck size={17} />
          <span><b>Workspace secured</b><small>Protected company data</small></span>
        </div>
      </div>

      <div className="auth-showcase-modules">
        <span>Inventory</span>
        <span>Sales</span>
        <span>Purchase orders</span>
        <span>Shipments</span>
      </div>
    </aside>
  )
}
