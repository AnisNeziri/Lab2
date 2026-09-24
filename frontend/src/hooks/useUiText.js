import { useCallback } from 'react'
import { useSettingsStore } from '../store/settingsStore'
import { uiCopySq } from '../locales/uiCopy'

export function useUiText() {
  const language = useSettingsStore((state) => state.language)
  return useCallback((text) => language === 'sq' ? uiCopySq[text] ?? text : text, [language])
}
