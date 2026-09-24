const fs = require('fs')
const http = require('http')
const os = require('os')
const path = require('path')
const { spawn, spawnSync } = require('child_process')

const desktop = path.resolve(__dirname, '..')
const resources = path.join(desktop, 'resources')
const backend = path.join(resources, 'backend')
const frontend = path.join(resources, 'frontend')
const phpRoot = path.join(resources, 'php')
const php = path.join(phpRoot, 'php.exe')
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'aims-desktop-startup-'))
const database = path.join(work, 'aims.sqlite')
const port = 18775
let server
let serverOutput = ''

function fail(message) {
  throw new Error(`Desktop startup certification failed: ${message}`)
}

function portablePhpIni() {
  const source = path.join(phpRoot, 'php.ini')
  if (!fs.existsSync(source)) fail('bundled php.ini is missing')
  const target = path.join(work, 'php.ini')
  const normalizedRoot = phpRoot.replace(/\\/g, '\\\\')
  const normalizedWork = work.replace(/\\/g, '\\\\')
  const normalizedOpcache = path.join(work, 'opcache').replace(/\\/g, '\\\\')
  fs.mkdirSync(path.join(work, 'opcache'), { recursive: true })
  let contents = fs.readFileSync(source, 'utf8')
    .replace(/C:\\xampp\\php/gi, normalizedRoot)
    .replace(/C:\\xampp\\tmp/gi, normalizedWork)
    .replace(/^\s*;?extension_dir\s*=.*$/gim, `extension_dir="${normalizedRoot}\\\\ext"`)
    .replace(/^\s*;?upload_tmp_dir\s*=.*$/gim, `upload_tmp_dir="${normalizedWork}"`)
    .replace(/^\s*;?session\.save_path\s*=.*$/gim, `session.save_path="${normalizedWork}"`)
    .replace(/^\s*;?opcache\.file_cache\s*=.*$/gim, `opcache.file_cache="${normalizedOpcache}"`)
    .replace(/^\s*;?opcache\.file_cache_fallback\s*=.*$/gim, 'opcache.file_cache_fallback=1')
    .replace(/^\s*browscap\s*=.*$/gim, ';browscap disabled in the portable AIMS runtime')
    .replace(/^\s*(curl\.cainfo|openssl\.cafile)\s*=.*$/gim, '; portable AIMS uses the operating system certificate store')
  fs.writeFileSync(target, contents)
  return target
}

function requestStatus() {
  return new Promise((resolve, reject) => {
    const request = http.get(`http://127.0.0.1:${port}/api/status`, (response) => {
      let body = ''
      response.on('data', (chunk) => { body += chunk })
      response.on('end', () => response.statusCode === 200 ? resolve(body) : reject(new Error(`status endpoint returned ${response.statusCode}`)))
    })
    request.setTimeout(10000, () => request.destroy(new Error('status request timed out')))
    request.on('error', reject)
  })
}

async function waitForBackend() {
  const deadline = Date.now() + 60000
  let lastError = ''
  while (Date.now() < deadline) {
    try { return await requestStatus() } catch (error) { lastError = error.message; await new Promise((resolve) => setTimeout(resolve, 500)) }
  }
  fail(`the bundled Laravel API did not become healthy within 60 seconds (${lastError})${serverOutput ? `: ${serverOutput.trim()}` : ''}`)
}

function validateFrontend() {
  const indexPath = path.join(frontend, 'index.html')
  if (!fs.existsSync(indexPath)) fail('compiled frontend index.html is missing')
  const index = fs.readFileSync(indexPath, 'utf8')
  if (/(?:src|href)=["']https?:\/\//i.test(index)) fail('compiled desktop shell depends on a remote script or stylesheet')
  for (const match of index.matchAll(/(?:src|href)=["']\/([^"'?#]+)/gi)) {
    const asset = path.join(frontend, decodeURIComponent(match[1]))
    if (!fs.existsSync(asset)) fail(`compiled frontend asset is missing: ${match[1]}`)
  }
}

async function main() {
  for (const file of fs.readdirSync(backend, { recursive: true })) {
    const name = path.basename(file).toLowerCase()
    if (name === '.env' || name.startsWith('.env.') || /\.(?:sqlite|sqlite3|db|sql)(?:-(?:wal|shm))?$/i.test(name)) {
      fail(`private development data was bundled: ${file}`)
    }
  }
  for (const required of [php, path.join(backend, 'artisan'), path.join(backend, 'vendor', 'autoload.php')]) {
    if (!fs.existsSync(required)) fail(`required bundled resource is missing: ${required}`)
  }
  validateFrontend()
  fs.writeFileSync(database, '')
  const env = {
    ...process.env,
    APP_NAME: 'AIMS', APP_ENV: 'testing', APP_DEBUG: 'false',
    APP_KEY: 'base64:32Hr/pYy5GkWR0Lcm3HnL0fHw2cbCtmHtravymO8GA0=',
    APP_URL: `http://127.0.0.1:${port}`, APP_OPERATION_MODE: 'offline',
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'log', MAIL_MAILER: 'array',
    TRACKING_EXTERNAL_ENABLED: 'false', PHPRC: portablePhpIni(), PHP_INI_SCAN_DIR: '',
  }
  const setup = spawnSync(php, ['artisan', 'aims:desktop-setup'], {
    cwd: backend, env, encoding: 'utf8', windowsHide: true, timeout: 120000,
  })
  if (setup.status !== 0) fail((setup.stderr || setup.stdout || 'database initialization failed').trim())
  server = spawn(php, ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`], {
    cwd: backend, env, windowsHide: true, shell: false, stdio: ['ignore', 'pipe', 'pipe'],
  })
  server.stdout.on('data', (chunk) => { serverOutput += chunk.toString() })
  server.stderr.on('data', (chunk) => { serverOutput += chunk.toString() })
  const status = JSON.parse(await waitForBackend())
  if (!status || status.status !== 'ok') fail('the bundled API returned an invalid health response')
  process.stdout.write('AIMS desktop startup certification passed: assets local, SQLite initialized, API healthy.\n')
}

main().catch((error) => {
  process.stderr.write(`${error.message}\n`)
  process.exitCode = 1
}).finally(() => {
  if (server && !server.killed) {
    if (process.platform === 'win32') spawnSync('taskkill.exe', ['/pid', String(server.pid), '/t', '/f'], { windowsHide: true, stdio: 'ignore' })
    else server.kill('SIGTERM')
  }
  fs.rmSync(work, { recursive: true, force: true })
})
