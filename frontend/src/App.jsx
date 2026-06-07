import { lazy, Suspense, useState, useEffect } from 'react'
import LandingPage from './pages/LandingPage'
import Login from './pages/Login'
import Register from './pages/Register'
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

function PageLoader() {
  return <p className="page-message">Loading page...</p>
}

function App() {
  const [page, setPage] = useState('landing')
  const [authChecked, setAuthChecked] = useState(false)
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [userRole, setUserRole] = useState('staff')

  useEffect(() => {
    const token = localStorage.getItem('api_token')
    const role = localStorage.getItem('user_role') || 'staff'
    setIsAuthenticated(!!token)
    setUserRole(role)
    setAuthChecked(true)

    const handleUnauthorized = () => {
      setIsAuthenticated(false)
      setUserRole('staff')
      setPage('landing')
    }

    window.addEventListener('auth-unauthorized', handleUnauthorized)
    return () => window.removeEventListener('auth-unauthorized', handleUnauthorized)
  }, [])

  const handleAuthSuccess = (userData) => {
    localStorage.setItem('api_token', userData.token)
    localStorage.setItem('user_role', userData.user.role)
    localStorage.setItem('user', JSON.stringify(userData.user))
    setUserRole(userData.user.role)
    setIsAuthenticated(true)
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
      setIsAuthenticated(false)
      setUserRole('staff')
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
        onOpenDashboard={() => setPage('dashboard')}
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
          {page === 'activity-logs' && userRole === 'admin' && <ActivityLogs />}
        </Suspense>
      </div>
    </div>
  )
}

export default App
