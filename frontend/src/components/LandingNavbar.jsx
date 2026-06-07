import { useEffect, useRef, useState } from 'react'
import {
  ChevronDown,
  LogIn,
  UserPlus,
  LayoutDashboard,
  Menu,
  X,
} from 'lucide-react'
import AimsLogo from './AimsLogo'
import './LandingNavbar.css'

const NAV_LINKS = [
  { id: 'features', label: 'Features' },
  { id: 'about', label: 'About' },
  { id: 'contact', label: 'Contact' },
]

export default function LandingNavbar({
  isAuthenticated,
  onLogin,
  onRegister,
  onOpenDashboard,
}) {
  const [accountOpen, setAccountOpen] = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [scrolled, setScrolled] = useState(false)
  const accountRef = useRef(null)

  useEffect(() => {
    function handleScroll() {
      setScrolled(window.scrollY > 8)
    }

    handleScroll()
    window.addEventListener('scroll', handleScroll, { passive: true })
    return () => window.removeEventListener('scroll', handleScroll)
  }, [])

  useEffect(() => {
    document.body.style.overflow = mobileOpen ? 'hidden' : ''
    return () => {
      document.body.style.overflow = ''
    }
  }, [mobileOpen])

  useEffect(() => {
    if (!accountOpen) {
      return undefined
    }

    function handleClickOutside(event) {
      if (accountRef.current && !accountRef.current.contains(event.target)) {
        setAccountOpen(false)
      }
    }

    function handleEscape(event) {
      if (event.key === 'Escape') {
        setAccountOpen(false)
      }
    }

    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [accountOpen])

  function scrollToSection(id) {
    setMobileOpen(false)
    setAccountOpen(false)
    const element = document.getElementById(id)
    if (element) {
      element.scrollIntoView({ behavior: 'smooth' })
    }
  }

  function scrollToTop() {
    setMobileOpen(false)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function handleLogin() {
    setAccountOpen(false)
    setMobileOpen(false)
    onLogin()
  }

  function handleRegister() {
    setAccountOpen(false)
    setMobileOpen(false)
    onRegister()
  }

  function handleDashboard() {
    setAccountOpen(false)
    setMobileOpen(false)
    onOpenDashboard()
  }

  return (
    <header className={`landing-header${scrolled ? ' is-scrolled' : ''}`}>
      <div className="landing-nav-inner">
        <button type="button" className="landing-brand" onClick={scrollToTop}>
          <AimsLogo showText={false} size="xl" />
        </button>

        <nav className="landing-nav-links landing-nav-desktop" aria-label="Primary">
          {NAV_LINKS.map((link) => (
            <button
              key={link.id}
              type="button"
              className="landing-nav-link"
              onClick={() => scrollToSection(link.id)}
            >
              {link.label}
            </button>
          ))}
        </nav>

        <div className="landing-nav-end">
          <div className="landing-nav-actions landing-nav-desktop" ref={accountRef}>
            {isAuthenticated ? (
              <button type="button" className="landing-btn primary" onClick={handleDashboard}>
                <LayoutDashboard size={18} />
                Dashboard
              </button>
            ) : (
              <div className="landing-account-menu">
                <button
                  type="button"
                  className="landing-account-trigger"
                  aria-expanded={accountOpen}
                  onClick={() => setAccountOpen((open) => !open)}
                >
                  Account
                  <ChevronDown size={16} className={accountOpen ? 'rotated' : ''} />
                </button>

                {accountOpen && (
                  <div className="landing-account-dropdown">
                    <button type="button" onClick={handleLogin}>
                      <LogIn size={16} />
                      Log In
                    </button>
                    <button type="button" onClick={handleRegister}>
                      <UserPlus size={16} />
                      Register
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>

          <button
            type="button"
            className="landing-nav-toggle"
            aria-expanded={mobileOpen}
            aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
            onClick={() => setMobileOpen((open) => !open)}
          >
            {mobileOpen ? <X size={22} /> : <Menu size={22} />}
          </button>
        </div>
      </div>

      <div className={`landing-mobile-panel${mobileOpen ? ' is-open' : ''}`} aria-hidden={!mobileOpen}>
        <nav className="landing-mobile-nav" aria-label="Mobile">
          {NAV_LINKS.map((link) => (
            <button
              key={link.id}
              type="button"
              className="landing-mobile-link"
              onClick={() => scrollToSection(link.id)}
            >
              {link.label}
            </button>
          ))}
        </nav>

        <div className="landing-mobile-actions">
          {isAuthenticated ? (
            <button type="button" className="landing-btn primary landing-mobile-btn" onClick={handleDashboard}>
              <LayoutDashboard size={18} />
              Dashboard
            </button>
          ) : (
            <>
              <button type="button" className="landing-btn primary landing-mobile-btn" onClick={handleLogin}>
                <LogIn size={18} />
                Log In
              </button>
              <button type="button" className="landing-btn secondary landing-mobile-btn" onClick={handleRegister}>
                <UserPlus size={18} />
                Register
              </button>
            </>
          )}
        </div>
      </div>

      {mobileOpen && (
        <button
          type="button"
          className="landing-mobile-backdrop"
          aria-label="Close menu"
          onClick={() => setMobileOpen(false)}
        />
      )}
    </header>
  )
}
