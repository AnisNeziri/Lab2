import { useEffect } from 'react'
import { useAuthStore } from '../store/authStore'
import { useSettingsStore } from '../store/settingsStore'

export default function PreferencesProvider({ children }) {
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated)
  const mustChangePassword = useAuthStore((state) => state.mustChangePassword)
  const user = useAuthStore((state) => state.user)
  const loadFromUser = useSettingsStore((state) => state.loadFromUser)
  const syncFromApi = useSettingsStore((state) => state.syncFromApi)

  useEffect(() => {
    if (user?.preferences) {
      loadFromUser(user.preferences)
    }
  }, [user?.id, user?.preferences, loadFromUser])

  useEffect(() => {
    if (isAuthenticated && !mustChangePassword) {
      syncFromApi().catch(() => {})
    }
  }, [isAuthenticated, mustChangePassword, syncFromApi])

  return children
}
