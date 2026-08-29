import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { resendVerificationEmail, verifyEmail } from '../api/authFlow'
import AimsLogo from '../components/AimsLogo'
import '../styles/AuthPages.css'

export default function VerifyEmail() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [email, setEmail] = useState(searchParams.get('email') || '')
  const [code, setCode] = useState('')
  const [token] = useState(searchParams.get('token') || '')
  const [loading, setLoading] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    if (email && token) {
      handleVerify(email, token, null)
    }
  }, [])

  async function handleVerify(verifyEmailValue, verifyToken, verifyCode) {
    setLoading(true)
    setError('')
    setMessage('')

    try {
      const data = await verifyEmail({
        email: verifyEmailValue,
        token: verifyToken || undefined,
        code: verifyCode || undefined,
      })
      setMessage(data.message || 'Email verified successfully.')
      setTimeout(() => navigate('/login', { replace: true }), 1200)
    } catch (err) {
      setError(err.message || 'Verification failed.')
    } finally {
      setLoading(false)
    }
  }

  async function handleSubmit(event) {
    event.preventDefault()
    await handleVerify(email, token, code)
  }

  async function handleResend() {
    setLoading(true)
    setError('')
    try {
      const data = await resendVerificationEmail(email)
      setMessage(data.message)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page">
      <div className="auth-shell">
        <div className="auth-brand">
          <AimsLogo size="lg" showText={false} />
          <h1>Verify your email</h1>
          <p>Enter the 6-digit code sent to your inbox to activate your account.</p>
        </div>

        {message ? <div className="auth-success">{message}</div> : null}
        {error ? <div className="auth-error">{error}</div> : null}

        <form className="auth-form" onSubmit={handleSubmit}>
          <label htmlFor="verify-email">Email</label>
          <input
            id="verify-email"
            type="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

          <label htmlFor="verify-code">Verification code</label>
          <input
            id="verify-code"
            type="text"
            inputMode="numeric"
            maxLength={6}
            placeholder="123456"
            value={code}
            onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))}
          />

          <button type="submit" className="auth-submit" disabled={loading}>
            {loading ? 'Verifying...' : 'Verify email'}
          </button>
        </form>

        <p className="auth-switch">
          Did not receive it?{' '}
          <button type="button" className="auth-switch-link" onClick={handleResend} disabled={!email || loading}>
            Resend verification email
          </button>
        </p>
      </div>
    </div>
  )
}
