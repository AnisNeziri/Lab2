import { lazy, Suspense, useState, useEffect } from 'react'
import LandingPage from './pages/LandingPage'
import Login from './pages/Login'
import Register from './pages/Register'
import ChangePassword from './pages/ChangePassword'
import Sidebar from './components/Sidebar'
import { logout } from './api/login'
import './App.css'

const Dashboard = lazy(() => import('./pages/Dashboard'))
const Products = lazy(() => import('./pages/Products'))
const Stock = lazy(() => import('./pages/Stock'))
const Categories = lazy(() => import('./pages/Categories'))
const Suppliers = lazy(() => import('./pages/Suppliers'))
const Reports = lazy(() => import('./pages/Reports'))
const Invoices = lazy(() => import('./pages/Invoices'))
const ActivityLogs = lazy(() => import('./pages/ActivityLogs'))
const Users = lazy(() => import('./pages/Users'))

function PageLoader() {
  return <p className="page-message">Loading page...</p>
}

function App() {
  const [page, setPage] = useState('landing')
  const [authChecked, setAuthChecked] = useState(false)
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [userRole, setUserRole] = useState('staff')
  const [mustChangePassword, setMustChangePassword] = useState(false)

  useEffect(() => {
    const token = localStorage.getItem('api_token')
    const role = localStorage.getItem('user_role') || 'staff'
    const storedUser = localStorage.getItem('user')
    const user = storedUser ? JSON.parse(storedUser) : null

    setIsAuthenticated(!!token)
    setUserRole(role)
    setMustChangePassword(
      localStorage.getItem('must_change_password') === 'true' || user?.must_change_password === true
    )
    setAuthChecked(true)

    const handleUnauthorized = () => {
      setIsAuthenticated(false)
      setUserRole('staff')
      setMustChangePassword(false)
      setPage('landing')
    }

    const handlePasswordChangeRequired = () => {
      setMustChangePassword(true)
      setPage('change-password')
    }

    window.addEventListener('auth-unauthorized', handleUnauthorized)
    window.addEventListener('password-change-required', handlePasswordChangeRequired)
    return () => {
      window.removeEventListener('auth-unauthorized', handleUnauthorized)
      window.removeEventListener('password-change-required', handlePasswordChangeRequired)
    }
  }, [])

  const persistUser = (userData) => {
    localStorage.setItem('api_token', userData.token)
    localStorage.setItem('user_role', userData.user.role)
    localStorage.setItem('user', JSON.stringify(userData.user))

    if (userData.user.must_change_password) {
      localStorage.setItem('must_change_password', 'true')
      setMustChangePassword(true)
    } else {
      localStorage.removeItem('must_change_password')
      setMustChangePassword(false)
    }

    setUserRole(userData.user.role)
    setIsAuthenticated(true)
  }

  const handleAuthSuccess = (userData) => {
    persistUser(userData)
    setPage(userData.user.must_change_password ? 'change-password' : 'dashboard')
  }

  const handlePasswordChanged = (data) => {
    const token = localStorage.getItem('api_token')
    persistUser({ token, user: data.user })
    setPage('dashboard')
  }

  const handleLogout = async () => {
    try {
      await logout()
    } catch {
    } finally {
      localStorage.removeItem('api_token')
      localStorage.removeItem('user')
      localStorage.removeItem('user_role')
      localStorage.removeItem('must_change_password')
      setIsAuthenticated(false)
      setUserRole('staff')
      setMustChangePassword(false)
      setPage('landing')
    }
  }

  if (!authChecked) {
    return null
  }

  if (page === 'landing') {
    return (
      <LandingPage
        isAuthenticated={isAuthenticated}
        onLogin={() => setPage('login')}
        onRegister={() => setPage('register')}
        onOpenDashboard={() => setPage(isAuthenticated && !mustChangePassword ? 'dashboard' : 'login')}
      />
    )
  }

  if (page === 'login') {
    return (
      <Login
        onLoginSuccess={handleAuthSuccess}
        onBackHome={() => setPage('landing')}
        onRegister={() => setPage('register')}
      />
    )
  }

  if (page === 'register') {
    return (
      <Register
        onRegisterSuccess={handleAuthSuccess}
        onBackHome={() => setPage('landing')}
        onLogin={() => setPage('login')}
      />
    )
  }

  if (!isAuthenticated) {
    return (
      <LandingPage
        isAuthenticated={false}
        onLogin={() => setPage('login')}
        onRegister={() => setPage('register')}
        onOpenDashboard={() => setPage('login')}
      />
    )
  }

  if (mustChangePassword || page === 'change-password') {
    return (
      <ChangePassword
        requiresChange={mustChangePassword}
        onPasswordChanged={handlePasswordChanged}
        onLogout={handleLogout}
      />
    )
  }

  return (
    <div className="app">
      <Sidebar
        currentPage={page}
        onPageChange={setPage}
        userRole={userRole}
        onLogout={handleLogout}
        onHome={() => setPage('landing')}
      />
      <div className="main-content">
        <Suspense fallback={<PageLoader />}>
          {page === 'products' && <Products userRole={userRole} />}
          {page === 'stock' && <Stock />}
          {page === 'categories' && <Categories userRole={userRole} />}
          {page === 'suppliers' && <Suppliers userRole={userRole} />}
          {page === 'dashboard' && <Dashboard onNavigate={setPage} />}
          {page === 'reports' && <Reports />}
          {page === 'invoices' && <Invoices />}
          {page === 'users' && userRole === 'admin' && <Users />}
          {page === 'activity-logs' && userRole === 'admin' && <ActivityLogs />}
        </Suspense>
      </div>
    </div>
  )
}

export default App
