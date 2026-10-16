import { useState } from 'react'
import { changePassword } from '../api/password'
import AimsLogo from '../components/AimsLogo'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'
import '../styles/AuthPages.css'

function ChangePassword({ onPasswordChanged, onLogout, requiresChange = true }) {
  const { language } = useTranslation()
  const text = (en, sq) => language === 'sq' ? sq : en
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
      setError(text('New passwords do not match.', 'Fjalëkalimet e reja nuk përputhen.'))
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
      if (data.access_token) useAuthStore.getState().setTokens(data.access_token, data.refresh_token)
      onPasswordChanged(data)
    } catch (err) {
      if (err.errors) {
        setError(Object.values(err.errors).flat().join(' '))
      } else {
        setError(err.message || text('Could not update password.', 'Fjalëkalimi nuk mund të përditësohej.'))
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
          <h1>{requiresChange ? text('Set a new password', 'Vendos një fjalëkalim të ri') : text('Change password', 'Ndrysho fjalëkalimin')}</h1>
          <p>
            {requiresChange
              ? text('Your temporary password was accepted. Choose a new password to continue.', 'Fjalëkalimi i përkohshëm u pranua. Zgjidh një fjalëkalim të ri për të vazhduar.')
              : text('Update your account password.', 'Përditëso fjalëkalimin e llogarisë.')}
          </p>
        </div>

        {error && <div className="auth-error" role="alert">{error}</div>}

        <form className="auth-form" onSubmit={handleSubmit}>
          {!requiresChange && (
            <>
              <label htmlFor="current-password">{text('Current Password', 'Fjalëkalimi aktual')}</label>
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

          <label htmlFor="new-password">{text('New Password', 'Fjalëkalimi i ri')}</label>
          <input
            id="new-password"
            name="password"
            type="password"
            required
            minLength={8}
            placeholder={text('At least 8 characters', 'Të paktën 8 karaktere')}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />

          <label htmlFor="confirm-password">{text('Confirm New Password', 'Konfirmo fjalëkalimin e ri')}</label>
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
            {loading ? text('Saving...', 'Duke ruajtur...') : text('Save new password', 'Ruaj fjalëkalimin e ri')}
          </button>
        </form>

        {requiresChange && (
          <p className="auth-switch">
            <button type="button" className="auth-switch-link" onClick={onLogout}>
              {text('Sign out', 'Dil nga llogaria')}
            </button>
          </p>
        )}
      </div>
    </div>
  )
}

export default ChangePassword
