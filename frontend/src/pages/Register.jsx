import { useState } from 'react'
import { register } from '../api/register'
import AimsLogo from '../components/AimsLogo'

function Register({ onRegisterSuccess, onBackHome, onLogin }) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
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
    <div className="login-page">
      <div className="login-card">
        <button type="button" className="login-back" onClick={onBackHome}>
          Back to home
        </button>

        <div className="login-brand">
          <div className="login-logo-wrap">
            <AimsLogo size="lg" showText={false} />
          </div>
          <h2>Create your AIMS account</h2>
          <p>Register to start managing inventory</p>
        </div>

        {error && <div className="login-error">{error}</div>}

        <form className="login-form" onSubmit={handleSubmit}>
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
            placeholder="you@company.com"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

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

          <button type="submit" disabled={loading}>
            {loading ? 'Creating account...' : 'Create account'}
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
