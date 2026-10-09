import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, ArrowRight, LockKeyhole, Mail, ShieldCheck } from 'lucide-react'
import { login } from '../api/login'
import AimsLogo from '../components/AimsLogo'
import AuthExperienceVisual from '../components/AuthExperienceVisual'
import { useTranslation } from '../hooks/useTranslation'
import '../styles/AuthPages.css'

function Login({ onLoginSuccess, onBackHome, onRegister }) {
  const { language } = useTranslation()
  const text = (en, sq) => language === 'sq' ? sq : en
  const navigate = useNavigate()
  const isDesktop = Boolean(window.__AIMS_API_BASE__)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const handleSubmit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    try {
      const data = await login(email, password)
      onLoginSuccess(data)
    } catch (err) {
      if (err.code === 'EMAIL_NOT_VERIFIED') {
        setError(text('Please verify your email before signing in. Redirecting…', 'Verifiko emailin para hyrjes. Duke të ridrejtuar…'))
        setTimeout(() => {
          navigate(`/verify-email?email=${encodeURIComponent(email)}`)
        }, 900)
        return
      }
      if (err.errors) {
        setError(Object.values(err.errors).flat().join(' '))
      } else {
        setError(err.message || text('Invalid email or password.', 'Emaili ose fjalëkalimi është i pasaktë.'))
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page auth-experience-page auth-login-page">
      <div className="auth-digital-atmosphere" aria-hidden="true">
        <span className="auth-atmosphere-grid" />
        <span className="auth-atmosphere-glow auth-atmosphere-glow-one" />
        <span className="auth-atmosphere-glow auth-atmosphere-glow-two" />
        <span className="auth-atmosphere-particle auth-atmosphere-particle-one" />
        <span className="auth-atmosphere-particle auth-atmosphere-particle-two" />
        <span className="auth-atmosphere-particle auth-atmosphere-particle-three" />
      </div>

      <div className="auth-experience-layout">
        <AuthExperienceVisual variant="login" />

        <main className="auth-shell auth-experience-shell">
          {import.meta.env.VITE_AIMS_INSTALLATION === 'synthetic' && <div className="auth-demo-notice" role="status">
            <strong>{text('Isolated demo installation', 'Instalim demonstrues i izoluar')}</strong>
            <p>{text('Your original AIMS accounts and company data are not in this demo.', 'Llogaritë dhe të dhënat origjinale të AIMS nuk janë në këtë demonstrim.')}</p>
            <a href="http://127.0.0.1:5173/login">{text('Open original AIMS', 'Hap AIMS origjinal')} →</a>
          </div>}
          <div className="auth-shell-topline">
            {!isDesktop ? (
              <button type="button" className="auth-back auth-icon-link" onClick={onBackHome}>
                <ArrowLeft size={16} />
                {text('Back to home', 'Kthehu në ballinë')}
              </button>
            ) : <span />}
            <span className="auth-secure-label">
              <ShieldCheck size={15} />
              {isDesktop ? text('Protected local workspace', 'Hapësirë lokale e mbrojtur') : text('Secure access', 'Hyrje e sigurt')}
            </span>
          </div>

          <div className="auth-brand">
            <div className="auth-logo-halo">
              <AimsLogo size="xl" showText={false} />
            </div>
            <span className="auth-brand-kicker">{text('Company command center', 'Qendra e menaxhimit të kompanisë')}</span>
            <h1>{text('Welcome back', 'Mirë se u ktheve')}</h1>
            <p>{text('Sign in to continue managing your company operations.', 'Hyr për të vazhduar menaxhimin e kompanisë.')}</p>
          </div>

          {error && <div className="auth-error" role="alert">{error}</div>}

          <form className="auth-form" onSubmit={handleSubmit}>
            <div className="auth-field">
              <label htmlFor="email-address">{text('Email address', 'Adresa e emailit')}</label>
              <div className="auth-control">
                <Mail size={18} aria-hidden="true" />
                <input
                  id="email-address"
                  name="email"
                  type="email"
                  required
                  autoComplete="email"
                  placeholder="you@company.com"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                />
              </div>
            </div>

            <div className="auth-field">
              <div className="auth-label-row">
                <label htmlFor="password">{text('Password', 'Fjalëkalimi')}</label>
                {!isDesktop ? (
                  <button
                    type="button"
                    className="auth-switch-link"
                    onClick={() => navigate('/forgot-password')}
                  >
                    {text('Forgot password?', 'Ke harruar fjalëkalimin?')}
                  </button>
                ) : null}
              </div>
              <div className="auth-control">
                <LockKeyhole size={18} aria-hidden="true" />
                <input
                  id="password"
                  name="password"
                  type="password"
                  required
                  autoComplete="current-password"
                  placeholder={text('Enter your password', 'Shkruaj fjalëkalimin')}
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                />
              </div>
            </div>

            <button type="submit" className="auth-submit auth-submit-premium" disabled={loading}>
              <span>{loading ? text('Signing in...', 'Duke hyrë…') : text('Enter workspace', 'Hyr në sistem')}</span>
              {loading ? <i className="auth-button-spinner" aria-hidden="true" /> : <ArrowRight size={18} />}
            </button>
          </form>

          {!isDesktop ? (
            <p className="auth-switch">
              {text('New to AIMS?', 'Je i ri në AIMS?')}{' '}
              <button type="button" className="auth-switch-link" onClick={onRegister}>
                {text('Create a company workspace', 'Krijo hapësirën e kompanisë')}
              </button>
            </p>
          ) : null}

          <div className="auth-trust-row" aria-hidden="true">
            <span><i /> {text('Secure access', 'Hyrje e sigurt')}</span>
            <span><i /> {text('Company-isolated data', 'Të dhëna të ndara sipas kompanisë')}</span>
          </div>
        </main>
      </div>
    </div>
  )
}

export default Login
