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
import { useTranslation } from '../hooks/useTranslation'
import '../styles/AuthPages.css'

function Register({ onRegisterSuccess, onBackHome, onLogin }) {
  const { language } = useTranslation()
  const text = (en, sq) => language === 'sq' ? sq : en
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
      setError(text('Passwords do not match.', 'Fjalëkalimet nuk përputhen.'))
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
        setError(err.message || text('Could not create account.', 'Llogaria nuk mund të krijohej.'))
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
              {text('Back to home', 'Kthehu në ballinë')}
            </button>
            <span className="auth-secure-label">
              <ShieldCheck size={15} />
              {text('Secure registration', 'Regjistrim i sigurt')}
            </span>
          </div>

          <div className="auth-brand">
            <div className="auth-logo-halo">
              <AimsLogo size="xl" showText={false} />
            </div>
            <span className="auth-brand-kicker">{text('Create your AIMS workspace', 'Krijo hapësirën tënde në AIMS')}</span>
            <h1>{text('Register your company', 'Regjistro kompaninë')}</h1>
            <p>{text('Set up the secure workspace where your business will operate.', 'Krijo hapësirën e sigurt ku do të punojë kompania jote.')}</p>
          </div>

          {error && <div className="auth-error" role="alert">{error}</div>}

          <form className="auth-form auth-register-form" onSubmit={handleSubmit}>
            <div className="auth-section-label"><span>01</span> {text('Company details', 'Të dhënat e kompanisë')}</div>

            <div className="auth-field">
              <label htmlFor="register-company-name">{text('Company name', 'Emri i kompanisë')}</label>
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
              <label htmlFor="register-company-address">{text('Full company address', 'Adresa e plotë e kompanisë')}</label>
              <div className="auth-control auth-control-textarea">
                <MapPin size={18} aria-hidden="true" />
                <textarea
                  id="register-company-address"
                  name="company_address"
                  required
                  rows={2}
                  placeholder={text('Street, city, state, postal code, country', 'Rruga, qyteti, rajoni, kodi postar, shteti')}
                  value={companyAddress}
                  onChange={(event) => setCompanyAddress(event.target.value)}
                />
              </div>
            </div>

            <div className="auth-section-label"><span>02</span> {text('Administrator account', 'Llogaria e administratorit')}</div>

            <div className="auth-form-row">
              <div className="auth-field">
                <label htmlFor="register-name">{text('Full name', 'Emri i plotë')}</label>
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
                <label htmlFor="register-email">{text('Email address', 'Adresa e emailit')}</label>
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
                <label htmlFor="register-password">{text('Password', 'Fjalëkalimi')}</label>
                <div className="auth-control">
                  <LockKeyhole size={18} aria-hidden="true" />
                  <input
                    id="register-password"
                    name="password"
                    type="password"
                    required
                    minLength={8}
                    autoComplete="new-password"
                    placeholder={text('At least 8 characters', 'Të paktën 8 karaktere')}
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                  />
                </div>
              </div>
              <div className="auth-field">
                <label htmlFor="register-password-confirm">{text('Confirm password', 'Konfirmo fjalëkalimin')}</label>
                <div className="auth-control">
                  <LockKeyhole size={18} aria-hidden="true" />
                  <input
                    id="register-password-confirm"
                    name="password_confirmation"
                    type="password"
                    required
                    minLength={8}
                    autoComplete="new-password"
                    placeholder={text('Repeat password', 'Përsërit fjalëkalimin')}
                    value={passwordConfirmation}
                    onChange={(event) => setPasswordConfirmation(event.target.value)}
                  />
                </div>
              </div>
            </div>

            <button type="submit" className="auth-submit auth-submit-premium" disabled={loading}>
              <span>{loading ? text('Creating company...', 'Duke krijuar kompaninë…') : text('Create company workspace', 'Krijo hapësirën e kompanisë')}</span>
              {loading ? <i className="auth-button-spinner" aria-hidden="true" /> : <ArrowRight size={18} />}
            </button>
          </form>

          <p className="auth-switch">
            {text('Already have an AIMS workspace?', 'Ke tashmë llogari në AIMS?')}{' '}
            <button type="button" className="auth-switch-link" onClick={onLogin}>
              {text('Sign in', 'Hyr')}
            </button>
          </p>

          <div className="auth-trust-row" aria-hidden="true">
            <span><i /> {text('Secure company isolation', 'Ndarje e sigurt mes kompanive')}</span>
            <span><i /> {text('Guided workspace setup', 'Udhëzim gjatë konfigurimit')}</span>
          </div>
        </main>
      </div>
    </div>
  )
}

export default Register
