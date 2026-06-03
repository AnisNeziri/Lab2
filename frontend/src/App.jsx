import { useState } from 'react'
import Categories from './pages/Categories'
import Dashboard from './pages/Dashboard'
import Products from './pages/Products'
import Reports from './pages/Reports'
import Stock from './pages/Stock'
import './App.css'

function App() {
  const [page, setPage] = useState('products')

  return (
    <div className="app">
      <header>
        <div>
          <p className="eyebrow">Operations dashboard</p>
          <h1>Inventory System</h1>
          <p>Manage products, categories, stock movements, and reporting in one workspace.</p>
        </div>

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
            className={page === 'stock' ? 'active' : 'secondary'}
            onClick={() => setPage('stock')}
          >
            Stock
          </button>
          <button
            type="button"
            className={page === 'categories' ? 'active' : 'secondary'}
            onClick={() => setPage('categories')}
          >
            Categories
          </button>
          <button
            type="button"
            className={page === 'dashboard' ? 'active' : 'secondary'}
            onClick={() => setPage('dashboard')}
          >
            Dashboard
          </button>
          <button
            type="button"
            className={page === 'reports' ? 'active' : 'secondary'}
            onClick={() => setPage('reports')}
          >
            Reports
          </button>
        </nav>
      </header>

      {page === 'products' && <Products />}
      {page === 'stock' && <Stock />}
      {page === 'categories' && <Categories />}
      {page === 'dashboard' && <Dashboard />}
      {page === 'reports' && <Reports />}
    </div>
  )
}

export default App
