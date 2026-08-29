import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { ArrowLeft, ArrowRight, LockKeyhole, Mail, ShieldCheck } from 'lucide-react'
import { login } from '../api/login'
import AimsLogo from '../components/AimsLogo'
import AuthExperienceVisual from '../components/AuthExperienceVisual'
import '../styles/AuthPages.css'

function Login({ onLoginSuccess, onBackHome, onRegister }) {
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
        setError('Please verify your email before signing in. Redirecting…')
        setTimeout(() => {
          navigate(`/verify-email?email=${encodeURIComponent(email)}`)
        }, 900)
        return
      }
      if (err.errors) {
        setError(Object.values(err.errors).flat().join(' '))
      } else {
        setError(err.message || 'Invalid email or password.')
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
          <div className="auth-shell-topline">
            {!isDesktop ? (
              <button type="button" className="auth-back auth-icon-link" onClick={onBackHome}>
                <ArrowLeft size={16} />
                Back to home
              </button>
            ) : <span />}
            <span className="auth-secure-label">
              <ShieldCheck size={15} />
              {isDesktop ? 'Protected local workspace' : 'Secure access'}
            </span>
          </div>

          <div className="auth-brand">
            <div className="auth-logo-halo">
              <AimsLogo size="xl" showText={false} />
            </div>
            <span className="auth-brand-kicker">Company command center</span>
            <h1>Welcome back</h1>
            <p>Sign in to continue managing your company operations.</p>
          </div>

          {error && <div className="auth-error" role="alert">{error}</div>}

          <form className="auth-form" onSubmit={handleSubmit}>
            <div className="auth-field">
              <label htmlFor="email-address">Email address</label>
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
                <label htmlFor="password">Password</label>
                {!isDesktop ? (
                  <button
                    type="button"
                    className="auth-switch-link"
                    onClick={() => navigate('/forgot-password')}
                  >
                    Forgot password?
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
                  placeholder="Enter your password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                />
              </div>
            </div>

            <button type="submit" className="auth-submit auth-submit-premium" disabled={loading}>
              <span>{loading ? 'Signing in...' : 'Enter workspace'}</span>
              {loading ? <i className="auth-button-spinner" aria-hidden="true" /> : <ArrowRight size={18} />}
            </button>
          </form>

          {!isDesktop ? (
            <p className="auth-switch">
              New to AIMS?{' '}
              <button type="button" className="auth-switch-link" onClick={onRegister}>
                Create a company workspace
              </button>
            </p>
          ) : null}

          <div className="auth-trust-row" aria-hidden="true">
            <span><i /> Encrypted session</span>
            <span><i /> Company-isolated data</span>
          </div>
        </main>
      </div>
    </div>
  )
}

export default Login
