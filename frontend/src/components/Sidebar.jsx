import { 
  LayoutDashboard, 
  Package, 
  TrendingUp, 
  FolderTree,
  Truck,
  FileText, 
  Receipt,
  Activity,
  LogOut
} from 'lucide-react'
import NotificationCenter from './NotificationCenter'

export default function Sidebar({ currentPage, onPageChange, userRole, onLogout }) {
  const menuItems = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { id: 'products', label: 'Products', icon: Package },
    { id: 'stock', label: 'Stock Movements', icon: TrendingUp },
    { id: 'categories', label: 'Categories', icon: FolderTree },
    { id: 'suppliers', label: 'Suppliers', icon: Truck },
    { id: 'reports', label: 'Reports', icon: FileText },
    { id: 'invoices', label: 'Invoices', icon: Receipt },
    ...(userRole === 'admin' ? [{ id: 'activity-logs', label: 'Activity Logs', icon: Activity }] : []),
  ]

  return (
    <aside className="sidebar">
      <div className="sidebar-header">
        <div className="sidebar-logo">
          <Package className="logo-icon" size={28} />
          <h1>Inventory</h1>
        </div>
        <div className="sidebar-header-actions">
          <p className="sidebar-subtitle">Enterprise Management</p>
          <NotificationCenter />
        </div>
      </div>

      <nav className="sidebar-nav">
        {menuItems.map((item) => {
          const Icon = item.icon
          const isActive = currentPage === item.id
          
          return (
            <button
              key={item.id}
              type="button"
              className={`sidebar-item ${isActive ? 'active' : ''}`}
              onClick={() => onPageChange(item.id)}
            >
              <Icon size={20} />
              <span>{item.label}</span>
            </button>
          )
        })}
      </nav>

      <div className="sidebar-footer">
        <button 
          type="button" 
          className="sidebar-item logout"
          onClick={() => onLogout?.()}
        >
          <LogOut size={20} />
          <span>Logout</span>
        </button>
      </div>
    </aside>
  )
}
