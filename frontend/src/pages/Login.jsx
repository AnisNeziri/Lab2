import { useState } from 'react'
import { login } from '../api/login'
import AimsLogo from '../components/AimsLogo'

function Login({ onLoginSuccess, onBackHome, onRegister }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const handleSubmit = async (e) => {
    e.preventDefault()
    setLoading(true)
    setError('')

    try {
      const data = await login(email, password)
      onLoginSuccess(data)
    } catch (err) {
      setError(err.message || 'Invalid email or password.')
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
          <h2>Login to AIMS</h2>
          <p>Sign in to access your inventory dashboard</p>
        </div>

        {error && <div className="login-error">{error}</div>}

        <form className="login-form" onSubmit={handleSubmit}>
          <label htmlFor="email-address">Email Address</label>
          <input
            id="email-address"
            name="email"
            type="email"
            required
            placeholder="admin@enterprise.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />

          <label htmlFor="password">Password</label>
          <input
            id="password"
            name="password"
            type="password"
            required
            placeholder="••••••••"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />

          <button type="submit" disabled={loading}>
            {loading ? 'Signing in...' : 'Sign in'}
          </button>
        </form>

        <p className="auth-switch">
          Don&apos;t have an account?{' '}
          <button type="button" className="auth-switch-link" onClick={onRegister}>
            Register
          </button>
        </p>
      </div>
    </div>
  )
}

export default Login
