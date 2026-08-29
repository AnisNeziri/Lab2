import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertTriangle,
  Check,
  CheckCircle2,
  DatabaseBackup,
  Download,
  FileArchive,
  FileJson,
  Eye,
  EyeOff,
  LoaderCircle,
  LockKeyhole,
  RefreshCw,
  ShieldCheck,
  Upload,
} from 'lucide-react'
import {
  BACKUP_MAX_BYTES,
  BACKUP_MIN_PASSPHRASE_LENGTH,
  downloadBackup,
  getBackupCapabilities,
  inspectBackupFile,
  restoreBackup,
} from '../api/backup'
import { downloadExport } from '../api/export'
import { importList } from '../api/import'
import { getReports } from '../api/reports'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'

const BACKUP_MODULES = [
  'company', 'categories', 'suppliers', 'products', 'inventory',
  'warehouses', 'purchases', 'daily_sales', 'customer_debts', 'finance',
  'shipments',
]

const DATA_LISTS = [
  { id: 'products', importable: true },
  { id: 'categories', importable: true },
  { id: 'suppliers', importable: true },
  { id: 'stock_movements', importable: false },
  { id: 'invoices', importable: false },
]

const FORMATS = ['csv', 'json', 'xlsx']
const IMPORT_EXTENSIONS = ['csv', 'json', 'xlsx', 'txt']

function formatBytes(bytes) {
  if (!Number.isFinite(bytes) || bytes < 1) return '0 KB'
  const units = ['B', 'KB', 'MB', 'GB']
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / (1024 ** index)).toFixed(index > 1 ? 1 : 0)} ${units[index]}`
}

function resultRows(result) {
  const value = result?.imported || result?.summary || result?.modules
  if (!value || typeof value !== 'object' || Array.isArray(value)) return []
  return Object.entries(value).map(([module, details]) => ({
    module,
    value: typeof details === 'object'
      ? details?.restored ?? details?.imported ?? details?.records ?? details?.count ?? details?.status ?? '✓'
      : details,
  }))
}

function Reports() {
  const { t, language } = useTranslation()
  const role = useAuthStore((state) => state.role)
  const user = useAuthStore((state) => state.user)
  const permissions = useAuthStore((state) => state.permissions)
  const canExport = permissions.includes('export.execute')
  const canImport = permissions.includes('import.execute')
  const canManagePortableBackup = role === 'admin' || (role === 'superadmin' && Boolean(user?.company_id))
  const fileInputRef = useRef(null)
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [reportError, setReportError] = useState('')
  const [actionError, setActionError] = useState('')
  const [message, setMessage] = useState('')
  const [busyKey, setBusyKey] = useState('')
  const [backupBusy, setBackupBusy] = useState('')
  const [backupProgress, setBackupProgress] = useState(null)
  const [selectedModules, setSelectedModules] = useState(BACKUP_MODULES)
  const [backupFile, setBackupFile] = useState(null)
  const [backupMeta, setBackupMeta] = useState(null)
  const [restoreModules, setRestoreModules] = useState([])
  const [restoreMode, setRestoreMode] = useState('merge')
  const [replaceAcknowledged, setReplaceAcknowledged] = useState(false)
  const [restoreResult, setRestoreResult] = useState(null)
  const [isDragging, setIsDragging] = useState(false)
  const [backupPassphrase, setBackupPassphrase] = useState('')
  const [backupPassphraseConfirm, setBackupPassphraseConfirm] = useState('')
  const [showBackupPassphrase, setShowBackupPassphrase] = useState(false)
  const [restorePassphrase, setRestorePassphrase] = useState('')
  const [showRestorePassphrase, setShowRestorePassphrase] = useState(false)
  const [backupMaxBytes, setBackupMaxBytes] = useState(BACKUP_MAX_BYTES)

  const moduleLabel = useCallback((module) => {
    const key = `backup.module.${module}`
    const translated = t(key)
    return translated === key
      ? module.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
      : translated
  }, [t])

  const loadReports = useCallback(async () => {
    try {
      setLoading(true)
      setReportError('')
      setData(await getReports())
    } catch {
      setReportError(t('reports.loadError'))
    } finally {
      setLoading(false)
    }
  }, [t])

  useEffect(() => { loadReports() }, [loadReports])

  useEffect(() => {
    if (!canManagePortableBackup) return undefined
    let active = true
    getBackupCapabilities()
      .then((capabilities) => {
        if (active && Number(capabilities?.max_bytes) > 0) {
          setBackupMaxBytes(Number(capabilities.max_bytes))
        }
      })
      .catch(() => {})
    return () => { active = false }
  }, [canManagePortableBackup])

  const dateTimeFormatter = useMemo(() => new Intl.DateTimeFormat(
    language === 'sq' ? 'sq-AL' : 'en-GB',
    { dateStyle: 'medium', timeStyle: 'short' },
  ), [language])

  const currencyFormatter = useMemo(() => new Intl.NumberFormat(
    language === 'sq' ? 'sq-AL' : 'en-IE',
    { style: 'currency', currency: 'EUR' },
  ), [language])

  const clearNotices = () => {
    setActionError('')
    setMessage('')
    setRestoreResult(null)
  }

  const handleExport = async (list, format) => {
    const key = `export-${list}-${format}`
    try {
      setBusyKey(key)
      clearNotices()
      await downloadExport(list, format)
      setMessage(t('reports.exportReady', { list: t(`reports.list.${list}`) }))
    } catch (error) {
      setActionError(error.message || t('reports.exportError'))
    } finally {
      setBusyKey('')
    }
  }

  const handleImport = async (list, event) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    const extension = file.name.split('.').pop()?.toLowerCase()
    if (!IMPORT_EXTENSIONS.includes(extension) || file.size < 1) {
      setActionError(t('reports.invalidImportFile'))
      return
    }
    if (file.size > 25 * 1024 * 1024) {
      setActionError(t('reports.importTooLarge'))
      return
    }

    const key = `import-${list}`
    try {
      setBusyKey(key)
      clearNotices()
      const result = await importList(list, file)
      setMessage(t('reports.importComplete', {
        imported: result.records_imported || 0,
        total: result.records_total || 0,
        list: t(`reports.list.${list}`),
      }))
      await loadReports()
    } catch (error) {
      setActionError(error.message || t('reports.importError'))
    } finally {
      setBusyKey('')
    }
  }

  const handleBackupDownload = async (modules, kind) => {
    if (kind === 'selected' && modules.length === 0) {
      setActionError(t('backup.chooseModule'))
      return
    }
    if (backupPassphrase.length < BACKUP_MIN_PASSPHRASE_LENGTH) {
      setActionError(t('backup.passphraseTooShort'))
      return
    }
    if (backupPassphrase !== backupPassphraseConfirm) {
      setActionError(t('backup.passphraseMismatch'))
      return
    }
    try {
      setBackupBusy(kind)
      clearNotices()
      setBackupProgress({ phase: 'preparing', percent: 8 })
      const result = await downloadBackup(modules, backupPassphrase, setBackupProgress)
      setMessage(t('backup.downloadComplete', {
        filename: result.filename,
        size: formatBytes(result.size),
      }))
      setBackupPassphrase('')
      setBackupPassphraseConfirm('')
    } catch (error) {
      setBackupProgress(null)
      setActionError(error.message || t('backup.downloadError'))
    } finally {
      setBackupBusy('')
    }
  }

  const selectBackupFile = async (file) => {
    clearNotices()
    setBackupFile(null)
    setBackupMeta(null)
    setRestoreModules([])
    setRestorePassphrase('')
    setReplaceAcknowledged(false)
    if (!file) return

    if (file.size > backupMaxBytes) {
      setActionError(t('backup.fileTooLarge', { size: formatBytes(backupMaxBytes) }))
      if (fileInputRef.current) fileInputRef.current.value = ''
      return
    }

    try {
      setBackupBusy('validate')
      setBackupProgress({ phase: 'validating', percent: 12 })
      const meta = await inspectBackupFile(file, backupMaxBytes)
      const manifestHidden = meta.encrypted && meta.modules.length === 0
      const containedModules = meta.modules.includes('full')
        ? BACKUP_MODULES
        : meta.modules.filter((module) => BACKUP_MODULES.includes(module))
      setBackupFile(file)
      setBackupMeta({ ...meta, modules: containedModules, moduleManifestHidden: manifestHidden })
      setRestoreModules(containedModules)
      setBackupProgress(null)
    } catch (error) {
      setBackupProgress(null)
      setActionError(error.message || t('backup.invalidFile'))
      if (fileInputRef.current) fileInputRef.current.value = ''
    } finally {
      setBackupBusy('')
    }
  }

  const handleRestore = async () => {
    if (!backupFile || !backupMeta) return setActionError(t('backup.chooseFile'))
    if (backupMeta.modules.length > 0 && restoreModules.length === 0) return setActionError(t('backup.chooseRestoreModule'))
    if (restoreMode === 'replace' && !replaceAcknowledged) return setActionError(t('backup.confirmReplaceRequired'))
    if (backupMeta.encrypted && restorePassphrase.length < BACKUP_MIN_PASSPHRASE_LENGTH) return setActionError(t('backup.restorePassphraseRequired'))

    try {
      setBackupBusy('restore')
      clearNotices()
      setBackupProgress({ phase: 'uploading', percent: 25 })
      const result = await restoreBackup(backupFile, {
        mode: restoreMode,
        modules: restoreModules,
        passphrase: restorePassphrase,
        confirmReplace: restoreMode === 'replace' && replaceAcknowledged,
      }, setBackupProgress)
      setRestoreResult(result)
      setMessage(result?.message || t('backup.restoreComplete'))
      setRestorePassphrase('')
      window.dispatchEvent(new CustomEvent('database-refresh', { detail: { source: 'backup-restore', silent: true } }))
      await loadReports()
    } catch (error) {
      setBackupProgress(null)
      setActionError(error.message || t('backup.restoreError'))
    } finally {
      setBackupBusy('')
    }
  }

  const toggleSelectedModule = (module) => setSelectedModules((current) => current.includes(module)
    ? current.filter((item) => item !== module)
    : [...current, module])

  const toggleRestoreModule = (module) => setRestoreModules((current) => current.includes(module)
    ? current.filter((item) => item !== module)
    : [...current, module])

  return (
    <main className="reports-page page-stack">
      <section className="card reports-heading-card">
        <div>
          <span className="eyebrow">{t('reports.workspace')}</span>
          <h2>{t('reports.title')}</h2>
          <p className="page-intro">{t('reports.intro')}</p>
        </div>
        <span className="backup-security-badge"><ShieldCheck size={16} />{t('backup.companyScoped')}</span>
      </section>

      {actionError ? <div className="form-error-banner" role="alert">{actionError}</div> : null}
      {message ? <div className="success-banner backup-notice" role="status"><CheckCircle2 size={17} />{message}</div> : null}

      {canManagePortableBackup ? <section className="card backup-workspace" aria-labelledby="backup-title">
        <div className="backup-workspace-heading">
          <span className="backup-workspace-icon"><DatabaseBackup size={26} /></span>
          <div><h3 id="backup-title">{t('backup.title')}</h3><p>{t('backup.intro')}</p></div>
          {canExport ? (
            <button type="button" className="primary backup-full-button" disabled={Boolean(backupBusy)} onClick={() => handleBackupDownload([], 'full')}>
              {backupBusy === 'full' ? <LoaderCircle className="is-spinning" size={17} /> : <FileArchive size={17} />}
              {t('backup.downloadFull')}
            </button>
          ) : null}
        </div>

        <div className="backup-callout">
          <ShieldCheck size={19} />
          <div><strong>{t('backup.disasterRecovery')}</strong><span>{t('backup.disasterRecoveryHint')}</span></div>
        </div>
        <div className="backup-confidential-warning">
          <AlertTriangle size={18} />
          <span><strong>{t('backup.confidentialTitle')}</strong>{t('backup.confidentialHint')}</span>
        </div>
        <div className="backup-exclusions-note">
          <ShieldCheck size={17} />
          <span>{t('backup.securityExclusions')}</span>
        </div>

        <div className="backup-passphrase-panel">
          <div className="backup-passphrase-heading">
            <span><LockKeyhole size={18} /></span>
            <div><strong>{t('backup.encryptionTitle')}</strong><small>{t('backup.encryptionHint')}</small></div>
          </div>
          <div className="backup-passphrase-fields">
            <label>
              <span>{t('backup.passphrase')}</span>
              <div className="backup-password-input">
                <input type={showBackupPassphrase ? 'text' : 'password'} value={backupPassphrase} minLength={BACKUP_MIN_PASSPHRASE_LENGTH} autoComplete="new-password" onChange={(event) => setBackupPassphrase(event.target.value)} placeholder={t('backup.passphrasePlaceholder')} />
                <button type="button" onClick={() => setShowBackupPassphrase((shown) => !shown)} aria-label={showBackupPassphrase ? t('backup.hidePassphrase') : t('backup.showPassphrase')}>{showBackupPassphrase ? <EyeOff size={16} /> : <Eye size={16} />}</button>
              </div>
            </label>
            <label>
              <span>{t('backup.confirmPassphrase')}</span>
              <input type={showBackupPassphrase ? 'text' : 'password'} value={backupPassphraseConfirm} minLength={BACKUP_MIN_PASSPHRASE_LENGTH} autoComplete="new-password" onChange={(event) => setBackupPassphraseConfirm(event.target.value)} placeholder={t('backup.confirmPassphrasePlaceholder')} />
            </label>
          </div>
          <small className="backup-passphrase-reminder">{t('backup.passphraseReminder')}</small>
        </div>

        <div className="backup-section-heading">
          <div><h4>{t('backup.selectiveTitle')}</h4><p>{t('backup.selectiveHint')}</p></div>
          <div className="backup-text-actions">
            <button type="button" onClick={() => setSelectedModules(BACKUP_MODULES)}>{t('backup.selectAll')}</button>
            <button type="button" onClick={() => setSelectedModules([])}>{t('backup.clearSelection')}</button>
          </div>
        </div>

        <div className="backup-module-grid">
          {BACKUP_MODULES.map((module) => {
            const checked = selectedModules.includes(module)
            return (
              <label key={module} className={`backup-module-option ${checked ? 'is-selected' : ''}`}>
                <input type="checkbox" checked={checked} onChange={() => toggleSelectedModule(module)} />
                <span className="backup-module-check">{checked ? <Check size={13} /> : null}</span>
                <span>{moduleLabel(module)}</span>
              </label>
            )
          })}
        </div>

        <div className="backup-selection-footer">
          <span>{t('backup.selectedCount', { count: selectedModules.length, total: BACKUP_MODULES.length })}</span>
          {canExport ? (
            <button type="button" className="secondary" disabled={Boolean(backupBusy) || selectedModules.length === 0} onClick={() => handleBackupDownload(selectedModules, 'selected')}>
              {backupBusy === 'selected' ? <LoaderCircle className="is-spinning" size={16} /> : <Download size={16} />}
              {t('backup.downloadSelected')}
            </button>
          ) : <span className="backup-permission-note">{t('backup.exportPermission')}</span>}
        </div>

        {backupProgress ? (
          <div className="backup-progress" role="status" aria-live="polite">
            <div><span>{t(`backup.progress.${backupProgress.phase}`)}</span><strong>{backupProgress.percent === null ? '…' : `${backupProgress.percent}%`}</strong></div>
            <span className={backupProgress.percent === null ? 'is-indeterminate' : ''}>
              <i style={backupProgress.percent === null ? undefined : { width: `${backupProgress.percent}%` }} />
            </span>
          </div>
        ) : null}

        <div className="backup-divider" />
        <div className="backup-section-heading"><div><h4>{t('backup.restoreTitle')}</h4><p>{t('backup.restoreHint')}</p></div></div>

        <div
          className={`backup-dropzone ${isDragging ? 'is-dragging' : ''} ${backupMeta ? 'has-file' : ''}`}
          onDragEnter={(event) => { event.preventDefault(); setIsDragging(true) }}
          onDragOver={(event) => event.preventDefault()}
          onDragLeave={() => setIsDragging(false)}
          onDrop={(event) => { event.preventDefault(); setIsDragging(false); selectBackupFile(event.dataTransfer.files?.[0]) }}
        >
          {backupMeta ? <FileJson size={28} /> : <Upload size={28} />}
          <div>
            <strong>{backupFile?.name || t('backup.dropFile')}</strong>
            <span>{backupFile ? `${formatBytes(backupFile.size)} · ${t('backup.fileValidated')}` : t('backup.fileTypes', { size: formatBytes(backupMaxBytes) })}</span>
          </div>
          <button type="button" className="secondary" onClick={() => fileInputRef.current?.click()} disabled={Boolean(backupBusy)}>
            {backupBusy === 'validate' ? <LoaderCircle className="is-spinning" size={15} /> : null}
            {backupFile ? t('backup.changeFile') : t('backup.chooseFileButton')}
          </button>
          <input ref={fileInputRef} type="file" accept=".aimsbackup,.json,application/json" hidden onChange={(event) => selectBackupFile(event.target.files?.[0])} />
        </div>

        {backupMeta ? (
          <div className="backup-file-details">
            <div><span>{t('backup.version')}</span><strong>{backupMeta.version}</strong></div>
            <div><span>{t('backup.created')}</span><strong>{backupMeta.createdAt ? dateTimeFormatter.format(new Date(backupMeta.createdAt)) : '—'}</strong></div>
            <div><span>{t('backup.company')}</span><strong>{backupMeta.company || t('backup.currentCompany')}</strong></div>
            <div><span>{t('backup.modules')}</span><strong>{backupMeta.moduleManifestHidden ? t('backup.protectedManifest') : backupMeta.modules.length || t('backup.allModules')}</strong></div>
          </div>
        ) : null}

        {backupMeta?.encrypted ? (
          <div className="backup-restore-passphrase">
            <label>
              <span><LockKeyhole size={15} />{t('backup.restorePassphrase')}</span>
              <div className="backup-password-input">
                <input type={showRestorePassphrase ? 'text' : 'password'} value={restorePassphrase} minLength={BACKUP_MIN_PASSPHRASE_LENGTH} autoComplete="off" onChange={(event) => setRestorePassphrase(event.target.value)} placeholder={t('backup.restorePassphrasePlaceholder')} />
                <button type="button" onClick={() => setShowRestorePassphrase((shown) => !shown)} aria-label={showRestorePassphrase ? t('backup.hidePassphrase') : t('backup.showPassphrase')}>{showRestorePassphrase ? <EyeOff size={16} /> : <Eye size={16} />}</button>
              </div>
            </label>
            <small>{t('backup.restorePassphraseHint')}</small>
          </div>
        ) : null}

        {backupMeta?.modules.length > 0 ? (
          <div className="backup-restore-modules">
            <div className="backup-section-heading compact">
              <div><h4>{t('backup.restoreModules')}</h4><p>{t('backup.restoreModulesHint')}</p></div>
              <div className="backup-text-actions">
                <button type="button" onClick={() => setRestoreModules(backupMeta.modules)}>{t('backup.selectAll')}</button>
                <button type="button" onClick={() => setRestoreModules([])}>{t('backup.clearSelection')}</button>
              </div>
            </div>
            <div className="backup-module-grid compact">
              {backupMeta.modules.map((module) => (
                <label key={module} className={`backup-module-option ${restoreModules.includes(module) ? 'is-selected' : ''}`}>
                  <input type="checkbox" checked={restoreModules.includes(module)} onChange={() => toggleRestoreModule(module)} />
                  <span className="backup-module-check">{restoreModules.includes(module) ? <Check size={13} /> : null}</span>
                  <span>{moduleLabel(module)}</span>
                </label>
              ))}
            </div>
          </div>
        ) : null}

        {backupMeta ? (
          <div className="backup-restore-options">
            <fieldset>
              <legend>{t('backup.restoreMode')}</legend>
              <label className={restoreMode === 'merge' ? 'is-selected' : ''}>
                <input type="radio" name="restore-mode" value="merge" checked={restoreMode === 'merge'} onChange={() => { setRestoreMode('merge'); setReplaceAcknowledged(false) }} />
                <span><strong>{t('backup.merge')}</strong><small>{t('backup.mergeHint')}</small></span>
              </label>
              <label className={restoreMode === 'replace' ? 'is-selected danger' : ''}>
                <input type="radio" name="restore-mode" value="replace" checked={restoreMode === 'replace'} onChange={() => setRestoreMode('replace')} />
                <span><strong>{t('backup.replace')}</strong><small>{t('backup.replaceHint')}</small></span>
              </label>
            </fieldset>

            {restoreMode === 'replace' ? (
              <label className="backup-danger-confirm">
                <input type="checkbox" checked={replaceAcknowledged} onChange={(event) => setReplaceAcknowledged(event.target.checked)} />
                <AlertTriangle size={18} /><span>{t('backup.replaceConfirm')}</span>
              </label>
            ) : <div className="backup-merge-note"><ShieldCheck size={17} />{t('backup.mergeSafeNote')}</div>}

            {canImport ? (
              <button type="button" className={`primary backup-restore-button ${restoreMode === 'replace' ? 'danger' : ''}`} disabled={Boolean(backupBusy) || (restoreMode === 'replace' && !replaceAcknowledged)} onClick={handleRestore}>
                {backupBusy === 'restore' ? <LoaderCircle className="is-spinning" size={17} /> : <RefreshCw size={17} />}
                {backupBusy === 'restore' ? t('backup.restoring') : t('backup.restoreButton')}
              </button>
            ) : <p className="backup-permission-note">{t('backup.importPermission')}</p>}
          </div>
        ) : null}

        {restoreResult ? (
          <div className="backup-result" role="status">
            <div><CheckCircle2 size={20} /><strong>{t('backup.restoreResult')}</strong></div>
            {resultRows(restoreResult).length > 0 ? <ul>{resultRows(restoreResult).map((row) => <li key={row.module}><span>{moduleLabel(row.module)}</span><strong>{row.value}</strong></li>)}</ul> : null}
            {Array.isArray(restoreResult.warnings) && restoreResult.warnings.length > 0 ? <div className="backup-result-warnings"><AlertTriangle size={16} /><span>{restoreResult.warnings.join(' ')}</span></div> : null}
          </div>
        ) : null}
      </section> : null}

      <section className="card reports-transfer-card">
        <div className="backup-section-heading"><div><h3>{t('reports.dataTools')}</h3><p>{t('reports.dataToolsHint')}</p></div></div>
        <div className="reports-transfer-note"><AlertTriangle size={17} /><span>{t('reports.notBackupWarning')}</span></div>
        <div className="table-wrap">
          <table className="product-table reports-transfer-table">
            <thead><tr><th>{t('reports.list')}</th><th>{t('reports.export')}</th><th>{t('reports.import')}</th></tr></thead>
            <tbody>
              {DATA_LISTS.map((list) => (
                <tr key={list.id}>
                  <td><strong>{t(`reports.list.${list.id}`)}</strong></td>
                  <td><div className="table-actions">{FORMATS.map((format) => {
                    const key = `export-${list.id}-${format}`
                    return <button key={format} type="button" className="secondary" disabled={!canExport || Boolean(busyKey)} onClick={() => handleExport(list.id, format)}>{busyKey === key ? <LoaderCircle className="is-spinning" size={14} /> : <Download size={14} />}{format.toUpperCase()}</button>
                  })}</div></td>
                  <td>{list.importable ? (
                    <label className={`import-file-label ${!canImport || busyKey ? 'is-disabled' : ''}`}>
                      {busyKey === `import-${list.id}` ? <LoaderCircle className="is-spinning" size={14} /> : <Upload size={14} />}
                      {busyKey === `import-${list.id}` ? t('reports.importing') : t('reports.chooseFile')}
                      <input type="file" accept=".csv,.json,.xlsx,.txt" hidden disabled={!canImport || Boolean(busyKey)} onChange={(event) => handleImport(list.id, event)} />
                    </label>
                  ) : <span className="reports-export-only">{t('reports.exportOnly')}</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {reportError ? <div className="form-error-banner" role="alert">{reportError}</div> : null}
      {loading ? <section className="card reports-loading"><LoaderCircle className="is-spinning" size={20} />{t('reports.loading')}</section> : null}

      {data ? <>
        <section className="stats-grid stats-grid-3">
          <article className="stat-card"><p className="stat-label">{t('reports.totalStockIn')}</p><p className="stat-value">{data.stock_summary?.total_stock_in ?? 0}</p></article>
          <article className="stat-card"><p className="stat-label">{t('reports.totalStockOut')}</p><p className="stat-value">{data.stock_summary?.total_stock_out ?? 0}</p></article>
          <article className="stat-card"><p className="stat-label">{t('reports.stockMovements')}</p><p className="stat-value">{data.stock_summary?.movement_count ?? 0}</p></article>
        </section>

        <section className="card reports-data-card"><h2>{t('reports.inventoryByCategory')}</h2><div className="table-wrap"><table className="product-table"><thead><tr><th>{t('reports.category')}</th><th>{t('reports.products')}</th><th>{t('reports.units')}</th><th>{t('reports.value')}</th></tr></thead><tbody>
          {(data.categories || []).map((category) => <tr key={category.id}><td>{category.name}</td><td>{category.product_count}</td><td>{category.total_units}</td><td>{currencyFormatter.format(Number(category.total_value || 0))}</td></tr>)}
        </tbody></table></div></section>

        <section className="card reports-data-card"><h2>{t('reports.inventoryBySupplier')}</h2><div className="table-wrap"><table className="product-table"><thead><tr><th>{t('reports.supplier')}</th><th>{t('reports.products')}</th><th>{t('reports.units')}</th><th>{t('reports.value')}</th></tr></thead><tbody>
          {(data.suppliers || []).map((supplier) => <tr key={supplier.id}><td>{supplier.name}</td><td>{supplier.product_count}</td><td>{supplier.total_units}</td><td>{currencyFormatter.format(Number(supplier.total_value || 0))}</td></tr>)}
        </tbody></table></div></section>

        <section className="card reports-data-card"><h2>{t('reports.topProducts')}</h2>{(data.top_products || []).length === 0 ? <p>{t('reports.noProducts')}</p> : <div className="table-wrap"><table className="product-table"><thead><tr><th>{t('reports.product')}</th><th>SKU</th><th>{t('reports.category')}</th><th>{t('reports.quantity')}</th><th>{t('reports.unitPrice')}</th><th>{t('reports.totalValue')}</th></tr></thead><tbody>
          {data.top_products.map((product) => <tr key={product.id}><td>{product.name}</td><td>{product.sku || '—'}</td><td>{product.category ?? '—'}</td><td>{product.quantity}</td><td>{currencyFormatter.format(Number(product.price || 0))}</td><td>{currencyFormatter.format(Number(product.value || 0))}</td></tr>)}
        </tbody></table></div>}</section>
      </> : null}
    </main>
  )
}

export default Reports
