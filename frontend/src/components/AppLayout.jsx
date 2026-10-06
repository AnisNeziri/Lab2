import { useCallback, useEffect, useRef, useState } from "react";
import { Outlet, useNavigate, useLocation } from "react-router-dom";
import { Menu, X } from "lucide-react";
import Sidebar from "./Sidebar";
import { useAuthStore } from "../store/authStore";
import { logout } from "../api/login";
import { disconnectEcho } from "../lib/echo";
import { canOpenPage } from "../config/pageAccess";
import PageState from "./PageState";
import { useTranslation } from "../hooks/useTranslation";
import WorkspaceContext from './WorkspaceContext';
import { navigationKey } from '../config/navigation';
import AimsAssistant from './AimsAssistant';
import SystemStatusBanner from './SystemStatusBanner';

export default function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [assistantOpen, setAssistantOpen] = useState(false);
  const [assistantGuide, setAssistantGuide] = useState(0);
  const [assistantRequest, setAssistantRequest] = useState(null);
  useEffect(() => {
    const open = event => { setAssistantOpen(true); setAssistantRequest({...event.detail, nonce: Date.now()}); };
    window.addEventListener('aims-assistant:open', open);
    return () => window.removeEventListener('aims-assistant:open', open);
  }, []);
  const closeAssistant = useCallback(() => setAssistantOpen(false), []);
  const logoutPending = useRef(false);
  const navigate = useNavigate();
  const location = useLocation();
  const { role, permissions, clearAuth } = useAuthStore();
  const { t, language } = useTranslation();

  const currentPage = location.pathname.startsWith('/control-tower/') ? 'control-tower' : location.pathname.replace("/", "") || "dashboard";
  const activeNavigation = navigationKey(location.pathname + location.search);

  const handlePageChange = (page, item = null) => {
    if (typeof page === "string" && page.startsWith("/")) {
      navigate(page);
      setSidebarOpen(false);
      return;
    }
    const query = page === "invoices" && item?.id ? `?invoice=${item.id}` : "";
    navigate(`/${page}${query}`);
    setSidebarOpen(false);
  };

  const handleLogout = async () => {
    if (logoutPending.current) return;
    logoutPending.current = true;
    setLoggingOut(true);
    try {
      await logout();
    } catch {
    } finally {
      disconnectEcho();
      clearAuth();
      navigate("/");
    }
  };

  return (
    <div className="app">
      <a className="workspace-skip-link" href="#workspace-main">{language === 'sq' ? 'Kalo te përmbajtja' : 'Skip to content'}</a>
      <button
        type="button"
        className="mobile-menu-toggle"
        aria-label={t("nav.toggle")}
        aria-expanded={sidebarOpen}
        onClick={() => setSidebarOpen((open) => !open)}
      >
        {sidebarOpen ? <X size={22} /> : <Menu size={22} />}
      </button>

      <button
        type="button"
        className={`sidebar-backdrop ${sidebarOpen ? "is-visible" : ""}`}
        aria-label={t("nav.close")}
        onClick={() => setSidebarOpen(false)}
      />

      <Sidebar
        currentPage={activeNavigation}
        onPageChange={handlePageChange}
        userRole={role}
        onLogout={handleLogout}
        loggingOut={loggingOut}
        onHome={() => { setSidebarOpen(false); navigate(role === "superadmin" ? "/superadmin" : "/dashboard"); }}
        isOpen={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
      />

      <div className="main-content" id="workspace-main" tabIndex={-1}>
        <WorkspaceContext onHelp={() => { setAssistantOpen(true); setAssistantGuide(value => value + 1); }}/>
        <SystemStatusBanner/>
        <div key={location.pathname} className="page-transition">
          {canOpenPage(currentPage, permissions) ? <Outlet /> : <PageState forbidden />}
        </div>
      </div>
      <AimsAssistant open={assistantOpen} onClose={closeAssistant} guideRequest={assistantGuide} request={assistantRequest}/>
    </div>
  );
}
