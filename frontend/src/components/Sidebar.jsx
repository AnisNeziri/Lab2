import {
  LayoutDashboard,
  Package,
  TrendingUp,
  FolderTree,
  Truck,
  FileText,
  Receipt,
  Activity,
  Users,
  LogOut,
  FileEdit,
} from 'lucide-react'
import NotificationCenter from './NotificationCenter'
import GlobalSearch from './GlobalSearch'
import AimsLogo from './AimsLogo'

export default function Sidebar({ currentPage, onPageChange, userRole, onLogout, onHome, isOpen = false }) {
  const menuItems = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard },
    { id: 'products', label: 'Products', icon: Package },
    { id: 'stock', label: 'Stock Movements', icon: TrendingUp },
    { id: 'categories', label: 'Categories', icon: FolderTree },
    { id: 'suppliers', label: 'Suppliers', icon: Truck },
    { id: 'reports', label: 'Reports', icon: FileText },
    { id: 'invoices', label: 'Invoices', icon: Receipt },
    ...(userRole === 'admin' ? [
      { id: 'users', label: 'Users', icon: Users },
      { id: 'activity-logs', label: 'Activity Logs', icon: Activity },
      { id: 'cms', label: 'Site Content', icon: FileEdit },
    ] : []),
  ]

  return (
    <aside className={`sidebar ${isOpen ? 'sidebar-open' : ''}`}>
      <div className="sidebar-header">
        <button type="button" className="sidebar-logo sidebar-logo-btn" onClick={onHome}>
          <AimsLogo showText={false} size="sm" />
          <div className="sidebar-logo-text">
            <h1>AIMS</h1>
            <p className="sidebar-subtitle">Enterprise Management</p>
          </div>
        </button>
        <div className="sidebar-header-actions">
          <NotificationCenter onViewStock={() => onPageChange('stock')} />
        </div>
        <GlobalSearch onNavigate={(type) => onPageChange(type)} />
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
