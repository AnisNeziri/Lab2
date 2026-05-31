import { useState } from 'react'
import Dashboard from './pages/Dashboard'
import Products from './pages/Products'
import './App.css'

function App() {
  const [page, setPage] = useState('products')

  return (
    <div className="app">
      <header>
        <h1>Inventory System</h1>
        <p>Stock and product management</p>

        <nav className="main-nav">
          <button
            type="button"
            className={page === 'products' ? 'active' : 'secondary'}
            onClick={() => setPage('products')}
          >
            Products
          </button>
          <button
            type="button"
            className={page === 'dashboard' ? 'active' : 'secondary'}
            onClick={() => setPage('dashboard')}
          >
            Dashboard
          </button>
        </nav>
      </header>

      {page === 'products' ? <Products /> : <Dashboard />}
    </div>
  )
}

export default App
