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

  useEffect(() => {
    const token = localStorage.getItem('api_token')
    setIsAuthenticated(!!token)
    if (!token) {
      setPage('login')
    }
  }, [])

  if (page === 'login') {
    return <Login onLoginSuccess={() => setPage('dashboard')} />
  }

  if (!isAuthenticated) {
    return null
  }

  return (
    <div className="app">
      <Sidebar currentPage={page} onPageChange={setPage} />
      <div className="main-content">
        {page === 'products' && <Products />}
        {page === 'stock' && <Stock />}
        {page === 'categories' && <Categories />}
        {page === 'dashboard' && <Dashboard />}
        {page === 'reports' && <Reports />}
        {page === 'invoices' && <Invoices />}
        {page === 'activity-logs' && <ActivityLogs />}
      </div>
    </div>
  )
}

export default App
