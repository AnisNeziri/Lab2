import { useEffect, useState } from 'react'
import { useAuthStore } from '../store/authStore'
import { useSettingsStore } from '../store/settingsStore'
import { useTranslation } from '../hooks/useTranslation'
import { useWorkspaceStore } from '../store/workspaceStore'

export default function PreferencesProvider({ children }) {
  const { t } = useTranslation()
  const [loadedUserId, setLoadedUserId] = useState(null)
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const mustChangePassword = useAuthStore((state) => state.mustChangePassword)
  const user = useAuthStore((state) => state.user)
  const loadFromUser = useSettingsStore((state) => state.loadFromUser)
  const syncFromApi = useSettingsStore((state) => state.syncFromApi)
  const loadWorkspace = useWorkspaceStore(state => state.load)

  useEffect(() => {
    if (!mustChangePassword) loadWorkspace(isAuthenticated ? user : null)
  }, [isAuthenticated, mustChangePassword, user?.id, user?.company_id, loadWorkspace])

  useEffect(() => {
    if (user?.preferences) {
      loadFromUser(user.preferences)
    }
  }, [user?.id, user?.preferences, loadFromUser])

  useEffect(() => {
    let active = true
    if (isAuthenticated && !mustChangePassword) {
      syncFromApi().catch(() => {}).finally(() => {
        if (active) setLoadedUserId(user?.id)
      })
    }
    return () => { active = false }
  }, [isAuthenticated, mustChangePassword, user?.id, syncFromApi])

  // Feature routes must not redirect using stale cached preferences while the
  // initial server settings are still loading. Later refreshes stay invisible.
  if (isAuthenticated && !mustChangePassword && loadedUserId !== user?.id) {
    return <p className="page-message" role="status">{t('common.loading')}</p>
  }

  return children
}
