import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { forgotPassword } from '../api/authFlow'
import AimsLogo from '../components/AimsLogo'
import '../styles/AuthPages.css'

export default function ForgotPassword({ onBackLogin }) {
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(event) {
    event.preventDefault()
    setLoading(true)
    setError('')
    setMessage('')

    try {
      const data = await forgotPassword(email)
      setMessage(data.message)
    } catch (err) {
      setError(err.message || 'Could not send reset email.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page">
      <div className="auth-shell">
        <div className="auth-brand">
          <AimsLogo size="lg" showText={false} />
          <h1>Forgot password</h1>
          <p>We will email you a secure link to reset your password.</p>
        </div>

        {message ? <div className="auth-success">{message}</div> : null}
        {error ? <div className="auth-error">{error}</div> : null}

        <form className="auth-form" onSubmit={handleSubmit}>
          <label htmlFor="reset-email">Email</label>
          <input
            id="reset-email"
            type="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

          <button type="submit" className="auth-submit" disabled={loading}>
            {loading ? 'Sending...' : 'Send reset link'}
          </button>
        </form>

        <p className="auth-switch">
          <button type="button" className="auth-switch-link" onClick={() => (onBackLogin ? onBackLogin() : navigate('/login'))}>
            Back to sign in
          </button>
        </p>
      </div>
    </div>
  )
}
