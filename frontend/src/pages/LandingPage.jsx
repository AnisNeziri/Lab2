import { useEffect, useMemo, useState } from 'react'
import { Package, BarChart3, Link2, Mail } from 'lucide-react'
import { getPublishedPages } from '../api/cms'
import AimsLogo from '../components/AimsLogo'
import LandingNavbar from '../components/LandingNavbar'
import banner from '../assets/banner.jpg'
import img1 from '../assets/img1.jpg'
import slider1 from '../assets/slider1.jpg'
import slider2 from '../assets/slider2.jpg'
import slider3 from '../assets/slider3.jpg'
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

const sliderImages = [slider1, slider2, slider3]

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

      <section className="landing-hero" style={{ backgroundImage: `url(${banner})` }}>
        <div className="landing-hero-overlay">
          <h1>{content['landing-hero-title']}</h1>
          <p>{content['landing-hero-subtitle']}</p>
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
      </section>

      <section id="features" className="landing-section">
        <h2>Why Choose AIMS for Your Business</h2>
        <div className="landing-features">
          {features.map((item) => {
            const Icon = item.icon
            return (
              <article key={item.title} className="landing-feature-card">
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
            <h2>About AIMS Inventory</h2>
            <p>{content['about-section']}</p>
            <ul className="landing-about-list">
              <li>Track products, categories, suppliers, and stock in one place</li>
              <li>Role-based access for admins, managers, and staff</li>
              <li>Low-stock alerts, reports, and invoice management built in</li>
              <li>Designed for teams that need clarity without complexity</li>
            </ul>
          </div>
          <div className="landing-about-media">
            <img src={img1} alt="Warehouse operations" className="landing-about-image" />
          </div>
        </div>
        <div className="landing-about-stats">
          <div className="landing-about-stat">
            <strong>Real-time</strong>
            <span>Stock updates</span>
          </div>
          <div className="landing-about-stat">
            <strong>Secure</strong>
            <span>Role-based access</span>
          </div>
          <div className="landing-about-stat">
            <strong>Simple</strong>
            <span>Team-ready UI</span>
          </div>
        </div>
      </section>

      <section className="landing-slider-wrap">
        <div className="landing-slider">
          {sliderImages.map((image, index) => (
            <img key={index} src={image} alt={`Inventory slide ${index + 1}`} />
          ))}
        </div>
      </section>

      <footer id="contact" className="landing-footer">
        <div className="landing-footer-grid">
          <div className="landing-footer-brand">
            <AimsLogo size="md" showText={false} />
            <p className="landing-footer-tagline">Simplify. Optimize. Grow.</p>
            <p className="landing-footer-desc">
              AIMS Inventory helps modern teams track stock, suppliers, and operations from one secure platform.
            </p>
          </div>

          <div className="landing-footer-column">
            <h3>Explore</h3>
            <button type="button" onClick={() => document.getElementById('features')?.scrollIntoView({ behavior: 'smooth' })}>
              Features
            </button>
            <button type="button" onClick={() => document.getElementById('about')?.scrollIntoView({ behavior: 'smooth' })}>
              About
            </button>
          </div>

          <div className="landing-footer-column">
            <h3>Account</h3>
            {isAuthenticated ? (
              <button type="button" onClick={onOpenDashboard}>Dashboard</button>
            ) : (
              <>
                <button type="button" onClick={onLogin}>Log In</button>
                <button type="button" onClick={onRegister}>Register</button>
              </>
            )}
          </div>

          <div className="landing-footer-column">
            <h3>Contact</h3>
            <a href="mailto:support@aims.com" className="landing-footer-link">
              <Mail size={16} />
              support@aims.com
            </a>
            <div className="landing-footer-social">
              <a href="https://github.com/" target="_blank" rel="noreferrer">GitHub</a>
              <a href="https://linkedin.com/" target="_blank" rel="noreferrer">LinkedIn</a>
            </div>
          </div>
        </div>

        <div className="landing-footer-bottom">
          <p>&copy; {new Date().getFullYear()} AIMS Inventory. All rights reserved.</p>
        </div>
      </footer>
    </div>
  )
}
