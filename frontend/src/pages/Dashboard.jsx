import { useEffect, useState, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { getDashboard } from '../api/dashboard'
import { getActivityLogs } from '../api/activityLogs'
import { AreaChart, Area, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, PieChart, Pie, Cell, Legend } from 'recharts'

function Dashboard() {
  const navigate = useNavigate()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [activityLogs, setActivityLogs] = useState([])
  const [timeFilter, setTimeFilter] = useState('week')

  const loadDashboard = useCallback(async () => {
    try {
      setLoading(true)
      setError('')
      const summary = await getDashboard()
      setData(summary)
    } catch {
      setError('Could not load dashboard data.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadDashboard()
  }, [loadDashboard])

  useEffect(() => {
    const handleRefresh = () => loadDashboard()
    window.addEventListener('dashboard-refresh', handleRefresh)
    return () => window.removeEventListener('dashboard-refresh', handleRefresh)
  }, [loadDashboard])

  useEffect(() => {
    async function loadActivityLogs() {
      try {
        const logs = await getActivityLogs(1)
        setActivityLogs(logs.data?.slice(0, 5) || [])
      } catch {
      }
    }

    loadActivityLogs()
  }, [])

  if (loading) {
    return <p className="page-message">Loading dashboard...</p>
  }

  if (error) {
    return <p className="error page-message">{error}</p>
  }

  const getFilteredMovements = () => {
    const now = new Date()
    const movements = data.recent_movements.filter(m => m.type === 'in' || m.type === 'out')

    if (timeFilter === 'today') {
      return movements.filter(m => {
        const movementDate = new Date(m.created_at)
        return movementDate.toDateString() === now.toDateString()
      })
    } else if (timeFilter === 'month') {
      const monthAgo = new Date(now.getFullYear(), now.getMonth(), 1)
      return movements.filter(m => new Date(m.created_at) >= monthAgo)
    } else if (timeFilter === 'year') {
      const yearAgo = new Date(now.getFullYear(), 0, 1)
      return movements.filter(m => new Date(m.created_at) >= yearAgo)
    }
    const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)
    return movements.filter(m => new Date(m.created_at) >= weekAgo)
  }

  const stockMovementData = getFilteredMovements()
    .reduce((acc, movement) => {
      const date = new Date(movement.created_at).toLocaleDateString()
      const existing = acc.find(item => item.date === date)

      if (existing) {
        if (movement.type === 'in') {
          existing.stockIn += movement.quantity
        } else {
          existing.stockOut += movement.quantity
        }
      } else {
        acc.push({
          date,
          stockIn: movement.type === 'in' ? movement.quantity : 0,
          stockOut: movement.type === 'out' ? movement.quantity : 0,
        })
      }

      return acc
    }, [])

  const categoryData = data.category_values || []

  const COLORS = ['#0f766e', '#0891b2', '#0284c7', '#0369a1', '#075985', '#0c4a6e']

  return (
    <main className="dashboard-page">
      <section className="stats-grid">
        <article className="stat-card">
          <p className="stat-label">Total products</p>
          <p className="stat-value">{data.total_products}</p>
          <p className="stat-note">Across {data.total_categories ?? 0} categor{(data.total_categories ?? 0) === 1 ? 'y' : 'ies'}</p>
        </article>

        <article className="stat-card">
          <p className="stat-label">Total units in stock</p>
          <p className="stat-value">{data.total_units}</p>
          <p className="stat-note">Sum of quantity across all products</p>
        </article>

        <article className="stat-card">
          <p className="stat-label">Inventory value</p>
          <p className="stat-value">${Number(data.total_value).toFixed(2)}</p>
          <p className="stat-note">Quantity &times; selling price</p>
        </article>

        <article className="stat-card">
          <p className="stat-label">Total suppliers</p>
          <p className="stat-value">{data.total_suppliers}</p>
          <p className="stat-note">Active supplier records</p>
        </article>

        <article className="stat-card warning">
          <p className="stat-label">Low stock items</p>
          <p className="stat-value">{data.low_stock_count}</p>
          <p className="stat-note">At or below each product&apos;s minimum quantity</p>
        </article>

        <article className="stat-card danger">
          <p className="stat-label">Out of stock</p>
          <p className="stat-value">{data.out_of_stock_count}</p>
          <p className="stat-note danger-note">Products with zero units left</p>
        </article>
      </section>

      <section className="stats-grid-3">
        <article className="card">
          <h2>Cost Value (Purchase Price)</h2>
          <p className="stat-value" style={{ fontSize: '2rem', color: '#0f766e' }}>
            ${Number(data.inventory_value || 0).toFixed(2)}
          </p>
          <p className="stat-note">Total inventory cost at purchase price</p>
        </article>

        <article className="card">
          <h2>Expected Net Profit</h2>
          <p className="stat-value" style={{ fontSize: '2rem', color: '#166534' }}>
            ${Number(data.expected_net_profit || 0).toFixed(2)}
          </p>
          <p className="stat-note">Potential profit if all inventory sold</p>
        </article>

        <article className="card">
          <h2>Profit Margin</h2>
          <p className="stat-value" style={{ fontSize: '2rem', color: '#0891b2' }}>
            {data.inventory_value > 0 
              ? ((data.expected_net_profit / data.inventory_value) * 100).toFixed(1) + '%'
              : '0%'}
          </p>
          <p className="stat-note">Expected profit margin on inventory</p>
        </article>
      </section>

      <section className="stats-grid-3">
        <article className="card">
          <div className="card-header">
            <h2>Stock Movements</h2>
            <select
              value={timeFilter}
              onChange={(e) => setTimeFilter(e.target.value)}
              className="time-filter-select"
            >
              <option value="week">Last 7 Days</option>
              <option value="today">Today</option>
              <option value="month">This Month</option>
              <option value="year">This Year</option>
            </select>
          </div>
          <ResponsiveContainer width="100%" height={250}>
            <AreaChart data={stockMovementData}>
              <CartesianGrid strokeDasharray="3 3" />
              <XAxis dataKey="date" />
              <YAxis />
              <Tooltip />
              <Area type="monotone" dataKey="stockIn" stackId="1" stroke="#0f766e" fill="#0f766e" name="Stock In" />
              <Area type="monotone" dataKey="stockOut" stackId="2" stroke="#ef4444" fill="#ef4444" name="Stock Out" />
            </AreaChart>
          </ResponsiveContainer>
        </article>

        <article className="card">
          <h2>Inventory by Category</h2>
          <ResponsiveContainer width="100%" height={250}>
            <PieChart>
              <Pie
                data={categoryData}
                cx="50%"
                cy="50%"
                labelLine={false}
                label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`}
                outerRadius={80}
                fill="#8884d8"
                dataKey="value"
              >
                {categoryData.map((entry, index) => (
                  <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                ))}
              </Pie>
              <Tooltip />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </article>

        <article className="card">
          <h2>Quick Stats</h2>
          <div className="detail-list">
            <dt>Total Categories</dt>
            <dd>{data.total_categories ?? 0}</dd>
            <dt>Avg Product Price</dt>
            <dd>${data.total_products > 0 ? (data.total_value / data.total_products).toFixed(2) : '0.00'}</dd>
            <dt>Stock Turnover</dt>
            <dd>{typeof data.stock_turnover === 'number' ? `${data.stock_turnover}x` : 'N/A'}</dd>
          </div>
        </article>

        <article className="card">
          <h2>Recent Activity</h2>
          {activityLogs.length === 0 ? (
            <p>No recent activity.</p>
          ) : (
            <table className="mini-table">
              <thead>
                <tr>
                  <th>Action</th>
                  <th>User</th>
                  <th>Time</th>
                </tr>
              </thead>
              <tbody>
                {activityLogs.map((log) => (
                  <tr key={log.id}>
                    <td>{log.action}</td>
                    <td>{log.user?.name || 'System'}</td>
                    <td>{new Date(log.created_at).toLocaleTimeString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </article>
      </section>

      {data.out_of_stock_products && data.out_of_stock_products.length > 0 && (
        <section className="card">
          <h2>Out of stock</h2>
          <table className="product-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>SKU</th>
                <th>Min</th>
              </tr>
            </thead>
            <tbody>
              {data.out_of_stock_products.map((product) => (
                <tr key={product.id}>
                  <td>{product.name}</td>
                  <td>{product.category?.name ?? '-'}</td>
                  <td>{product.sku}</td>
                  <td>{product.min_quantity}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <section className="card">
        <h2>Quick Alerts - Low Stock</h2>

        {data.low_stock_products.length === 0 ? (
          <p>All products are above the low stock threshold.</p>
        ) : (
          <table className="product-table">
            <thead>
              <tr>
                <th>Product</th>
                <th>Category</th>
                <th>SKU</th>
                <th>Qty</th>
                <th>Min</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {data.low_stock_products.map((product) => (
                <tr key={product.id}>
                  <td>{product.name}</td>
                  <td>{product.category?.name ?? '-'}</td>
                  <td>{product.sku}</td>
                  <td className="low-stock">{product.quantity}</td>
                  <td>{product.min_quantity}</td>
                  <td>
                    <button
                      type="button"
                      className="primary"
                      onClick={() => navigate('/stock')}
                    >
                      + Add Stock
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      <section className="card">
        <h2>Recent stock activity</h2>

        {data.recent_movements.length === 0 ? (
          <p>No stock movements yet.</p>
        ) : (
          <table className="product-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Product</th>
                <th>Type</th>
                <th>Qty</th>
                <th>Reason</th>
              </tr>
            </thead>
            <tbody>
              {data.recent_movements.map((movement) => (
                <tr key={movement.id}>
                  <td>{new Date(movement.created_at).toLocaleString()}</td>
                  <td>{movement.product?.name ?? '-'}</td>
                  <td>
                    <span className={`badge badge-${movement.type}`}>
                      {movement.type === 'in' ? 'Stock in' : 'Stock out'}
                    </span>
                  </td>
                  <td>{movement.quantity}</td>
                  <td>{movement.reason ?? '-'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </main>
  )
}

export default Dashboard
