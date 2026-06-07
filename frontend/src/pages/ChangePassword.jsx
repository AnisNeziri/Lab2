import { useState } from 'react'
import { changePassword } from '../api/password'
import AimsLogo from '../components/AimsLogo'
import '../styles/AuthPages.css'

function ChangePassword({ onPasswordChanged, onLogout, requiresChange = true }) {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const handleSubmit = async (event) => {
    event.preventDefault()
    setLoading(true)
    setError('')

    if (password !== passwordConfirmation) {
      setError('New passwords do not match.')
      setLoading(false)
      return
    }

    try {
      const payload = requiresChange
        ? { password, password_confirmation: passwordConfirmation }
        : {
            current_password: currentPassword,
            password,
            password_confirmation: passwordConfirmation,
          }

      const data = await changePassword(payload)
      onPasswordChanged(data)
    } catch (err) {
      if (err.errors) {
        setError(Object.values(err.errors).flat().join(' '))
      } else {
        setError(err.message || 'Could not update password.')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page">
      <div className="auth-shell">
        <div className="auth-brand">
          <AimsLogo size="lg" showText={false} />
          <h1>{requiresChange ? 'Set a new password' : 'Change password'}</h1>
          <p>
            {requiresChange
              ? 'Your temporary password was accepted. Choose a new password to continue.'
              : 'Update your account password.'}
          </p>
        </div>

        {error && <div className="auth-error">{error}</div>}

        <form className="auth-form" onSubmit={handleSubmit}>
          {!requiresChange && (
            <>
              <label htmlFor="current-password">Current Password</label>
              <input
                id="current-password"
                name="current_password"
                type="password"
                required
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
              />
            </>
          )}

          <label htmlFor="new-password">New Password</label>
          <input
            id="new-password"
            name="password"
            type="password"
            required
            minLength={8}
            placeholder="At least 8 characters"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />

          <label htmlFor="confirm-password">Confirm New Password</label>
          <input
            id="confirm-password"
            name="password_confirmation"
            type="password"
            required
            minLength={8}
            value={passwordConfirmation}
            onChange={(event) => setPasswordConfirmation(event.target.value)}
          />

          <button type="submit" className="auth-submit" disabled={loading}>
            {loading ? 'Saving...' : 'Save new password'}
          </button>
        </form>

        {requiresChange && (
          <p className="auth-switch">
            <button type="button" className="auth-switch-link" onClick={onLogout}>
              Sign out
            </button>
          </p>
        )}
      </div>
    </div>
  )
}

export default ChangePassword
