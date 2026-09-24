const { app, BrowserWindow, dialog, ipcMain, Menu, net: electronNet, safeStorage, shell } = require('electron')
const { autoUpdater } = require('electron-updater')
const { execFileSync, spawn, spawnSync } = require('child_process')
const crypto = require('crypto')
const fs = require('fs')
const http = require('http')
const nodeNet = require('net')
const os = require('os')
const path = require('path')
const {
  installLicence,
  machineFingerprint: commercialMachineFingerprint,
  readLicence,
} = require('./license.cjs')

const backendPort = 18765
const frontendPort = 18766
const backendUrl = `http://127.0.0.1:${backendPort}`
const frontendUrl = `http://127.0.0.1:${frontendPort}`
const gotSingleInstanceLock = app.requestSingleInstanceLock()

let backendProcess
let documentMaintenanceProcess
let documentMaintenanceTimer
let aisProcess
let frontendServer
let mainWindow
let shuttingDown = false
let logFile
let currentLicence
let updateStatus = { state: 'idle', message: 'Updates have not been checked yet.' }
let updateCheckRunning = false

function userDataPath(...parts) {
  return path.join(app.getPath('userData'), ...parts)
}

function log(message, details = '') {
  try {
    const directory = app.getPath('userData')
    const logsDirectory = path.join(directory, 'logs')
    fs.mkdirSync(logsDirectory, { recursive: true })
    logFile ||= path.join(logsDirectory, 'desktop.log')
    if (fs.existsSync(logFile) && fs.statSync(logFile).size > 2 * 1024 * 1024) {
      fs.renameSync(logFile, `${logFile}.1`)
    }
    const sanitized = String(details || '').replace(/(password|token|secret|app[_-]?key)\s*[:=]\s*[^\s,;]+/gi, '$1=[redacted]')
    fs.appendFileSync(logFile, `${new Date().toISOString()} ${message}${sanitized ? ` ${sanitized}` : ''}\n`)
  } catch {
    // Startup diagnostics must never prevent the application from opening.
  }
}

function resourcePath(...parts) {
  const root = app.isPackaged ? process.resourcesPath : path.join(__dirname, '..', 'resources')
  return path.join(root, ...parts)
}

function licenceFilePath() {
  return userDataPath('license.aims-license')
}

function licencePublicKeyPath() {
  return resourcePath('license-public.pem')
}

function developmentLicence() {
  return {
    valid: true,
    development: true,
    machineId: commercialMachineFingerprint(),
    customer: 'AIMS Development',
    edition: 'development',
    licenseId: 'DEVELOPMENT',
    issuedAt: null,
    expiresAt: null,
    features: [],
  }
}

function licenceStatus() {
  if (! app.isPackaged) return developmentLicence()
  return readLicence(licenceFilePath(), licencePublicKeyPath())
}

async function selectAndInstallLicence() {
  const selection = await dialog.showOpenDialog({
    title: 'Activate AIMS',
    properties: ['openFile'],
    filters: [
      { name: 'AIMS licence', extensions: ['aims-license'] },
      { name: 'All files', extensions: ['*'] },
    ],
  })
  if (selection.canceled || ! selection.filePaths[0]) return null

  try {
    const status = installLicence(selection.filePaths[0], licenceFilePath(), licencePublicKeyPath())
    currentLicence = status
    log('Commercial licence activated', `licenseId=${status.licenseId} customer=${status.customer}`)
    return status
  } catch (error) {
    log('Commercial licence activation rejected', error.message)
    await dialog.showMessageBox({
      type: 'error',
      title: 'Licence not accepted',
      message: 'AIMS could not activate this licence.',
      detail: error.message,
    })
    return null
  }
}

async function ensureCommercialLicence() {
  if (! app.isPackaged) return developmentLicence()

  while (true) {
    const status = licenceStatus()
    if (status.valid) return status

    const result = await dialog.showMessageBox({
      type: 'warning',
      title: 'Activate AIMS',
      message: 'This computer needs a valid AIMS commercial licence.',
      detail: `${status.error}\n\nComputer ID:\n${status.machineId}\n\nSend this Computer ID to the AIMS owner to receive a signed licence file.`,
      buttons: ['Activate licence', 'Exit'],
      defaultId: 0,
      cancelId: 1,
      noLink: true,
    })
    if (result.response === 1) return null

    const activated = await selectAndInstallLicence()
    if (activated) return activated
  }
}

function sendUpdateStatus(next) {
  updateStatus = { ...updateStatus, ...next }
  if (mainWindow && ! mainWindow.isDestroyed()) {
    mainWindow.webContents.send('aims:update-status', updateStatus)
  }
}

function updateConfiguration() {
  const configurationPath = resourcePath('update-config.json')
  if (! fs.existsSync(configurationPath)) return null
  try {
    return JSON.parse(fs.readFileSync(configurationPath, 'utf8'))
  } catch (error) {
    log('Update configuration is invalid', error.message)
    return null
  }
}

async function checkForUpdates(interactive = false) {
  if (! app.isPackaged) {
    sendUpdateStatus({ state: 'development', message: 'Automatic updates are checked in packaged AIMS releases.' })
    return updateStatus
  }
  if (updateCheckRunning) return updateStatus

  // Updates are optional. A disconnected computer must continue running the
  // complete local application without waiting for a network timeout.
  if (!electronNet.isOnline()) {
    sendUpdateStatus({
      state: 'offline',
      message: 'AIMS is working offline. Updates can be checked when an internet connection is available.',
    })
    return updateStatus
  }

  const configuration = updateConfiguration()
  if (! configuration) {
    sendUpdateStatus({ state: 'disabled', message: 'The secure update channel is not configured.' })
    return updateStatus
  }

  updateCheckRunning = true
  sendUpdateStatus({ state: 'checking', message: 'Checking for a signed AIMS update…' })
  try {
    autoUpdater.setFeedURL(configuration)
    await autoUpdater.checkForUpdates()
  } catch (error) {
    log('Automatic update check failed', error.message)
    sendUpdateStatus({ state: 'error', message: interactive ? error.message : 'The update service is temporarily unavailable.' })
  } finally {
    updateCheckRunning = false
  }
  return updateStatus
}

function configureAutomaticUpdates() {
  autoUpdater.autoDownload = true
  autoUpdater.autoInstallOnAppQuit = true
  autoUpdater.allowDowngrade = false

  autoUpdater.on('checking-for-update', () => {
    sendUpdateStatus({ state: 'checking', message: 'Checking for a signed AIMS update…' })
  })
  autoUpdater.on('update-not-available', (info) => {
    sendUpdateStatus({
      state: 'current',
      version: info?.version || app.getVersion(),
      message: `AIMS ${app.getVersion()} is up to date.`,
    })
  })
  autoUpdater.on('update-available', (info) => {
    sendUpdateStatus({
      state: 'downloading',
      version: info?.version,
      percent: 0,
      message: `Downloading signed AIMS ${info?.version || 'update'}…`,
    })
  })
  autoUpdater.on('download-progress', (progress) => {
    const percent = Math.round(progress.percent || 0)
    sendUpdateStatus({ state: 'downloading', percent, message: `Downloading signed update… ${percent}%` })
  })
  autoUpdater.on('update-downloaded', async (info) => {
    sendUpdateStatus({
      state: 'ready',
      version: info?.version,
      percent: 100,
      message: `AIMS ${info?.version || 'update'} is ready to install.`,
    })
    const result = await dialog.showMessageBox({
      type: 'info',
      title: 'AIMS update ready',
      message: `A signed AIMS ${info?.version || 'update'} has been downloaded.`,
      detail: 'Restart now to install it, or choose Later and AIMS will install it when the application closes.',
      buttons: ['Restart and install', 'Later'],
      defaultId: 0,
      cancelId: 1,
      noLink: true,
    })
    if (result.response === 0) {
      shuttingDown = true
      autoUpdater.quitAndInstall(false, true)
    }
  })
  autoUpdater.on('error', (error) => {
    log('Automatic updater error', error.message)
    sendUpdateStatus({ state: 'error', message: 'The update service is temporarily unavailable.' })
  })

  setTimeout(() => checkForUpdates(false), 5000)
}

function copyRuntime() {
  const target = userDataPath('runtime')
  const source = resourcePath('backend')
  const filter = (entry) => !entry.endsWith('.env') && !entry.includes(`${path.sep}storage${path.sep}logs`)

  if (!fs.existsSync(path.join(target, 'artisan'))) {
    log('Copying packaged Laravel runtime')
    fs.cpSync(source, target, { recursive: true, force: true, filter })
    return target
  }

  const sourceLock = path.join(source, 'composer.lock')
  const targetLock = path.join(target, 'composer.lock')
  const dependenciesChanged = !fs.existsSync(targetLock) || fs.readFileSync(sourceLock, 'utf8') !== fs.readFileSync(targetLock, 'utf8')
  fs.mkdirSync(target, { recursive: true })

  const directories = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes']
  if (dependenciesChanged) directories.push('vendor')
  for (const directory of directories) {
    const sourceDirectory = path.join(source, directory)
    const targetDirectory = path.join(target, directory)
    // Mirror packaged application code instead of overlaying it. Otherwise a
    // controller, migration, route, or dependency removed in a later release
    // can remain active indefinitely in an upgraded desktop installation.
    fs.rmSync(targetDirectory, { recursive: true, force: true })
    fs.cpSync(sourceDirectory, targetDirectory, { recursive: true, force: true, filter })
  }
  fs.mkdirSync(path.join(target, 'bootstrap', 'cache'), { recursive: true })
  for (const file of ['artisan', 'composer.json', 'composer.lock']) {
    fs.copyFileSync(path.join(source, file), path.join(target, file))
  }
  log('Laravel runtime synchronized', `dependenciesChanged=${dependenciesChanged}`)
  return target
}

function phpExecutable() {
  const candidates = [
    process.env.AIMS_PHP_PATH,
    app.isPackaged ? resourcePath('php', 'php.exe') : path.join(__dirname, '..', 'runtime', 'php', 'php.exe'),
  ].filter(Boolean)

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) return candidate
  }

  try {
    const command = process.platform === 'win32' ? 'where.exe' : 'which'
    const discovered = execFileSync(command, ['php'], { encoding: 'utf8' }).split(/\r?\n/).find(Boolean)
    if (discovered) return discovered.trim()
  } catch {
    // The friendly error below is more useful than a raw spawn failure.
  }

  throw new Error('The bundled PHP runtime is missing. Reinstall AIMS or contact your administrator.')
}

function ensurePhpIni() {
  const source = path.join(path.dirname(phpExecutable()), 'php.ini')
  if (!fs.existsSync(source)) return undefined

  const phpRoot = path.dirname(phpExecutable())
  const tempDirectory = userDataPath('tmp')
  const logsDirectory = userDataPath('logs')
  const opcacheDirectory = userDataPath('opcache')
  fs.mkdirSync(tempDirectory, { recursive: true })
  fs.mkdirSync(logsDirectory, { recursive: true })
  fs.mkdirSync(opcacheDirectory, { recursive: true })

  const target = userDataPath('runtime-php.ini')
  let contents = fs.readFileSync(source, 'utf8')
  const normalizedRoot = phpRoot.replace(/\\/g, '\\\\')
  const normalizedTemp = tempDirectory.replace(/\\/g, '\\\\')
  const normalizedLog = path.join(logsDirectory, 'php.log').replace(/\\/g, '\\\\')
  const normalizedOpcache = opcacheDirectory.replace(/\\/g, '\\\\')
  contents = contents.replace(/C:\\xampp\\php/gi, normalizedRoot)
  contents = contents.replace(/C:\\xampp\\tmp/gi, normalizedTemp)
  contents = contents.replace(/^\s*;?extension_dir\s*=.*$/gim, `extension_dir="${normalizedRoot}\\\\ext"`)
  contents = contents.replace(/^\s*;?error_log\s*=.*$/gim, `error_log="${normalizedLog}"`)
  contents = contents.replace(/^\s*;?upload_tmp_dir\s*=.*$/gim, `upload_tmp_dir="${normalizedTemp}"`)
  contents = contents.replace(/^\s*;?session\.save_path\s*=.*$/gim, `session.save_path="${normalizedTemp}"`)
  // Portable company backups can include product images and accounting proof
  // documents. Give the local-only API enough room to receive a deliberate
  // restore without weakening the public web deployment's PHP configuration.
  contents = contents.replace(/^\s*max_execution_time\s*=.*$/gim, 'max_execution_time=300')
  contents = contents.replace(/^\s*memory_limit\s*=.*$/gim, 'memory_limit=1024M')
  contents = contents.replace(/^\s*post_max_size\s*=.*$/gim, 'post_max_size=270M')
  contents = contents.replace(/^\s*upload_max_filesize\s*=.*$/gim, 'upload_max_filesize=256M')
  // A relocated Windows PHP runtime can reject OPcache shared-memory handlers
  // under ASLR. Its file-cache fallback keeps the performance benefit without
  // depending on the PHP installation path used on the build computer.
  contents = contents.replace(/^\s*;?opcache\.file_cache\s*=.*$/gim, `opcache.file_cache="${normalizedOpcache}"`)
  contents = contents.replace(/^\s*;?opcache\.file_cache_fallback\s*=.*$/gim, 'opcache.file_cache_fallback=1')
  contents = contents.replace(/^\s*browscap\s*=.*$/gim, ';browscap disabled in the portable AIMS runtime')
  contents = contents.replace(/^\s*(curl\.cainfo|openssl\.cafile)\s*=.*$/gim, '; portable AIMS uses the operating system certificate store')
  fs.writeFileSync(target, contents, 'utf8')
  return target
}

function machineFingerprint() {
  return crypto.createHash('sha256')
    .update(`${process.platform}|${os.hostname()}|${os.userInfo().username}`)
    .digest('hex')
}

function encryptedValue(file, value) {
  fs.writeFileSync(file, safeStorage.encryptString(value), { mode: 0o600 })
}

function decryptedValue(file) {
  return safeStorage.decryptString(fs.readFileSync(file))
}

function envFileValue(file, name) {
  try {
    if (!fs.existsSync(file)) return ''
    const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    const match = fs.readFileSync(file, 'utf8').match(new RegExp(`^${escapedName}=(.*)$`, 'm'))
    if (!match) return ''
    return match[1].trim().replace(/^(['"])(.*)\1$/, '$2')
  } catch {
    return ''
  }
}

function availableAisStreamKey() {
  const candidates = [
    process.env.AIMS_AISSTREAM_API_KEY,
    process.env.AISSTREAM_API_KEY,
    envFileValue(path.resolve(__dirname, '..', '..', 'backend', '.env'), 'AISSTREAM_API_KEY'),
    envFileValue(path.resolve(path.dirname(process.execPath), '..', '..', '..', 'backend', '.env'), 'AISSTREAM_API_KEY'),
  ]
  return candidates.map((value) => String(value || '').trim()).find(Boolean) || ''
}

function loadAisStreamKey() {
  const encryptedFile = userDataPath('aisstream.key.enc')
  if (fs.existsSync(encryptedFile)) {
    if (!safeStorage.isEncryptionAvailable()) return ''
    try {
      return decryptedValue(encryptedFile).trim()
    } catch {
      log('Protected AIS configuration could not be unlocked')
      return ''
    }
  }

  const key = availableAisStreamKey()
  if (!key) return ''
  if (safeStorage.isEncryptionAvailable()) encryptedValue(encryptedFile, key)
  return key
}

function ensureDeviceBinding() {
  const bindingFile = userDataPath('device.binding')
  const fingerprint = machineFingerprint()

  if (fs.existsSync(bindingFile)) {
    if (!safeStorage.isEncryptionAvailable()) {
      throw new Error('Windows secure storage is unavailable. AIMS cannot unlock this protected installation.')
    }
    let boundFingerprint
    try {
      boundFingerprint = decryptedValue(bindingFile)
    } catch {
      throw new Error('This AIMS data belongs to another Windows computer or user. Contact your administrator for a licensed transfer.')
    }
    if (boundFingerprint !== fingerprint) {
      throw new Error('This AIMS data belongs to another Windows computer or user. Contact your administrator for a licensed transfer.')
    }
    return
  }

  if (app.isPackaged && !safeStorage.isEncryptionAvailable()) {
    throw new Error('Windows secure storage is unavailable. AIMS cannot protect this installation.')
  }
  if (safeStorage.isEncryptionAvailable()) encryptedValue(bindingFile, fingerprint)
  else fs.writeFileSync(bindingFile, fingerprint, { encoding: 'utf8', mode: 0o600 })
}

function loadAppKey() {
  const encryptedFile = userDataPath('aims.key.enc')
  const legacyFile = userDataPath('aims.key')

  if (safeStorage.isEncryptionAvailable()) {
    if (fs.existsSync(encryptedFile)) {
      try {
        return decryptedValue(encryptedFile).trim()
      } catch {
        throw new Error('The protected AIMS encryption key cannot be unlocked on this computer.')
      }
    }

    const key = fs.existsSync(legacyFile)
      ? fs.readFileSync(legacyFile, 'utf8').trim()
      : `base64:${crypto.randomBytes(32).toString('base64')}`
    encryptedValue(encryptedFile, key)
    if (fs.existsSync(legacyFile)) fs.rmSync(legacyFile, { force: true })
    return key
  }

  if (app.isPackaged) throw new Error('Windows secure storage is unavailable. AIMS cannot protect this installation.')
  const fallback = fs.existsSync(legacyFile) ? fs.readFileSync(legacyFile, 'utf8').trim() : `base64:${crypto.randomBytes(32).toString('base64')}`
  if (!fs.existsSync(legacyFile)) fs.writeFileSync(legacyFile, fallback, { encoding: 'utf8', mode: 0o600 })
  return fallback
}

function backupDatabase() {
  const database = userDataPath('aims.sqlite')
  if (!fs.existsSync(database)) return
  const directory = userDataPath('backups')
  fs.mkdirSync(directory, { recursive: true })
  const stamp = new Date().toISOString().replace(/[:.]/g, '-')
  const backup = path.join(directory, `aims-${stamp}.sqlite`)

  try {
    const script = [
      '$source = getenv("AIMS_BACKUP_SOURCE");',
      '$target = getenv("AIMS_BACKUP_TARGET");',
      '$database = new PDO("sqlite:".$source);',
      '$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);',
      '$database->exec("VACUUM INTO ".$database->quote($target));',
    ].join('')

    execFileSync(phpExecutable(), ['-r', script], {
      env: {
        ...process.env,
        AIMS_BACKUP_SOURCE: database,
        AIMS_BACKUP_TARGET: backup,
      },
      stdio: 'ignore',
      timeout: 30000,
      windowsHide: true,
    })
  } catch (error) {
    // Keep a recoverable snapshot even if VACUUM INTO is unavailable. When a
    // previous run ended abnormally, the WAL sidecar can contain committed rows
    // that are not yet checkpointed into the main SQLite file.
    fs.copyFileSync(database, backup)
    for (const suffix of ['-wal', '-shm']) {
      const sourceSidecar = `${database}${suffix}`
      if (fs.existsSync(sourceSidecar)) fs.copyFileSync(sourceSidecar, `${backup}${suffix}`)
    }
    log('Consistent SQLite snapshot was unavailable; copied database sidecars', error.message)
  }

  const backups = fs.readdirSync(directory)
    .filter((file) => file.endsWith('.sqlite'))
    .sort()
    .reverse()
  for (const oldBackup of backups.slice(5)) {
    const oldPath = path.join(directory, oldBackup)
    fs.rmSync(oldPath, { force: true })
    fs.rmSync(`${oldPath}-wal`, { force: true })
    fs.rmSync(`${oldPath}-shm`, { force: true })
  }
  log('Database backup created', backup)
}

function localEnvironment() {
  ensureDeviceBinding()
  const appKey = loadAppKey()
  const aisStreamKey = loadAisStreamKey()
  const phpIni = ensurePhpIni()
  const inherited = { ...process.env }
  for (const key of Object.keys(inherited)) {
    if (/^(APP_KEY|DB_|MYSQL_|REDIS_PASSWORD|AISSTREAM_API_KEY|VESSELAPI_API_KEY|REVERB_APP_SECRET|STRIPE_SECRET|MAIL_PASSWORD)$/i.test(key)) delete inherited[key]
  }

  return {
    ...inherited,
    APP_NAME: 'AIMS',
    APP_ENV: 'production',
    APP_DEBUG: 'false',
    APP_KEY: appKey,
    APP_URL: backendUrl,
    APP_OPERATION_MODE: 'offline',
    TRACKING_EXTERNAL_ENABLED: aisStreamKey ? 'true' : 'false',
    TRACKING_VESSEL_PROVIDER: 'aisstream',
    ...(aisStreamKey ? { AISSTREAM_API_KEY: aisStreamKey } : {}),
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: userDataPath('aims.sqlite'),
    CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'log',
    SESSION_DRIVER: 'file',
    LOG_CHANNEL: 'single',
    LOG_LEVEL: 'warning',
    MAIL_MAILER: 'log',
    CORS_ALLOWED_ORIGINS: frontendUrl,
    ...(phpIni ? { PHPRC: phpIni, PHP_INI_SCAN_DIR: '' } : {}),
  }
}

function portAvailable(port) {
  return new Promise((resolve) => {
    const probe = nodeNet.createServer()
    probe.once('error', () => resolve(false))
    probe.once('listening', () => probe.close(() => resolve(true)))
    probe.listen(port, '127.0.0.1')
  })
}

function runArtisan(runtime, args, env) {
  log('Running Laravel command', args.join(' '))
  const result = spawnSync(phpExecutable(), ['artisan', ...args], {
    cwd: runtime,
    env,
    encoding: 'utf8',
    timeout: 120000,
    windowsHide: true,
  })
  if (result.status !== 0) {
    const output = result.stderr || result.stdout || 'AIMS setup failed.'
    log('Laravel command failed', output)
    throw new Error('AIMS could not initialize its local database. Check the desktop log for details.')
  }
}

function startBackend(runtime, env) {
  const maintainDocuments = () => {
    if (shuttingDown || documentMaintenanceProcess) return
    documentMaintenanceProcess = spawn(phpExecutable(), ['artisan', 'documents:expiry-alerts'], {cwd: runtime, env, windowsHide: true, shell: false, stdio: ['ignore', 'ignore', 'pipe']})
    documentMaintenanceProcess.stderr.on('data', chunk => log('Document maintenance', chunk.toString().trim()))
    documentMaintenanceProcess.once('error', error => log('Document maintenance error', error.message))
    documentMaintenanceProcess.once('close', () => { documentMaintenanceProcess = null })
  }
  maintainDocuments()
  documentMaintenanceTimer = setInterval(maintainDocuments, 60 * 60 * 1000)
  backendProcess = spawn(phpExecutable(), ['artisan', 'serve', '--host=127.0.0.1', `--port=${backendPort}`], {
    cwd: runtime,
    env,
    windowsHide: true,
    shell: false,
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  backendProcess.stdout.on('data', (chunk) => log('Backend', chunk.toString().trim()))
  backendProcess.stderr.on('data', (chunk) => log('Backend diagnostic', chunk.toString().trim()))
  backendProcess.once('error', (error) => log('Backend process error', error.message))
  backendProcess.once('exit', (code, signal) => {
    log('Backend stopped', `code=${code} signal=${signal || 'none'}`)
    if (!shuttingDown && mainWindow) dialog.showErrorBox('AIMS backend stopped', 'The local AIMS service stopped unexpectedly. Restart the application.')
  })
  log('Backend process started', `pid=${backendProcess.pid} port=${backendPort}`)
}

function startAisWorker(runtime, env) {
  if (!env.AISSTREAM_API_KEY || env.TRACKING_EXTERNAL_ENABLED !== 'true') {
    log('AIS listener disabled', 'A protected AIS provider key is not configured on this installation')
    return
  }

  aisProcess = spawn(phpExecutable(), ['artisan', 'tracking:aisstream'], {
    cwd: runtime,
    env,
    windowsHide: true,
    shell: false,
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  aisProcess.stdout.on('data', (chunk) => log('AIS listener', chunk.toString().trim()))
  aisProcess.stderr.on('data', (chunk) => log('AIS listener diagnostic', chunk.toString().trim()))
  aisProcess.once('error', (error) => log('AIS listener process error', error.message))
  aisProcess.once('exit', (code, signal) => {
    log('AIS listener stopped', `code=${code} signal=${signal || 'none'}`)
    aisProcess = null
    if (!shuttingDown && code !== 0) {
      log('AIS listener restart scheduled', 'retrying in 5 seconds')
      setTimeout(() => {
        if (!shuttingDown && !aisProcess) startAisWorker(runtime, env)
      }, 5000)
    }
  })
  log('AIS listener started', `pid=${aisProcess.pid}`)
}

function waitForBackend() {
  return new Promise((resolve, reject) => {
    const expiresAt = Date.now() + 30000
    const retry = () => {
      if (Date.now() >= expiresAt) return reject(new Error('The local AIMS backend did not start. Check that the application files are complete.'))
      setTimeout(check, 300)
    }
    const check = () => {
      const request = http.get(`${backendUrl}/api/status`, (response) => {
        response.resume()
        if (response.statusCode === 200) return resolve()
        retry()
      })
      request.setTimeout(2000, () => request.destroy())
      request.on('error', retry)
    }
    check()
  })
}

function contentType(file) {
  return {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.webmanifest': 'application/manifest+json',
    '.wasm': 'application/wasm',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.jpg': 'image/jpeg',
    '.jpeg': 'image/jpeg',
    '.webp': 'image/webp',
    '.avif': 'image/avif',
    '.gif': 'image/gif',
    '.ico': 'image/x-icon',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
    '.mp4': 'video/mp4',
    '.webm': 'video/webm',
  }[path.extname(file).toLowerCase()] || 'application/octet-stream'
}

function startFrontend() {
  const directory = path.resolve(resourcePath('frontend'))
  const indexFile = path.join(directory, 'index.html')
  if (!fs.existsSync(indexFile)) {
    throw new Error('The bundled AIMS interface is missing. Reinstall AIMS or contact your administrator.')
  }

  return new Promise((resolve, reject) => {
    frontendServer = http.createServer((request, response) => {
      if (!['GET', 'HEAD'].includes(request.method || 'GET')) {
        response.writeHead(405, { Allow: 'GET, HEAD' }).end()
        return
      }

      let pathname
      try {
        pathname = decodeURIComponent(new URL(request.url, frontendUrl).pathname)
      } catch {
        response.writeHead(400).end('Bad request')
        return
      }

      const candidate = path.resolve(directory, `.${pathname === '/' ? '/index.html' : pathname}`)
      const relative = path.relative(directory, candidate)
      const safe = !relative.startsWith('..') && !path.isAbsolute(relative)
      const candidateExists = safe && fs.existsSync(candidate) && fs.statSync(candidate).isFile()
      const looksLikeAsset = path.extname(pathname) !== ''
      if (!candidateExists && looksLikeAsset) {
        response.writeHead(404, {
          'Cache-Control': 'no-store',
          'X-Content-Type-Options': 'nosniff',
        }).end('Not found')
        return
      }

      const target = candidateExists ? candidate : indexFile
      const basename = path.basename(target)
      const isEntryDocument = target === indexFile
      const isHashedAsset = path.dirname(target).endsWith(`${path.sep}assets`)
        && /[-_.][A-Za-z0-9_-]{8,}\.[^.]+$/.test(basename)
      const headers = {
        'Content-Type': contentType(target),
        'Content-Length': fs.statSync(target).size,
        // All animation code, fonts, icons, and application imagery are served
        // locally. Network access is optional and only permitted for map tiles.
        'Content-Security-Policy': "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.tile.openstreetmap.org https://tile.openstreetmap.org https://*.basemaps.cartocdn.com; font-src 'self' data:; connect-src 'self' blob: http://127.0.0.1:18765 ws://127.0.0.1:18765; worker-src 'self' blob:; media-src 'self' data: blob:; object-src 'none'; frame-src 'self' blob:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        'X-Content-Type-Options': 'nosniff',
        'X-Frame-Options': 'DENY',
        'Referrer-Policy': 'no-referrer',
        'Cache-Control': isEntryDocument
          ? 'no-store'
          : isHashedAsset
            ? 'public, max-age=31536000, immutable'
            : 'no-cache',
      }
      response.writeHead(200, headers)
      if (request.method === 'HEAD') {
        response.end()
        return
      }
      fs.createReadStream(target).on('error', () => response.destroy()).pipe(response)
    })

    const startupError = (error) => {
      log('Frontend server failed to start', error.message)
      reject(error)
    }
    frontendServer.once('error', startupError)
    frontendServer.on('error', (error) => log('Frontend server error', error.message))
    frontendServer.listen(frontendPort, '127.0.0.1', () => {
      frontendServer.off('error', startupError)
      log('Frontend server started', `port=${frontendPort} assets=local`)
      resolve()
    })
  })
}

function configureWindowSecurity(window) {
  window.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https:\/\//i.test(url)) shell.openExternal(url)
    return { action: 'deny' }
  })
  window.webContents.on('will-navigate', (event, url) => {
    if (url === frontendUrl || url.startsWith(`${frontendUrl}/`)) return
    event.preventDefault()
    if (/^https:\/\//i.test(url)) shell.openExternal(url)
  })
  window.webContents.on('will-attach-webview', (event) => event.preventDefault())
  window.webContents.on('before-input-event', (event, input) => {
    if (app.isPackaged && (input.key === 'F12' || (input.control && input.shift && input.key.toLowerCase() === 'i'))) event.preventDefault()
  })
}

async function createWindow() {
  try {
    Menu.setApplicationMenu(null)
    log('AIMS startup started', `version=${app.getVersion()} packaged=${app.isPackaged}`)
    currentLicence = await ensureCommercialLicence()
    if (! currentLicence) {
      app.quit()
      return
    }
    const runtime = copyRuntime()
    const env = localEnvironment()
    if (!await portAvailable(backendPort)) throw new Error(`Port ${backendPort} is already in use. Close another AIMS instance and try again.`)
    if (!await portAvailable(frontendPort)) throw new Error(`Port ${frontendPort} is already in use. Close another AIMS instance and try again.`)
    backupDatabase()
    runArtisan(runtime, ['optimize:clear'], env)
    runArtisan(runtime, ['aims:desktop-setup'], env)
    startBackend(runtime, env)
    startAisWorker(runtime, env)
    await waitForBackend()
    await startFrontend()

    const icon = resourcePath('frontend', 'aims-logo.png')
    mainWindow = new BrowserWindow({
      width: 1440,
      height: 920,
      minWidth: 1100,
      minHeight: 700,
      title: 'AIMS | Inventory Management',
      icon: fs.existsSync(icon) ? icon : undefined,
      autoHideMenuBar: true,
      backgroundColor: '#f8fafc',
      webPreferences: {
        preload: path.join(__dirname, 'preload.cjs'),
        contextIsolation: true,
        nodeIntegration: false,
        sandbox: true,
        devTools: !app.isPackaged,
        webSecurity: true,
        allowRunningInsecureContent: false,
      },
    })
    mainWindow.setMenuBarVisibility(false)
    configureWindowSecurity(mainWindow)
    mainWindow.on('closed', () => { mainWindow = null })
    await mainWindow.loadURL(`${frontendUrl}/login`)
    configureAutomaticUpdates()
    log('AIMS startup completed')
  } catch (error) {
    log('AIMS startup failed', error.stack || error.message)
    dialog.showErrorBox('AIMS could not start', error.message)
    app.quit()
  }
}

ipcMain.handle('aims:licence-status', () => currentLicence || licenceStatus())
ipcMain.handle('aims:activate-licence', async () => {
  const activated = await selectAndInstallLicence()
  return activated || currentLicence || licenceStatus()
})
ipcMain.handle('aims:update-status', () => updateStatus)
ipcMain.handle('aims:check-updates', () => checkForUpdates(true))

function shutdown() {
  if (shuttingDown) return
  shuttingDown = true
  log('AIMS shutdown started')
  if (frontendServer) frontendServer.close()
  if (aisProcess && !aisProcess.killed) {
    if (process.platform === 'win32') {
      spawnSync('taskkill.exe', ['/pid', String(aisProcess.pid), '/t', '/f'], { windowsHide: true, stdio: 'ignore' })
    } else {
      aisProcess.kill('SIGTERM')
    }
  }
  if (backendProcess && !backendProcess.killed) {
    clearInterval(documentMaintenanceTimer)
    if(documentMaintenanceProcess && !documentMaintenanceProcess.killed) documentMaintenanceProcess.kill()
    if (process.platform === 'win32') {
      spawnSync('taskkill.exe', ['/pid', String(backendProcess.pid), '/t', '/f'], { windowsHide: true, stdio: 'ignore' })
    } else {
      backendProcess.kill('SIGTERM')
    }
  }
}

if (!gotSingleInstanceLock) {
  app.quit()
} else {
  app.on('second-instance', () => {
    if (mainWindow) {
      if (mainWindow.isMinimized()) mainWindow.restore()
      mainWindow.focus()
    }
  })
  app.whenReady().then(createWindow)
  app.on('window-all-closed', () => app.quit())
  app.on('before-quit', shutdown)
  process.on('uncaughtException', (error) => log('Uncaught main-process error', error.stack || error.message))
  process.on('unhandledRejection', (error) => log('Unhandled main-process rejection', error?.stack || error?.message || error))
}
