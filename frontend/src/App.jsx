import { useState, useEffect } from 'react'
import Categories from './pages/Categories'
import Dashboard from './pages/Dashboard'
import Products from './pages/Products'
import Reports from './pages/Reports'
import Stock from './pages/Stock'
import Invoices from './pages/Invoices'
import ActivityLogs from './pages/ActivityLogs'
import Login from './pages/Login'
import Sidebar from './components/Sidebar'
import './App.css'

function App() {
  const [page, setPage] = useState('dashboard')
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [userRole, setUserRole] = useState('staff')

  useEffect(() => {
    const token = localStorage.getItem('api_token')
    const role = localStorage.getItem('user_role') || 'staff'
    setIsAuthenticated(!!token)
    setUserRole(role)
    if (!token) {
      setPage('login')
    }
  }, [])

  const handleLoginSuccess = (userData) => {
    localStorage.setItem('api_token', userData.token)
    localStorage.setItem('user_role', userData.user.role)
    setUserRole(userData.user.role)
    setIsAuthenticated(true)
    setPage('dashboard')
  }

  if (page === 'login') {
    return <Login onLoginSuccess={handleLoginSuccess} />
  }

  if (!isAuthenticated) {
    return null
  }

  return (
    <div className="app">
      <Sidebar currentPage={page} onPageChange={setPage} userRole={userRole} />
      <div className="main-content">
        {page === 'products' && <Products userRole={userRole} />}
        {page === 'stock' && <Stock />}
        {page === 'categories' && <Categories userRole={userRole} />}
        {page === 'dashboard' && <Dashboard />}
        {page === 'reports' && <Reports />}
        {page === 'invoices' && <Invoices />}
        {page === 'activity-logs' && <ActivityLogs />}
      </div>
    </div>
  )
}

export default App
