import { ProductsGlyph as Package, DashboardGlyph as LayoutGrid } from './icons/AimsGlyph'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { LogOut, ChevronDown, X } from 'lucide-react'
import { useAuthStore } from '../store/authStore'
import NotificationCenter from './NotificationCenter'
import SettingsModal from './SettingsModal'
import GlobalSearch from './GlobalSearch'
import AimsLogo from './AimsLogo'
import { useTranslation } from '../hooks/useTranslation'
import { useSettingsStore } from '../store/settingsStore'
import { useSidebarNavStore } from '../store/sidebarNavStore'
import { useDialog } from '../hooks/useDialog'
import NavigationCustomizer from './NavigationCustomizer'
import { useWorkspaceStore } from '../store/workspaceStore'
import { defaultNavigation, groupSidebarNavigation, personalizedNavigation } from '../config/workspaceNavigation'
import { workspaceIcons } from './workspaceIcons'
import { focusedNavigation } from '../config/everydayWorkspace'
import { workProfiles } from '../config/workProfiles'
import { useWorkFocus } from '../hooks/useWorkFocus'
import '../styles/EverydayWorkspace.css'

const icons = workspaceIcons

export default function Sidebar({ currentPage, onPageChange, userRole, onLogout, loggingOut = false, onHome, onClose, isOpen = false }) {
  const { t, language } = useTranslation()
  const enable3dMap = useSettingsStore(state => state.enable_3d_map)
  const permissions = useAuthStore(state => state.permissions)
  const capabilities=useAuthStore(state=>state.user?.commercial?.capabilities)
  const expandedGroups = useSidebarNavStore(state => state.expandedGroups)
  const toggleGroup = useSidebarNavStore(state => state.toggleGroup)
  const ensureGroupsOpen = useSidebarNavStore(state => state.ensureGroupsOpen)
  const workspace = useWorkspaceStore(state => state.document)
  const workspaceLoading = useWorkspaceStore(state => state.loading)
  const [customizing,setCustomizing] = useState(false)
  const {profile,available,setProfile}=useWorkFocus()
  const navigation = useMemo(() => personalizedNavigation(permissions, enable3dMap, userRole, workspace?.navigation||defaultNavigation(userRole),capabilities), [permissions, enable3dMap, userRole, workspace?.navigation,capabilities])
  const {groups,favorites}=userRole==='superadmin'?navigation:focusedNavigation(navigation.groups,navigation.favorites,profile)
  const sections = groupSidebarNavigation(groups, userRole)
  const hasCurrentPage = group => group.items.some(entry => entry.id === currentPage)
  const activeSection = sections.find(section => hasCurrentPage(section) || section.subgroups.some(hasCurrentPage))
  const activeSectionId = activeSection?.id
  const activeSubgroupId = activeSection?.subgroups.find(hasCurrentPage)?.id
  const panelRef = useDialog(onClose, false, isOpen)
  const navRef = useRef(null)

  useEffect(() => {
    if (activeSectionId) ensureGroupsOpen(...[activeSectionId, activeSubgroupId].filter(Boolean))
  }, [activeSectionId, activeSubgroupId, currentPage, ensureGroupsOpen])

  useEffect(() => {
    const selected = navRef.current?.querySelector('[aria-current="page"]')
    if (!selected) return
    const bounds = navRef.current.getBoundingClientRect(), row = selected.getBoundingClientRect()
    if (row.top < bounds.top || row.bottom > bounds.bottom) navRef.current.scrollTop += row.top - bounds.top - 48
  }, [currentPage, expandedGroups, isOpen])

  function renderEntry(entry) {
    const Icon = icons[entry.icon] || Package
    const selected = entry.id === currentPage
    const title = entry.label ? t(entry.label) : entry[language === 'sq' ? 'sq' : 'en']
    return <Link key={entry.id} to={entry.path} title={title} className={`sidebar-item sidebar-subitem ${selected ? 'active' : ''}`} aria-current={selected ? 'page' : undefined} onClick={event => { if (!event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) { event.preventDefault(); onPageChange(entry.path) } }}><Icon size={18} aria-hidden="true"/><span>{title}</span></Link>
  }

  function renderGroup(group, nested = false) {
    const expanded = expandedGroups[group.id] ?? false
    const active = group.id === activeSectionId || group.id === activeSubgroupId
    const label = language === 'sq' ? group.sq : group.en
    const contentId = `nav-group-${group.id}`
    return <div key={group.id} className={`sidebar-group ${nested ? 'sidebar-nested-group' : ''} ${active ? 'has-active' : ''}`}>
      <button type="button" className={`sidebar-group-toggle ${expanded ? 'expanded' : ''}`} onClick={() => toggleGroup(group.id)} aria-expanded={expanded} aria-controls={contentId}>
        <span>{label}</span><ChevronDown size={nested ? 14 : 16} className="sidebar-group-chevron" aria-hidden="true"/>
      </button>
      <div id={contentId} className={expanded ? 'sidebar-group-items' : undefined} hidden={!expanded}>
        {expanded && <>
          {group.items.map(renderEntry)}
          {group.subgroups?.map(subgroup => renderGroup(subgroup, true))}
        </>}
      </div>
    </div>
  }

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
      {userRole !== 'superadmin' && profile && <label className="sidebar-work-focus">
        {language === 'sq' ? 'Hapësira ime' : 'My workspace'}
        <span className="sidebar-focus-control">
          <select value={profile} onChange={event => setProfile(event.target.value)}>
            {available.map(id => <option key={id} value={id}>{workProfiles[id]?.[language === 'sq' ? 'sq' : 'en'] || id}</option>)}
          </select>
          <ChevronDown size={16} aria-hidden="true"/>
        </span>
      </label>}
      {favorites.length > 0 && <div className="sidebar-favorites">
        <p className="sidebar-favorites-title">{language === 'sq' ? 'Të preferuarat' : 'Favorites'}</p>
        {favorites.map(renderEntry)}
      </div>}
      {sections.map(section => renderGroup(section))}
    </nav>
    <div className="sidebar-footer">{userRole!=='superadmin'&&<button type="button" className="sidebar-item sidebar-customize" disabled={workspaceLoading} onClick={()=>setCustomizing(true)}><LayoutGrid size={18}/><span>{language==='sq'?'Personalizo navigimin':'Customize Navigation'}</span></button>}<button type="button" className="sidebar-item logout" disabled={loggingOut} onClick={() => onLogout?.()}><LogOut size={20}/><span>{loggingOut ? (language === 'sq' ? 'Duke dalë…' : 'Signing out…') : t('nav.logout')}</span></button></div>
    {customizing&&<NavigationCustomizer onClose={()=>setCustomizing(false)}/>}
  </aside>
}
