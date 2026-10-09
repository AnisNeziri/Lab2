import {
  Activity,
  BarChart3,
  Boxes,
  CheckCircle2,
  PackageCheck,
  ShieldCheck,
  Ship,
} from 'lucide-react'
import { useTranslation } from '../hooks/useTranslation'

const chartBars = [42, 64, 52, 78, 61, 88, 72]

export default function AuthExperienceVisual({ variant = 'login' }) {
  const { language } = useTranslation()
  const text = (en, sq) => language === 'sq' ? sq : en
  const isRegister = variant === 'register'

  return (
    <aside className={`auth-showcase auth-showcase-${variant}`} aria-hidden="true">
      <div className="auth-showcase-copy">
        <span className="auth-showcase-eyebrow">
          <span className="auth-live-dot" />
          {text('AIMS digital operations', 'Operacionet digjitale AIMS')}
        </span>
        <h2>
          {isRegister
            ? text('Build a smarter company workspace.', 'Krijo një hapësirë më të mençur për kompaninë.')
            : text('Run every operation from one live view.', 'Menaxho çdo veprim nga një pamje e vetme.')}
        </h2>
        <p>
          {isRegister
            ? text('Connect stock, purchasing, sales, debts, and shipments from day one.', 'Lidh stokun, blerjet, shitjet, borxhet dhe dërgesat që nga dita e parë.')
            : text('Inventory, sales, suppliers, purchasing, and shipments stay connected in real time.', 'Inventari, shitjet, furnitorët, blerjet dhe dërgesat qëndrojnë të lidhura në kohë reale.')}
        </p>
      </div>

      <div className="auth-visual-stage">
        <div className="auth-visual-orbit auth-visual-orbit-one" />
        <div className="auth-visual-orbit auth-visual-orbit-two" />

        <div className="auth-visual-console">
          <div className="auth-console-topline">
            <span className="auth-console-brand">
              <Activity size={14} />
              {text('Workspace preview', 'Pamje ilustruese')}
            </span>
            <span className="auth-console-signal">
              <i />
              {text('Example', 'Shembull')}
            </span>
          </div>

          <div className="auth-console-kpis">
            <div>
              <span>{text('Inventory', 'Inventari')}</span>
              <strong>24,680</strong>
              <small><Boxes size={12} /> {text('units tracked', 'njësi në inventar')}</small>
            </div>
            <div>
              <span>{text('Sales flow', 'Ecuria e shitjeve')}</span>
              <strong>+18.4%</strong>
              <small><BarChart3 size={12} /> {text('sales analysis', 'analiza e shitjeve')}</small>
            </div>
          </div>

          <div className="auth-console-chart">
            <div className="auth-chart-heading">
              <span>{text('Weekly movement', 'Lëvizjet javore')}</span>
              <b>{text('MON — SUN', 'HËN — DIE')}</b>
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
          <span className="auth-rack-title">{text('WAREHOUSE A', 'DEPOJA A')}</span>
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
          <span><b>{text('Stock received', 'Stoku u pranua')}</b><small>{text('Inventory updated', 'Inventari u përditësua')}</small></span>
          <CheckCircle2 size={15} />
        </div>

        <div className="auth-floating-status auth-floating-status-secure">
          <ShieldCheck size={17} />
          <span><b>{text('Workspace secured', 'Hapësirë e sigurt')}</b><small>{text('Protected company data', 'Të dhëna të mbrojtura')}</small></span>
        </div>
      </div>

      <div className="auth-showcase-modules">
        <span>{text('Inventory', 'Inventari')}</span>
        <span>{text('Sales', 'Shitjet')}</span>
        <span>{text('Purchase orders', 'Porositë e blerjes')}</span>
        <span>{text('Shipments', 'Dërgesat')}</span>
      </div>
    </aside>
  )
}
