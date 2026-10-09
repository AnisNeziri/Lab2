import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { LayoutDashboard, Package, TrendingUp, FolderTree, Truck, FileText, Activity, Users, LogOut, FileEdit, Box, LayoutGrid, Ship, Globe, Bell, ChevronDown, ClipboardList, ShieldCheck, Landmark, Warehouse, ScanLine, Boxes, Radar, BookOpen, HeartPulse, X } from 'lucide-react'
import { useAuthStore } from '../store/authStore'
import NotificationCenter from './NotificationCenter'
import SettingsModal from './SettingsModal'
import GlobalSearch from './GlobalSearch'
import AimsLogo from './AimsLogo'
import { useTranslation } from '../hooks/useTranslation'
import { useSettingsStore } from '../store/settingsStore'
import { useSidebarNavStore } from '../store/sidebarNavStore'
import { navigationContext } from '../config/navigation'
import { useDialog } from '../hooks/useDialog'
import NavigationCustomizer from './NavigationCustomizer'
import { useWorkspaceStore } from '../store/workspaceStore'
import { defaultNavigation, personalizedNavigation } from '../config/workspaceNavigation'

const icons = { LayoutDashboard, Package, TrendingUp, FolderTree, Truck, FileText, Activity, Users, FileEdit, Box, LayoutGrid, Ship, Globe, Bell, ClipboardList, ShieldCheck, Landmark, Warehouse, ScanLine, Boxes, Radar, BookOpen, HeartPulse }

export default function Sidebar({ currentPage, onPageChange, userRole, onLogout, loggingOut = false, onHome, onClose, isOpen = false }) {
  const { t, language } = useTranslation()
  const enable3dMap = useSettingsStore(state => state.enable_3d_map)
  const permissions = useAuthStore(state => state.permissions)
  const expandedGroups = useSidebarNavStore(state => state.expandedGroups)
  const toggleGroup = useSidebarNavStore(state => state.toggleGroup)
  const ensureGroupOpen = useSidebarNavStore(state => state.ensureGroupOpen)
  const workspace = useWorkspaceStore(state => state.document)
  const workspaceLoading = useWorkspaceStore(state => state.loading)
  const [customizing,setCustomizing] = useState(false)
  const {groups,favorites} = useMemo(() => personalizedNavigation(permissions, enable3dMap, userRole, workspace?.navigation||defaultNavigation(userRole)), [permissions, enable3dMap, userRole, workspace?.navigation])
  const panelRef = useDialog(onClose, false, isOpen)
  const navRef = useRef(null)

  useEffect(() => {
    const group = currentPage === 'superadmin' ? 'settings' : navigationContext(currentPage)?.group.id
    if (group) ensureGroupOpen(group)
  }, [currentPage, ensureGroupOpen])

  useEffect(() => {
    const selected = navRef.current?.querySelector('[aria-current="page"]')
    if (!selected) return
    const bounds = navRef.current.getBoundingClientRect(), row = selected.getBoundingClientRect()
    if (row.top < bounds.top || row.bottom > bounds.bottom) navRef.current.scrollTop += row.top - bounds.top - 48
  }, [currentPage, expandedGroups])

  return <aside ref={panelRef} className={`sidebar ${isOpen ? 'sidebar-open' : ''}`} aria-label={language === 'sq' ? 'Navigimi kryesor' : 'Main navigation'}>
    <div className="sidebar-header">
      <button type="button" className="sidebar-mobile-close" onClick={onClose} aria-label={t('nav.close')}><X size={20}/></button>
      <button type="button" className="sidebar-logo sidebar-logo-btn" onClick={onHome} aria-label={userRole === 'superadmin' ? t('nav.platformAdmin') : t('nav.dashboard')} title={userRole === 'superadmin' ? t('nav.platformAdmin') : t('nav.dashboard')}>
        <AimsLogo showText={false} size="sm"/>
        <p className="sidebar-subtitle">{userRole === 'superadmin' ? t('nav.platformAdmin') : t('nav.enterprise')}</p>
      </button>
      {userRole !== 'superadmin' && <>
        <div className="sidebar-header-actions"><SettingsModal/><NotificationCenter onNavigate={path => onPageChange(path)}/></div>
        <GlobalSearch onNavigate={(type, entry) => onPageChange(type, entry)}/>
      </>}
    </div>
    <nav ref={navRef} className="sidebar-nav">
      {favorites.length>0&&<div className="sidebar-favorites"><p className="sidebar-favorites-title">{language==='sq'?'Të preferuarat':'Favorites'}</p>{favorites.map(entry=>{const Icon=icons[entry.icon]||Package,title=entry.label?t(entry.label):language==='sq'?entry.sq:entry.en;return <Link key={entry.id} to={entry.path} className={'sidebar-item sidebar-subitem '+(entry.id===currentPage?'active':'')} aria-current={entry.id===currentPage?'page':undefined} onClick={e=>{if(!e.ctrlKey&&!e.metaKey&&!e.shiftKey&&e.button===0){e.preventDefault();onPageChange(entry.path)}}}><Icon size={18}/><span>{title}</span></Link>})}</div>}
      {groups.map(group => {
        const expanded = expandedGroups[group.id] ?? false
        const active = group.items.some(entry => entry.id === currentPage)
        const label = language === 'sq' ? group.sq : group.en
        return <div key={group.id} className={`sidebar-group ${active ? 'has-active' : ''}`}>
          <button type="button" className={`sidebar-group-toggle ${expanded ? 'expanded' : ''}`} onClick={() => toggleGroup(group.id)} aria-expanded={expanded} aria-controls={`nav-group-${group.id}`}><span>{label}</span><ChevronDown size={16} className="sidebar-group-chevron"/></button>
          {expanded && <div id={`nav-group-${group.id}`} className="sidebar-group-items" aria-label={label}>
            {group.items.map(entry => {
              const Icon = icons[entry.icon] || Package
              const selected = entry.id === currentPage
              const title = entry.label ? t(entry.label) : language === 'sq' ? entry.sq : entry.en
              return <Link key={entry.id} to={entry.path} title={title} className={`sidebar-item sidebar-subitem ${selected ? 'active' : ''}`} aria-current={selected ? 'page' : undefined} onClick={event => { if (!event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) { event.preventDefault(); onPageChange(entry.path) } }}><Icon size={18} aria-hidden="true"/><span>{title}</span></Link>
            })}
          </div>}
        </div>
      })}
    </nav>
    <div className="sidebar-footer">{userRole!=='superadmin'&&<button type="button" className="sidebar-item sidebar-customize" disabled={workspaceLoading} onClick={()=>setCustomizing(true)}><LayoutGrid size={18}/><span>{language==='sq'?'Personalizo navigimin':'Customize Navigation'}</span></button>}<button type="button" className="sidebar-item logout" disabled={loggingOut} onClick={() => onLogout?.()}><LogOut size={20}/><span>{loggingOut ? (language === 'sq' ? 'Duke dalë…' : 'Signing out…') : t('nav.logout')}</span></button></div>
    {customizing&&<NavigationCustomizer onClose={()=>setCustomizing(false)}/>}
  </aside>
}
