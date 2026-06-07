import { lazy, Suspense, useEffect } from 'react'
import { BrowserRouter, Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import LandingPage from './pages/LandingPage'
import Login from './pages/Login'
import Register from './pages/Register'
import ChangePassword from './pages/ChangePassword'
import AppLayout from './components/AppLayout'
import { useAuthStore } from './store/authStore'
import { logout } from './api/login'
import { initEcho, disconnectEcho } from './lib/echo'
import { getNotifications } from './api/notifications'
import { useNotificationStore } from './store/notificationStore'
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
const Cms = lazy(() => import('./pages/Cms'))

function PageLoader() {
  return <p className="page-message">Loading page...</p>
}

function ProtectedRoute({ children, adminOnly = false }) {
  const { isAuthenticated, mustChangePassword, role } = useAuthStore()

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  if (mustChangePassword) {
    return <Navigate to="/change-password" replace />
  }

  if (adminOnly && role !== 'admin') {
    return <Navigate to="/dashboard" replace />
  }

  return children
}

function RealtimeProvider({ children }) {
  const { token, user, isAuthenticated, mustChangePassword } = useAuthStore()
  const setNotifications = useNotificationStore((state) => state.setNotifications)
  const addNotification = useNotificationStore((state) => state.addNotification)

  useEffect(() => {
    if (!isAuthenticated || mustChangePassword || !token || !user?.company_id) {
      disconnectEcho()
      return undefined
    }

    getNotifications()
      .then((data) => setNotifications(data.notifications || []))
      .catch(() => setNotifications([]))

    const echo = initEcho(token, user.company_id)
    if (!echo) {
      return undefined
    }

    const channel = echo.private(`company.${user.company_id}`)

    channel.error((error) => {
      console.error('[Echo] Channel subscription failed', error)
    })

    channel.listen('.notification.created', (event) => {
      if (event.notification) {
        addNotification(event.notification)
      }
    })

    channel.listen('.dashboard.updated', () => {
      window.dispatchEvent(new CustomEvent('dashboard-refresh'))
    })

    channel.listen('.stock.updated', () => {
      window.dispatchEvent(new CustomEvent('stock-refresh'))
    })

    return () => {
      channel.stopListening('.notification.created')
      channel.stopListening('.dashboard.updated')
      channel.stopListening('.stock.updated')
      disconnectEcho()
    }
  }, [token, user?.company_id, isAuthenticated, mustChangePassword, setNotifications, addNotification])

  return children
}

function AuthEvents() {
  const navigate = useNavigate()
  const clearAuth = useAuthStore((state) => state.clearAuth)
  const setTokens = useAuthStore((state) => state.setTokens)

  useEffect(() => {
    const handleUnauthorized = () => {
      disconnectEcho()
      clearAuth()
      navigate('/')
    }

    const handlePasswordChangeRequired = () => {
      navigate('/change-password')
    }

    const handleTokenRefreshed = (event) => {
      setTokens(event.detail.accessToken, event.detail.refreshToken)
    }

    window.addEventListener('auth-unauthorized', handleUnauthorized)
    window.addEventListener('password-change-required', handlePasswordChangeRequired)
    window.addEventListener('auth-token-refreshed', handleTokenRefreshed)
    return () => {
      window.removeEventListener('auth-unauthorized', handleUnauthorized)
      window.removeEventListener('password-change-required', handlePasswordChangeRequired)
      window.removeEventListener('auth-token-refreshed', handleTokenRefreshed)
    }
  }, [navigate, clearAuth, setTokens])

  return null
}

function AppRoutes() {
  const navigate = useNavigate()
  const { hydrate, isAuthenticated, mustChangePassword, setAuth, updateUser, clearAuth } = useAuthStore()

  useEffect(() => {
    hydrate()
  }, [hydrate])

  const handleAuthSuccess = (userData) => {
    const accessToken = userData.access_token || userData.token
    setAuth(accessToken, userData.user, userData.refresh_token)
    navigate(userData.user.must_change_password ? '/change-password' : '/dashboard', { replace: true })
  }

  const handlePasswordChanged = (data) => {
    const token = useAuthStore.getState().token
    updateUser(data.user)
    if (token) {
      setAuth(token, data.user)
    }
    navigate('/dashboard', { replace: true })
  }

  const handleLogout = async () => {
    try {
      await logout()
    } catch {
    } finally {
      disconnectEcho()
      clearAuth()
    }
  }

  return (
    <>
      <AuthEvents />
      <Routes>
        <Route
          path="/"
          element={
            <LandingPage
              isAuthenticated={isAuthenticated}
              onLogin={() => window.location.assign('/login')}
              onRegister={() => window.location.assign('/register')}
              onOpenDashboard={() => window.location.assign(isAuthenticated && !mustChangePassword ? '/dashboard' : '/login')}
            />
          }
        />
        <Route
          path="/login"
          element={
            isAuthenticated
              ? <Navigate to={mustChangePassword ? '/change-password' : '/dashboard'} replace />
              : <Login onLoginSuccess={handleAuthSuccess} onBackHome={() => navigate('/')} onRegister={() => navigate('/register')} />
          }
        />
        <Route
          path="/register"
          element={
            isAuthenticated && !mustChangePassword
              ? <Navigate to="/dashboard" replace />
              : <Register onRegisterSuccess={handleAuthSuccess} onBackHome={() => window.location.assign('/')} onLogin={() => window.location.assign('/login')} />
          }
        />
        <Route
          path="/change-password"
          element={
            !isAuthenticated
              ? <Navigate to="/login" replace />
              : <ChangePassword requiresChange={mustChangePassword} onPasswordChanged={handlePasswordChanged} onLogout={handleLogout} />
          }
        />
        <Route
          element={
            <ProtectedRoute>
              <RealtimeProvider>
                <AppLayout />
              </RealtimeProvider>
            </ProtectedRoute>
          }
        >
          <Route path="/dashboard" element={<Suspense fallback={<PageLoader />}><Dashboard /></Suspense>} />
          <Route path="/products" element={<Suspense fallback={<PageLoader />}><Products /></Suspense>} />
          <Route path="/stock" element={<Suspense fallback={<PageLoader />}><Stock /></Suspense>} />
          <Route path="/categories" element={<Suspense fallback={<PageLoader />}><Categories /></Suspense>} />
          <Route path="/suppliers" element={<Suspense fallback={<PageLoader />}><Suppliers /></Suspense>} />
          <Route path="/reports" element={<Suspense fallback={<PageLoader />}><Reports /></Suspense>} />
          <Route path="/invoices" element={<Suspense fallback={<PageLoader />}><Invoices /></Suspense>} />
          <Route path="/users" element={<ProtectedRoute adminOnly><Suspense fallback={<PageLoader />}><Users /></Suspense></ProtectedRoute>} />
          <Route path="/activity-logs" element={<ProtectedRoute adminOnly><Suspense fallback={<PageLoader />}><ActivityLogs /></Suspense></ProtectedRoute>} />
          <Route path="/cms" element={<ProtectedRoute adminOnly><Suspense fallback={<PageLoader />}><Cms /></Suspense></ProtectedRoute>} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </>
  )
}

export default function App() {
  return (
    <BrowserRouter>
      <AppRoutes />
    </BrowserRouter>
  )
}
