import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { getPreferences, updatePreferences } from '../api/settings'

const DEFAULTS = {
  theme: 'light',
  language: 'en',
  enable_3d_map: false,
}

function applyTheme(theme) {
  document.documentElement.dataset.theme = theme
  document.documentElement.classList.toggle('dark', theme === 'dark')
}

export const useSettingsStore = create(
  persist(
    (set, get) => ({
      ...DEFAULTS,
      hydrated: false,

      applyPreferences(preferences) {
        const next = { ...DEFAULTS, ...preferences }
        applyTheme(next.theme)
        set({
          theme: next.theme,
          language: next.language,
          enable_3d_map: Boolean(next.enable_3d_map),
        })
      },

      loadFromUser(preferences) {
        if (!preferences) return
        get().applyPreferences(preferences)
      },

      async syncFromApi() {
        const data = await getPreferences()
        get().applyPreferences(data.preferences)
        return data.preferences
      },

      async savePreferences(partial) {
        const data = await updatePreferences(partial)
        get().applyPreferences(data.preferences)
        return data.preferences
      },
    }),
    {
      name: 'aims-settings',
      partialize: (state) => ({
        theme: state.theme,
        language: state.language,
        enable_3d_map: state.enable_3d_map,
      }),
      onRehydrateStorage: () => (state) => {
        if (state) {
          applyTheme(state.theme)
          state.hydrated = true
        }
      },
    }
  )
)

applyTheme(useSettingsStore.getState().theme)
