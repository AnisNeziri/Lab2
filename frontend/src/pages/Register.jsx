import { useState } from 'react'
import { register } from '../api/register'
import AimsLogo from '../components/AimsLogo'
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
    <div className="auth-page">
      <div className="auth-shell auth-shell-wide">
        <button type="button" className="auth-back" onClick={onBackHome}>
          Back to home
        </button>

        <div className="auth-brand">
          <AimsLogo size="lg" showText={false} />
          <h1>Register your company</h1>
          <p>Create a company account and become the administrator.</p>
        </div>

        {error && <div className="auth-error">{error}</div>}

        <form className="auth-form" onSubmit={handleSubmit}>
          <div className="auth-section-label">Company details</div>

          <label htmlFor="register-company-name">Company Name</label>
          <input
            id="register-company-name"
            name="company_name"
            type="text"
            required
            placeholder="Acme Corporation"
            value={companyName}
            onChange={(event) => setCompanyName(event.target.value)}
          />

          <label htmlFor="register-company-address">Full Company Address</label>
          <textarea
            id="register-company-address"
            name="company_address"
            required
            rows={3}
            placeholder="Street, city, state, postal code, country"
            value={companyAddress}
            onChange={(event) => setCompanyAddress(event.target.value)}
          />

          <div className="auth-section-label">Administrator account</div>

          <label htmlFor="register-name">Full Name</label>
          <input
            id="register-name"
            name="name"
            type="text"
            required
            placeholder="John Doe"
            value={name}
            onChange={(event) => setName(event.target.value)}
          />

          <label htmlFor="register-email">Email Address</label>
          <input
            id="register-email"
            name="email"
            type="email"
            required
            placeholder="admin@company.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

          <div className="auth-form-row">
            <div>
              <label htmlFor="register-password">Password</label>
              <input
                id="register-password"
                name="password"
                type="password"
                required
                minLength={8}
                placeholder="At least 8 characters"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
              />
            </div>
            <div>
              <label htmlFor="register-password-confirm">Confirm Password</label>
              <input
                id="register-password-confirm"
                name="password_confirmation"
                type="password"
                required
                minLength={8}
                placeholder="Repeat password"
                value={passwordConfirmation}
                onChange={(event) => setPasswordConfirmation(event.target.value)}
              />
            </div>
          </div>

          <button type="submit" className="auth-submit" disabled={loading}>
            {loading ? 'Creating company...' : 'Create company account'}
          </button>
        </form>

        <p className="auth-switch">
          Already have an account?{' '}
          <button type="button" className="auth-switch-link" onClick={onLogin}>
            Log in
          </button>
        </p>
      </div>
    </div>
  )
}

export default Register
