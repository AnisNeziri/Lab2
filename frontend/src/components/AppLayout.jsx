import { useState } from 'react'
import { Outlet, useNavigate, useLocation } from 'react-router-dom'
import { Menu, X } from 'lucide-react'
import Sidebar from './Sidebar'
import { useAuthStore } from '../store/authStore'
import { logout } from '../api/login'
import { disconnectEcho } from '../lib/echo'

export default function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const navigate = useNavigate()
  const location = useLocation()
  const { role, clearAuth } = useAuthStore()

  const currentPage = location.pathname.replace('/', '') || 'dashboard'

  const handlePageChange = (page) => {
    navigate(`/${page}`)
    setSidebarOpen(false)
  }

  const handleLogout = async () => {
    try {
      await logout()
    } catch {
    } finally {
      disconnectEcho()
      clearAuth()
      navigate('/')
    }
  }

  return (
    <div className="app">
      <button
        type="button"
        className="mobile-menu-toggle"
        aria-label="Toggle navigation"
        onClick={() => setSidebarOpen((open) => !open)}
      >
        {sidebarOpen ? <X size={22} /> : <Menu size={22} />}
      </button>

      <button
        type="button"
        className={`sidebar-backdrop ${sidebarOpen ? 'is-visible' : ''}`}
        aria-label="Close navigation"
        onClick={() => setSidebarOpen(false)}
      />

      <Sidebar
        currentPage={currentPage}
        onPageChange={handlePageChange}
        userRole={role}
        onLogout={handleLogout}
        onHome={() => navigate('/')}
        isOpen={sidebarOpen}
      />

      <div className="main-content">
        <Outlet />
      </div>
    </div>
  )
}
