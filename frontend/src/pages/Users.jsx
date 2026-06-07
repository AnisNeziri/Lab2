import { useEffect, useState } from 'react'
import { UserPlus, Users as UsersIcon } from 'lucide-react'
import { createUser, getUsers } from '../api/users'

function Users() {
  const [users, setUsers] = useState([])
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [role, setRole] = useState('staff')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [formError, setFormError] = useState('')
  const [temporaryPassword, setTemporaryPassword] = useState('')

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

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setFormError('')
    setTemporaryPassword('')

    try {
      const result = await createUser({ name, email, role })
      setTemporaryPassword(result.temporary_password)
      setName('')
      setEmail('')
      setRole('staff')
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

  return (
    <main className="users-page">
      <section className="card">
        <div className="section-header">
          <h2>
            <UsersIcon size={20} />
            Company users
          </h2>
        </div>
        <p className="page-message">Create staff or manager accounts for your company. A one-time temporary password is generated automatically.</p>

        {formError && <div className="login-error">{formError}</div>}

        {temporaryPassword && (
          <div className="temp-password-banner">
            <strong>Temporary password (shown once):</strong>
            <code>{temporaryPassword}</code>
            <span>Share this securely. The user must set a new password on first login.</span>
          </div>
        )}

        <form className="form-grid" onSubmit={handleSubmit}>
          <label htmlFor="user-name">Full Name</label>
          <input
            id="user-name"
            type="text"
            required
            value={name}
            onChange={(event) => setName(event.target.value)}
          />

          <label htmlFor="user-email">Email Address</label>
          <input
            id="user-email"
            type="email"
            required
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />

          <label htmlFor="user-role">Role</label>
          <select id="user-role" value={role} onChange={(event) => setRole(event.target.value)}>
            <option value="staff">Staff</option>
            <option value="manager">Manager</option>
          </select>

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
        {loading && <p className="page-message">Loading users...</p>}
        {error && <p className="page-message">{error}</p>}

        {!loading && !error && (
          <div className="table-wrap">
            <table className="product-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Role</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                {users.map((user) => (
                  <tr key={user.id}>
                    <td>{user.name}</td>
                    <td>{user.email}</td>
                    <td>{user.role}</td>
                    <td>{user.must_change_password ? 'Awaiting password setup' : 'Active'}</td>
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
