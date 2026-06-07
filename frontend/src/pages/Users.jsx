import { useEffect, useState } from 'react'
import { Trash2, UserPlus, Users as UsersIcon } from 'lucide-react'
import { createUser, deleteUser, getUsers, updateUserRole } from '../api/users'
import { useAuthStore } from '../store/authStore'

function Users() {
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

    const confirmed = window.confirm(`Remove ${user.name} from your company? This cannot be undone.`)

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
          <h2>
            <UsersIcon size={20} />
            Company users
          </h2>
        </div>
        <p className="page-intro">
          Create staff or manager accounts for your company. Choose a temporary password for the new user.
          They must set their own personal password immediately after their first login.
        </p>

        {formError && <div className="form-error-banner">{formError}</div>}

        {successMessage && (
          <div className="success-banner">
            <strong>{successMessage}</strong>
            <span>Share the temporary password securely with the new user.</span>
          </div>
        )}

        <form className="form-grid" onSubmit={handleSubmit}>
          <label htmlFor="user-name">
            Full name
            <input
              id="user-name"
              type="text"
              required
              value={name}
              onChange={(event) => setName(event.target.value)}
            />
          </label>

          <label htmlFor="user-email">
            Email address
            <input
              id="user-email"
              type="email"
              required
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
          </label>

          <label htmlFor="user-role">
            Role
            <select id="user-role" value={role} onChange={(event) => setRole(event.target.value)}>
              <option value="staff">Staff</option>
              <option value="manager">Manager</option>
            </select>
          </label>

          <label htmlFor="temp-password">
            Temporary password
            <input
              id="temp-password"
              type="password"
              required
              minLength={8}
              placeholder="At least 8 characters"
              value={temporaryPassword}
              onChange={(event) => setTemporaryPassword(event.target.value)}
            />
          </label>

          <label htmlFor="temp-password-confirm">
            Confirm temporary password
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
              {submitting ? 'Creating...' : 'Create user'}
            </button>
          </div>
        </form>
      </section>

      <section className="card">
        <h2>Team members</h2>
        {loading && <p className="page-intro">Loading users...</p>}
        {error && <div className="form-error-banner">{error}</div>}

        {!loading && !error && (
          <div className="table-wrap">
            <table className="product-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Actions</th>
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
                          <option value="staff">Staff</option>
                          <option value="manager">Manager</option>
                        </select>
                      ) : (
                        user.role
                      )}
                    </td>
                    <td>{user.must_change_password ? 'Awaiting password setup' : 'Active'}</td>
                    <td>
                      {isManageable(user) ? (
                        <button
                          type="button"
                          className="danger"
                          disabled={deletingUserId === user.id}
                          onClick={() => handleDelete(user)}
                        >
                          <Trash2 size={16} />
                          {deletingUserId === user.id ? 'Removing...' : 'Remove'}
                        </button>
                      ) : (
                        <span className="muted-text">Protected</span>
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
