// This branch is removed from shipped web/desktop builds. Release browser
// tests run Vite's minified production compiler with mode=certification and a
// separate output directory, without fetching development source modules.
import { useSettingsStore } from './store/settingsStore'
import { useAuthStore } from './store/authStore'
import { apiRequest } from './api/client'
import { defaultDashboard } from './config/dashboardWidgets'
import { defaultNavigation } from './config/workspaceNavigation'
window.__aimsCertification = { useSettingsStore, useAuthStore, apiRequest, defaultDashboard, defaultNavigation }
