import { useEffect, useMemo, useState } from 'react'
import { Package, BarChart3, Link2, Mail } from 'lucide-react'
import { getPublishedPages } from '../api/cms'
import AimsDigitalHero from '../components/AimsDigitalHero'
import AimsLogo from '../components/AimsLogo'
import AimsOperationsVisual from '../components/AimsOperationsVisual'
import AimsShowcase from '../components/AimsShowcase'
import LandingNavbar from '../components/LandingNavbar'
import './LandingPage.css'

const defaultContent = {
  'landing-hero-title': 'Simplify Your Inventory with AIMS',
  'landing-hero-subtitle': 'Empower your company with real-time insights and effortless control.',
  'feature-realtime': 'Always know your inventory levels instantly with live updates.',
  'feature-analytics': 'Gain insights into sales, stock, and operations with smart reports.',
  'feature-integration': 'Connect seamlessly with your existing tools and workflows.',
  'about-section':
    'AIMS helps teams track stock, suppliers, and daily warehouse work without juggling spreadsheets. We built it for small and mid-size companies that need a clear picture of what they have on hand.',
}

const featureMeta = [
  { slug: 'feature-realtime', icon: Package, fallbackTitle: 'Real-Time Tracking' },
  { slug: 'feature-analytics', icon: BarChart3, fallbackTitle: 'Powerful Analytics' },
  { slug: 'feature-integration', icon: Link2, fallbackTitle: 'Easy Integration' },
]

export default function LandingPage({ onLogin, onRegister, onOpenDashboard, isAuthenticated }) {
  const [content, setContent] = useState(defaultContent)
  const [featureTitles, setFeatureTitles] = useState({
    'feature-realtime': 'Real-Time Tracking',
    'feature-analytics': 'Powerful Analytics',
    'feature-integration': 'Easy Integration',
  })

  useEffect(() => {
    getPublishedPages()
      .then((pages) => {
        const nextContent = { ...defaultContent }
        const nextTitles = { ...featureTitles }

        pages.forEach((page) => {
          nextContent[page.slug] = page.content
          if (featureMeta.some((item) => item.slug === page.slug)) {
            nextTitles[page.slug] = page.title
          }
        })

        setContent(nextContent)
        setFeatureTitles(nextTitles)
      })
      .catch(() => {})
  }, [])

  useEffect(() => {
    const elements = Array.from(document.querySelectorAll('.landing-reveal-card, .landing-heading-reveal'))
    if (!elements.length) return undefined

    elements.forEach((element) => element.classList.add('landing-scroll-reveal'))

    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (reducedMotion || typeof IntersectionObserver === 'undefined') {
      elements.forEach((element) => element.classList.add('is-visible'))
      return undefined
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible')
          observer.unobserve(entry.target)
        }
      })
    }, { threshold: 0.14, rootMargin: '0px 0px -8% 0px' })

    elements.forEach((element) => observer.observe(element))

    // The visuals are entirely local. This fallback guarantees that a browser
    // extension or unusual embedded runtime cannot leave content hidden if it
    // suppresses IntersectionObserver callbacks.
    const revealFallback = window.setTimeout(() => {
      elements.forEach((element) => element.classList.add('is-visible'))
    }, 1800)

    return () => {
      observer.disconnect()
      window.clearTimeout(revealFallback)
    }
  }, [])

  const features = useMemo(
    () =>
      featureMeta.map((item) => ({
        icon: item.icon,
        title: featureTitles[item.slug] || item.fallbackTitle,
        desc: content[item.slug] || defaultContent[item.slug],
      })),
    [content, featureTitles]
  )

  return (
    <div className="landing-page">
      <LandingNavbar
        isAuthenticated={isAuthenticated}
        onLogin={onLogin}
        onRegister={onRegister}
        onOpenDashboard={onOpenDashboard}
      />

      <section className="landing-hero">
        <div className="landing-hero-grid" aria-hidden="true" />
        <div className="landing-hero-glow landing-hero-glow-one" aria-hidden="true" />
        <div className="landing-hero-glow landing-hero-glow-two" aria-hidden="true" />
        <div className="landing-hero-scanline" aria-hidden="true" />

        <div className="landing-hero-inner">
          <div className="landing-hero-copy">
            <div className="landing-hero-eyebrow">
              <span className="landing-hero-eyebrow-dot" />
              Connected business operations
            </div>
            <h1 className="landing-hero-title">{content['landing-hero-title']}</h1>
            <p className="landing-hero-subtitle">{content['landing-hero-subtitle']}</p>
            <div className="landing-hero-modules" aria-label="AIMS modules">
              <span>Inventory</span>
              <span>Sales</span>
              <span>Orders</span>
              <span>Shipments</span>
            </div>
            <div className="landing-hero-actions">
              {isAuthenticated ? (
                <button type="button" className="landing-btn primary large" onClick={onOpenDashboard}>
                  Go to Dashboard
                </button>
              ) : (
                <>
                  <button type="button" className="landing-btn primary large" onClick={onRegister}>
                    Get Started
                  </button>
                  <button type="button" className="landing-btn ghost large" onClick={onLogin}>
                    Log In
                  </button>
                </>
              )}
            </div>
          </div>
          <AimsDigitalHero />
        </div>
      </section>

      <section className="landing-live-section" aria-labelledby="landing-live-title">
        <div className="landing-live-heading">
          <span className="landing-section-label">The AIMS pulse</span>
          <h2 id="landing-live-title" className="landing-heading-reveal">
            From receiving to selling, everything stays in motion.
          </h2>
          <p>
            Turn busy warehouse activity into a calm, connected workflow your team can understand at a glance.
          </p>
        </div>

        <div className="landing-live-board">
          <div className="landing-live-board-topline">
            <span className="landing-live-status"><span className="landing-live-status-dot" />Live workspace</span>
            <span className="landing-live-board-caption">Operational clarity, without the spreadsheet maze</span>
          </div>

          <div className="landing-live-flow">
            <article className="landing-live-step landing-live-step-active">
              <span className="landing-live-step-number">01</span>
              <span className="landing-live-step-icon">↗</span>
              <h3>Receive</h3>
              <p>Orders arrive with the details your team needs.</p>
              <span className="landing-live-step-chip">Supplier sync</span>
            </article>
            <span className="landing-live-connector" aria-hidden="true"><span /></span>
            <article className="landing-live-step">
              <span className="landing-live-step-number">02</span>
              <span className="landing-live-step-icon">▦</span>
              <h3>Organize</h3>
              <p>Stock, locations, and thresholds stay aligned.</p>
              <span className="landing-live-step-chip">Inventory pulse</span>
            </article>
            <span className="landing-live-connector" aria-hidden="true"><span /></span>
            <article className="landing-live-step">
              <span className="landing-live-step-number">03</span>
              <span className="landing-live-step-icon">↘</span>
              <h3>Move forward</h3>
              <p>Sales and decisions follow the latest picture.</p>
              <span className="landing-live-step-chip">Ready to act</span>
            </article>
          </div>

          <div className="landing-live-board-bottomline">
            <div className="landing-live-wave" aria-hidden="true">
              {Array.from({ length: 16 }, (_, index) => <span key={index} style={{ '--bar-delay': `${index * 0.06}s` }} />)}
            </div>
            <span>Every movement becomes a clear next step.</span>
          </div>
        </div>
      </section>

      <section id="capabilities" className="landing-capabilities-section" aria-labelledby="landing-capabilities-title">
        <div className="landing-capabilities-heading">
          <span className="landing-section-label">One connected system</span>
          <h2 id="landing-capabilities-title" className="landing-heading-reveal">Everything your operation needs to stay clear and in control.</h2>
          <p>From the first purchase order to the final customer payment, AIMS keeps the important details together and easy to act on.</p>
        </div>
        <div className="landing-capabilities-grid">
          <article className="landing-capability-card landing-reveal-card">
            <span className="landing-capability-number">01</span>
            <h3>Inventory intelligence</h3>
            <p>Track quantities, locations, thresholds, categories, suppliers, and stock movements in one live workspace.</p>
            <span className="landing-capability-link">Know what is on hand <span>→</span></span>
          </article>
          <article className="landing-capability-card landing-reveal-card">
            <span className="landing-capability-number">02</span>
            <h3>Purchase-to-warehouse flow</h3>
            <p>Edit orders, record partial payments, receive products before payment is complete, and update inventory automatically.</p>
            <span className="landing-capability-link">Move orders forward <span>→</span></span>
          </article>
          <article className="landing-capability-card landing-reveal-card">
            <span className="landing-capability-number">03</span>
            <h3>Sales and debt history</h3>
            <p>Write the day’s sales, see performance by calendar period, and keep a transparent running sheet for every customer debt.</p>
            <span className="landing-capability-link">Keep every payment visible <span>→</span></span>
          </article>
          <article className="landing-capability-card landing-reveal-card">
            <span className="landing-capability-number">04</span>
            <h3>Reports that guide action</h3>
            <p>Turn activity into practical decisions with low-stock warnings, sales analysis, shipment tracking, and role-based access.</p>
            <span className="landing-capability-link">Make the next decision faster <span>→</span></span>
          </article>
        </div>
      </section>

      <section id="features" className="landing-section">
        <h2 className="landing-heading-reveal">Why Choose AIMS for Your Business</h2>
        <div className="landing-features">
          {features.map((item) => {
            const Icon = item.icon
            return (
            <article key={item.title} className="landing-feature-card landing-reveal-card">
                <div className="landing-feature-icon">
                  <Icon size={22} />
                </div>
                <h3>{item.title}</h3>
                <p>{item.desc}</p>
              </article>
            )
          })}
        </div>
      </section>

      <section id="about" className="landing-about-section">
        <div className="landing-about">
          <div className="landing-about-copy">
            <span className="landing-section-label">About us</span>
            <h2 className="landing-heading-reveal">About AIMS Inventory</h2>
            <p>{content['about-section']}</p>
            <ul className="landing-about-list">
              <li>Track products, categories, suppliers, and stock in one place</li>
              <li>Role-based access for admins, managers, and staff</li>
              <li>Low-stock alerts, daily sales, debt history, and reports built in</li>
              <li>Designed for teams that need clarity without complexity</li>
            </ul>
          </div>
          <div className="landing-about-media">
            <AimsOperationsVisual />
          </div>
        </div>
        <div className="landing-about-stats">
          <div className="landing-about-stat landing-reveal-card">
            <strong>Real-time</strong>
            <span>Stock updates</span>
          </div>
          <div className="landing-about-stat landing-reveal-card">
            <strong>Secure</strong>
            <span>Role-based access</span>
          </div>
          <div className="landing-about-stat landing-reveal-card">
            <strong>Simple</strong>
            <span>Team-ready UI</span>
          </div>
        </div>
      </section>

      <AimsShowcase />

      <footer id="contact" className="landing-footer">
        <div className="landing-footer-backdrop" aria-hidden="true">
          <span className="landing-footer-orb landing-footer-orb-one" />
          <span className="landing-footer-orb landing-footer-orb-two" />
          <span className="landing-footer-scan" />
        </div>

        <div className="landing-footer-shell">
          <section className="landing-footer-cta" aria-labelledby="landing-footer-cta-title">
            <div className="landing-footer-cta-copy">
              <span className="landing-footer-kicker">
                <span className="landing-footer-status-dot" aria-hidden="true" />
                Your operations, connected
              </span>
              <h2 id="landing-footer-cta-title">Turn every business movement into a clear next decision.</h2>
              <p>Bring inventory, sales, orders, suppliers, and shipments into one focused AIMS workspace.</p>
            </div>
            <div className="landing-footer-cta-actions">
              <button
                type="button"
                className="landing-btn primary large landing-footer-cta-button"
                onClick={isAuthenticated ? onOpenDashboard : onRegister}
              >
                {isAuthenticated ? 'Open Dashboard' : 'Start with AIMS'}
                <span aria-hidden="true">→</span>
              </button>
              <span className="landing-footer-cta-note">Inventory • Sales • Orders • Shipments</span>
            </div>
          </section>

          <div className="landing-footer-grid">
            <div className="landing-footer-brand">
              <div className="landing-footer-logo-wrap">
                <AimsLogo size="md" showText={false} />
                <span className="landing-footer-brand-signal" aria-hidden="true" />
              </div>
              <div>
                <p className="landing-footer-tagline">Simplify. Optimize. Grow.</p>
                <p className="landing-footer-desc">
                  AIMS Inventory helps modern teams track stock, suppliers, and operations from one secure platform.
                </p>
              </div>
              <div className="landing-footer-module-list" aria-label="Core AIMS capabilities">
                <span>Inventory</span>
                <span>Analytics</span>
                <span>Logistics</span>
              </div>
            </div>

            <nav className="landing-footer-column" aria-label="Explore AIMS">
              <h3><span aria-hidden="true">01</span> Explore</h3>
              <button type="button" onClick={() => document.getElementById('features')?.scrollIntoView({ behavior: 'smooth' })}>
                Features
              </button>
              <button type="button" onClick={() => document.getElementById('about')?.scrollIntoView({ behavior: 'smooth' })}>
                About
              </button>
            </nav>

            <nav className="landing-footer-column" aria-label="AIMS account">
              <h3><span aria-hidden="true">02</span> Account</h3>
              {isAuthenticated ? (
                <button type="button" onClick={onOpenDashboard}>Dashboard</button>
              ) : (
                <>
                  <button type="button" onClick={onLogin}>Log In</button>
                  <button type="button" onClick={onRegister}>Register</button>
                </>
              )}
            </nav>

            <section className="landing-footer-column" aria-labelledby="landing-footer-contact-title">
              <h3 id="landing-footer-contact-title"><span aria-hidden="true">03</span> Contact</h3>
              <a href="mailto:support@aims.com" className="landing-footer-link">
                <Mail size={16} aria-hidden="true" />
                support@aims.com
              </a>
              <div className="landing-footer-social" aria-label="AIMS social links">
                <a href="https://github.com/" target="_blank" rel="noreferrer" aria-label="GitHub (opens in a new tab)">GitHub</a>
                <a href="https://linkedin.com/" target="_blank" rel="noreferrer" aria-label="LinkedIn (opens in a new tab)">LinkedIn</a>
              </div>
            </section>
          </div>

          <div className="landing-footer-bottom">
            <p>&copy; {new Date().getFullYear()} AIMS Inventory. All rights reserved.</p>
            <p className="landing-footer-bottom-status">
              <span aria-hidden="true" />
              Built for clear, secure business operations
            </p>
          </div>
        </div>
      </footer>
    </div>
  )
}
