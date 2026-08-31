import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router-dom'
import { Download, KeyRound, RefreshCw, Settings as SettingsIcon, ShieldCheck, X } from 'lucide-react'
import { useAuthStore } from '../store/authStore'
import { useSettingsStore } from '../store/settingsStore'
import { useTranslation } from '../hooks/useTranslation'

export default function SettingsModal() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const user = useAuthStore((state) => state.user)
  const updateUser = useAuthStore((state) => state.updateUser)
  const { theme, language, enable_3d_map, base_currency, savePreferences } = useSettingsStore()
  const [isOpen, setIsOpen] = useState(false)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [desktopLicence, setDesktopLicence] = useState(null)
  const [desktopUpdate, setDesktopUpdate] = useState(null)
  const [desktopBusy, setDesktopBusy] = useState('')
  const panelRef = useRef(null)

  useEffect(() => {
    if (!isOpen) return undefined

    function handlePointerDown(event) {
      if (panelRef.current && !panelRef.current.contains(event.target)) {
        setIsOpen(false)
      }
    }

    function handleEscape(event) {
      if (event.key === 'Escape') {
        setIsOpen(false)
      }
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isOpen])

  useEffect(() => {
    if (!isOpen || !window.aimsDesktop) return undefined

    let active = true
    Promise.all([
      window.aimsDesktop.getLicenceStatus(),
      window.aimsDesktop.getUpdateStatus(),
    ]).then(([licence, update]) => {
      if (!active) return
      setDesktopLicence(licence)
      setDesktopUpdate(update)
    }).catch(() => {})

    const unsubscribe = window.aimsDesktop.onUpdateStatus((status) => {
      if (active) setDesktopUpdate(status)
    })
    return () => {
      active = false
      unsubscribe?.()
    }
  }, [isOpen])

  async function persistPreferences(next) {
    setSaving(true)
    setMessage('')
    setError('')

    try {
      const preferences = await savePreferences(next)
      if (user) {
        updateUser({ ...user, preferences })
      }
      setMessage(t('settings.saved'))

      if (!next.enable_3d_map && ['/warehouse-3d', '/warehouse-layout'].includes(window.location.pathname)) {
        navigate('/dashboard', { replace: true })
      }
    } catch {
      setError(t('settings.saveError'))
    } finally {
      setSaving(false)
    }
  }

  function handleThemeChange(event) {
    persistPreferences({ theme: event.target.value, language, enable_3d_map })
  }

  function handleLanguageChange(event) {
    persistPreferences({ theme, language: event.target.value, enable_3d_map })
  }

  function handleWarehouseToggle(event) {
    persistPreferences({ theme, language, enable_3d_map: event.target.checked })
  }

  function handleBaseCurrencyChange(event) {
    persistPreferences({ theme, language, enable_3d_map, base_currency: event.target.value })
  }

  async function handleLicenceActivation() {
    if (!window.aimsDesktop || desktopBusy) return
    setDesktopBusy('licence')
    setError('')
    try {
      const status = await window.aimsDesktop.activateLicence()
      setDesktopLicence(status)
      if (status?.valid) setMessage(t('settings.licenceActivated'))
    } catch {
      setError(t('settings.licenceError'))
    } finally {
      setDesktopBusy('')
    }
  }

  async function handleUpdateCheck() {
    if (!window.aimsDesktop || desktopBusy) return
    setDesktopBusy('update')
    setError('')
    try {
      const status = await window.aimsDesktop.checkForUpdates()
      setDesktopUpdate(status)
    } catch {
      setError(t('settings.updateError'))
    } finally {
      setDesktopBusy('')
    }
  }

  const modal = isOpen
    ? createPortal(
        <>
          <div className="settings-modal-backdrop" onClick={() => setIsOpen(false)} />
          <div className="settings-modal" ref={panelRef} role="dialog" aria-modal="true" aria-label={t('settings.title')}>
            <div className="settings-modal-header">
              <h3>{t('settings.title')}</h3>
              <button type="button" className="close-btn" onClick={() => setIsOpen(false)} aria-label="Close settings">
                <X size={16} />
              </button>
            </div>

            <div className="settings-modal-body">
              <label className="settings-modal-field">
                <span>{t('settings.theme')}</span>
                <select value={theme} onChange={handleThemeChange} disabled={saving}>
                  <option value="light">{t('settings.themeLight')}</option>
                  <option value="dark">{t('settings.themeDark')}</option>
                </select>
              </label>

              <label className="settings-modal-field">
                <span>{t('settings.language')}</span>
                <select value={language} onChange={handleLanguageChange} disabled={saving}>
                  <option value="en">{t('settings.languageEn')}</option>
                  <option value="sq">{t('settings.languageSq')}</option>
                </select>
              </label>

              {user?.role === 'admin' ? (
                <label className="settings-modal-field">
                  <span>{t('settings.baseCurrency')}</span>
                  <select value={base_currency} onChange={handleBaseCurrencyChange} disabled={saving}>
                    {['EUR', 'USD', 'ALL', 'GBP', 'CHF', 'CNY'].map((currency) => <option key={currency}>{currency}</option>)}
                  </select>
                  <small>{t('settings.baseCurrencyHint')}</small>
                </label>
              ) : null}

              <div className="settings-modal-row">
                <div>
                  <strong>{t('settings.enable3d')}</strong>
                  <p>{t('settings.enable3dHint')}</p>
                </div>
                <label className="toggle-switch" aria-label={t('settings.enable3d')}>
                  <input
                    type="checkbox"
                    checked={enable_3d_map}
                    onChange={handleWarehouseToggle}
                    disabled={saving}
                  />
                  <span className="toggle-slider" />
                </label>
              </div>

              {window.aimsDesktop ? (
                <section className="settings-desktop-panel" aria-label={t('settings.desktopSecurity')}>
                  <div className="settings-desktop-heading">
                    <span><ShieldCheck size={17} /></span>
                    <div>
                      <strong>{t('settings.desktopSecurity')}</strong>
                      <p>{t('settings.desktopSecurityHint')}</p>
                    </div>
                  </div>

                  <div className="settings-desktop-status">
                    <span className={desktopLicence?.valid ? 'is-valid' : 'is-invalid'}>
                      <i />
                      {desktopLicence?.valid ? t('settings.licenceActive') : t('settings.licenceInactive')}
                    </span>
                    <small>{desktopLicence?.customer || t('settings.licenceLoading')}</small>
                  </div>

                  {desktopLicence?.machineId ? (
                    <div className="settings-machine-id">
                      <span>{t('settings.computerId')}</span>
                      <code>{desktopLicence.machineId}</code>
                    </div>
                  ) : null}

                  <div className="settings-update-status">
                    <Download size={15} />
                    <span>
                      <strong>{t('settings.automaticUpdates')}</strong>
                      <small>{desktopUpdate?.message || t('settings.updateIdle')}</small>
                    </span>
                    {desktopUpdate?.state === 'downloading' ? <em>{Number(desktopUpdate.percent || 0)}%</em> : null}
                  </div>

                  <div className="settings-desktop-actions">
                    <button type="button" onClick={handleLicenceActivation} disabled={Boolean(desktopBusy)}>
                      <KeyRound size={14} />
                      {t('settings.changeLicence')}
                    </button>
                    <button type="button" onClick={handleUpdateCheck} disabled={Boolean(desktopBusy)}>
                      <RefreshCw size={14} className={desktopBusy === 'update' ? 'is-spinning' : ''} />
                      {t('settings.checkUpdates')}
                    </button>
                  </div>
                </section>
              ) : null}

              {message ? <p className="settings-modal-success">{message}</p> : null}
              {error ? <p className="settings-modal-error">{error}</p> : null}
            </div>
          </div>
        </>,
        document.body
      )
    : null

  return (
    <>
      <button
        type="button"
        className="sidebar-icon-btn"
        aria-expanded={isOpen}
        aria-label={t('settings.title')}
        onClick={() => setIsOpen((open) => !open)}
      >
        <SettingsIcon size={20} />
      </button>
      {modal}
    </>
  )
}
