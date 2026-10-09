import { useTranslation } from '../hooks/useTranslation'
import { useEffect, useState } from 'react'
import { Trash2, UserPlus, Users as UsersIcon } from 'lucide-react'
import { createUser, deleteUser, getUsers, updateUserRole } from '../api/users'
import { useAuthStore } from '../store/authStore'

function Users() {
  const { language } = useTranslation(), text = (en, sq) => language === 'sq' ? sq : en
  const currentUserId = useAuthStore((state) => state.user?.id)
  const [users, setUsers] = useState([])
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('staff')
  const [temporaryPassword, setTemporaryPassword] = useState('')
  const [temporaryPasswordConfirmation, setTemporaryPasswordConfirmation] = useState('')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [updatingUserId, setUpdatingUserId] = useState(null)
  const [deletingUserId, setDeletingUserId] = useState(null)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')

  async function loadUsers() {
    try {
      setLoading(true)
      setError('')
      const data = await getUsers()
      setUsers(data)
    } catch (err) {
      setError(err.message || 'Could not load users.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadUsers()
  }, [])

  function isManageable(user) {
    return user.role !== 'admin' && user.id !== currentUserId
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setFormError('')
    setSuccessMessage('')

    if (temporaryPassword !== temporaryPasswordConfirmation) {
      setFormError('Temporary passwords do not match.')
      setSubmitting(false)
      return
    }

    try {
      const result = await createUser({
        name,
        email,
        role,
        temporary_password: temporaryPassword,
        temporary_password_confirmation: temporaryPasswordConfirmation,
      })
      setSuccessMessage(result.message || 'User created successfully.')
      setName('')
      setEmail('')
      setRole('staff')
      setTemporaryPassword('')
      setTemporaryPasswordConfirmation('')
      await loadUsers()
    } catch (err) {
      if (err.errors) {
        setFormError(Object.values(err.errors).flat().join(' '))
      } else {
        setFormError(err.message || 'Could not create user.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  async function handleRoleChange(user, nextRole) {
    if (!isManageable(user) || user.role === nextRole) {
      return
    }

    setUpdatingUserId(user.id)
    setError('')

    try {
      await updateUserRole(user.id, nextRole)
      await loadUsers()
    } catch (err) {
      setError(err.message || 'Could not update user role.')
    } finally {
      setUpdatingUserId(null)
    }
  }

  async function handleDelete(user) {
    if (!isManageable(user)) {
      return
    }

    const confirmed = window.confirm(text(`Remove ${user.name} from your company? This cannot be undone.`, `Të hiqet ${user.name} nga kompania? Ky veprim nuk mund të zhbëhet.`))

    if (!confirmed) {
      return
    }

    setDeletingUserId(user.id)
    setError('')

    try {
      await deleteUser(user.id)
      await loadUsers()
    } catch (err) {
      setError(err.message || 'Could not remove user.')
    } finally {
      setDeletingUserId(null)
    }
  }

  return (
    <main className="users-page page-stack">
      <section className="card">
        <div className="section-header">
          <h1>
            <UsersIcon size={20} />
            {text("Company users", "Përdoruesit e kompanisë")}
          </h1>
        </div>
        <p className="page-intro">
          {text('Create staff or manager accounts. New users must change their temporary password at first login.','Krijoni llogari stafi ose menaxheri. Përdoruesit e rinj duhet të ndryshojnë fjalëkalimin e përkohshëm në hyrjen e parë.')}
        </p>

        {formError && <div className="form-error-banner">{formError}</div>}

        {successMessage && (
          <div className="success-banner">
            <strong>{successMessage}</strong>
            <span>{text("Share the temporary password securely with the new user.", "Ndajeni fjalëkalimin e përkohshëm në mënyrë të sigurt me përdoruesin e ri.")}</span>
          </div>
        )}

        <form className="form-grid" onSubmit={handleSubmit}>
          <label htmlFor="user-name">
            {text("Full name", "Emri i plotë")}
            <input
              id="user-name"
              type="text"
              required
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </label>

          <label htmlFor="user-email">
            {text("Email address", "Adresa e emailit")}
            <input
              id="user-email"
              type="email"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </label>

          <label htmlFor="user-role">
            {text("Role", "Roli")}
            <select id="user-role" value={role} onChange={(event) => setRole(event.target.value)}>
              <option value="staff">{text("Staff", "Staf")}</option>
              <option value="manager">{text("Manager", "Menaxher")}</option>
            </select>
          </label>

          <label htmlFor="temp-password">
            {text("Temporary password", "Fjalëkalimi i përkohshëm")}
            <input
              id="temp-password"
              type="password"
              required
              minLength={8}
              placeholder={text('At least 8 characters','Të paktën 8 karaktere')}
              value={temporaryPassword}
              onChange={(event) => setTemporaryPassword(event.target.value)}
            />
          </label>

          <label htmlFor="temp-password-confirm">
            {text("Confirm temporary password", "Konfirmo fjalëkalimin e përkohshëm")}
            <input
              id="temp-password-confirm"
              type="password"
              required
              minLength={8}
              value={temporaryPasswordConfirmation}
              onChange={(event) => setTemporaryPasswordConfirmation(event.target.value)}
            />
          </label>

          <div className="form-actions">
            <button type="submit" disabled={submitting}>
              <UserPlus size={16} />
              {submitting ? text('Creating…','Duke krijuar…') : text('Create user','Krijo përdorues')}
            </button>
          </div>
        </form>
      </section>

      <section className="card">
        <h2>{text("Team members", "Anëtarët e ekipit")}</h2>
        {loading && <p className="page-intro">{text("Loading users...", "Duke ngarkuar përdoruesit…")}</p>}
        {error && <div className="form-error-banner">{error}</div>}

        {!loading && !error && (
          <div className="table-wrap">
            <table className="product-table">
              <thead>
                <tr>
                  <th>{text("Name", "Emri")}</th>
                  <th>{text("Email", "Email")}</th>
                  <th>{text("Role", "Roli")}</th>
                  <th>{text("Status", "Gjendja")}</th>
                  <th>{text("Actions", "Veprimet")}</th>
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <tr key={user.id}>
                    <td>{user.name}</td>
                    <td>{user.email}</td>
                    <td>
                      {isManageable(user) ? (
                        <select
                          value={user.role}
                          disabled={updatingUserId === user.id}
                          onChange={(event) => handleRoleChange(user, event.target.value)}
                        >
                          <option value="staff">{text("Staff", "Staf")}</option>
                          <option value="manager">{text("Manager", "Menaxher")}</option>
                        </select>
                      ) : (
                        user.role
                      )}
                    </td>
                    <td>{user.must_change_password ? text('Awaiting password setup','Në pritje të fjalëkalimit') : text('Active','Aktiv')}</td>
                    <td>
                      {isManageable(user) ? (
                        <button
                          type="button"
                          className="danger"
                          disabled={deletingUserId === user.id}
                          onClick={() => handleDelete(user)}
                        >
                          <Trash2 size={16} />
                          {deletingUserId === user.id ? text('Removing…','Duke hequr…') : text('Remove','Hiq')}
                        </button>
                      ) : (
                        <span className="muted-text">{text("Protected", "I mbrojtur")}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </main>
  )
}

export default Users
