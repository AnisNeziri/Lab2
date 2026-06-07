import { useState } from 'react'
import { login } from '../api/login'
import '../styles/AuthPages.css'

function Login({ onLoginSuccess, onBackHome, onRegister }) {
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
    <div className="auth-page">
      <div className="auth-shell">
        <button type="button" className="auth-back" onClick={onBackHome}>
          Back to home
        </button>

        <div className="auth-brand">
          <h1>Welcome back</h1>
          <p>Sign in to your company inventory dashboard</p>
        </div>

        {error && <div className="auth-error">{error}</div>}

        <form className="auth-form" onSubmit={handleSubmit}>
          <label htmlFor="email-address">Email Address</label>
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

          <label htmlFor="password">Password</label>
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

          <button type="submit" className="auth-submit" disabled={loading}>
            {loading ? 'Signing in...' : 'Sign in'}
          </button>
        </form>

        <p className="auth-switch">
          Register a new company?{' '}
          <button type="button" className="auth-switch-link" onClick={onRegister}>
            Create company account
          </button>
        </p>
      </div>
    </div>
  )
}

export default Login
