import { useState } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  LockKeyhole,
  Mail,
  MapPin,
  ShieldCheck,
  UserRound,
} from 'lucide-react'
import { register } from '../api/register'
import AimsLogo from '../components/AimsLogo'
import AuthExperienceVisual from '../components/AuthExperienceVisual'
import '../styles/AuthPages.css'

function Register({ onRegisterSuccess, onBackHome, onLogin }) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [companyName, setCompanyName] = useState('')
  const [companyAddress, setCompanyAddress] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const handleSubmit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    if (password !== passwordConfirmation) {
      setError('Passwords do not match.')
      setLoading(false)
      return
    }

    try {
      const data = await register({
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
        company_name: companyName,
        company_address: companyAddress,
      })
      onRegisterSuccess(data)
    } catch (err) {
      if (err.errors) {
        setError(Object.values(err.errors).flat().join(' '))
      } else {
        setError(err.message || 'Could not create account.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page auth-experience-page auth-register-page">
      <div className="auth-digital-atmosphere" aria-hidden="true">
        <span className="auth-atmosphere-grid" />
        <span className="auth-atmosphere-glow auth-atmosphere-glow-one" />
        <span className="auth-atmosphere-glow auth-atmosphere-glow-two" />
        <span className="auth-atmosphere-particle auth-atmosphere-particle-one" />
        <span className="auth-atmosphere-particle auth-atmosphere-particle-two" />
        <span className="auth-atmosphere-particle auth-atmosphere-particle-three" />
      </div>

      <div className="auth-experience-layout auth-register-layout">
        <AuthExperienceVisual variant="register" />

        <main className="auth-shell auth-shell-wide auth-experience-shell">
          <div className="auth-shell-topline">
            <button type="button" className="auth-back auth-icon-link" onClick={onBackHome}>
              <ArrowLeft size={16} />
              Back to home
            </button>
            <span className="auth-secure-label">
              <ShieldCheck size={15} />
              Secure registration
            </span>
          </div>

          <div className="auth-brand">
            <div className="auth-logo-halo">
              <AimsLogo size="xl" showText={false} />
            </div>
            <span className="auth-brand-kicker">Create your AIMS workspace</span>
            <h1>Register your company</h1>
            <p>Set up the secure workspace where your business will operate.</p>
          </div>

          {error && <div className="auth-error" role="alert">{error}</div>}

          <form className="auth-form auth-register-form" onSubmit={handleSubmit}>
            <div className="auth-section-label"><span>01</span> Company details</div>

            <div className="auth-field">
              <label htmlFor="register-company-name">Company name</label>
              <div className="auth-control">
                <Building2 size={18} aria-hidden="true" />
                <input
                  id="register-company-name"
                  name="company_name"
                  type="text"
                  required
                  placeholder="Acme Corporation"
                  value={companyName}
                  onChange={(event) => setCompanyName(event.target.value)}
                />
              </div>
            </div>

            <div className="auth-field">
              <label htmlFor="register-company-address">Full company address</label>
              <div className="auth-control auth-control-textarea">
                <MapPin size={18} aria-hidden="true" />
                <textarea
                  id="register-company-address"
                  name="company_address"
                  required
                  rows={2}
                  placeholder="Street, city, state, postal code, country"
                  value={companyAddress}
                  onChange={(event) => setCompanyAddress(event.target.value)}
                />
              </div>
            </div>

            <div className="auth-section-label"><span>02</span> Administrator account</div>

            <div className="auth-form-row">
              <div className="auth-field">
                <label htmlFor="register-name">Full name</label>
                <div className="auth-control">
                  <UserRound size={18} aria-hidden="true" />
                  <input
                    id="register-name"
                    name="name"
                    type="text"
                    required
                    autoComplete="name"
                    placeholder="John Doe"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                  />
                </div>
              </div>
              <div className="auth-field">
                <label htmlFor="register-email">Email address</label>
                <div className="auth-control">
                  <Mail size={18} aria-hidden="true" />
                  <input
                    id="register-email"
                    name="email"
                    type="email"
                    required
                    autoComplete="email"
                    placeholder="admin@company.com"
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                  />
                </div>
              </div>
            </div>

            <div className="auth-form-row">
              <div className="auth-field">
                <label htmlFor="register-password">Password</label>
                <div className="auth-control">
                  <LockKeyhole size={18} aria-hidden="true" />
                  <input
                    id="register-password"
                    name="password"
                    type="password"
                    required
                    minLength={8}
                    autoComplete="new-password"
                    placeholder="At least 8 characters"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                  />
                </div>
              </div>
              <div className="auth-field">
                <label htmlFor="register-password-confirm">Confirm password</label>
                <div className="auth-control">
                  <LockKeyhole size={18} aria-hidden="true" />
                  <input
                    id="register-password-confirm"
                    name="password_confirmation"
                    type="password"
                    required
                    minLength={8}
                    autoComplete="new-password"
                    placeholder="Repeat password"
                    value={passwordConfirmation}
                    onChange={(event) => setPasswordConfirmation(event.target.value)}
                  />
                </div>
              </div>
            </div>

            <button type="submit" className="auth-submit auth-submit-premium" disabled={loading}>
              <span>{loading ? 'Creating company...' : 'Create company workspace'}</span>
              {loading ? <i className="auth-button-spinner" aria-hidden="true" /> : <ArrowRight size={18} />}
            </button>
          </form>

          <p className="auth-switch">
            Already have an AIMS workspace?{' '}
            <button type="button" className="auth-switch-link" onClick={onLogin}>
              Sign in
            </button>
          </p>

          <div className="auth-trust-row" aria-hidden="true">
            <span><i /> Secure company isolation</span>
            <span><i /> Guided workspace setup</span>
          </div>
        </main>
      </div>
    </div>
  )
}

export default Register
