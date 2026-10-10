const fs = require('fs')
const http = require('http')
const os = require('os')
const path = require('path')
const { spawn, spawnSync } = require('child_process')
const { sanitizeInheritedEnvironment } = require('../src/runtime-env.cjs')
const { createSupervisor } = require('../src/process-supervisor.cjs')

const desktop = path.resolve(__dirname, '..')
const resources = path.join(desktop, 'resources')
const backend = path.join(resources, 'backend')
const frontend = path.join(resources, 'frontend')
const phpRoot = path.join(resources, 'php')
const php = path.join(phpRoot, 'php.exe')
const python = path.join(resources, 'python', 'python.exe')
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'aims-desktop-startup-'))
const database = path.join(work, 'aims.sqlite')
const port = 18775
let server
let serverOutput = ''
let supervisor

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

function requestStatus(endpoint = '/api/status', data = null, token = null) {
  return new Promise((resolve, reject) => {
    const payload = data ? JSON.stringify(data) : null
    const request = http.request(`http://127.0.0.1:${port}${endpoint}`, {method: payload ? 'POST' : 'GET', headers: {'Content-Type':'application/json','Accept':'application/json', ...(token ? {Authorization:`Bearer ${token}`} : {})}}, (response) => {
      let body = ''
      response.on('data', (chunk) => { body += chunk })
      response.on('end', () => {
        let detail=body.slice(0,600)
        try {const report=JSON.parse(body);if(report.checks)detail=JSON.stringify(report.checks.filter(check=>check.status==='critical'))} catch {}
        response.statusCode === 200 ? resolve(body) : reject(new Error(`${endpoint} returned ${response.statusCode}: ${detail}`))
      })
    })
    request.setTimeout(10000, () => request.destroy(new Error('status request timed out')))
    request.on('error', reject)
    request.end(payload)
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
  for (const required of [php, python, path.join(backend, 'ml', 'forecast.py'), path.join(backend, 'artisan'), path.join(backend, 'vendor', 'autoload.php')]) {
    if (!fs.existsSync(required)) fail(`required bundled resource is missing: ${required}`)
  }
  validateFrontend()
  const ml=spawnSync(python,['-I',path.join(backend,'ml','forecast.py')],{input:JSON.stringify({series:[]}),encoding:'utf8',windowsHide:true})
  if(ml.status!==0||JSON.parse(ml.stdout).status!=='insufficient_data')fail('bundled local forecasting runtime failed')
  fs.writeFileSync(database, '')
  for (const directory of ['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','documents','backups']) fs.mkdirSync(path.join(work,directory),{recursive:true})
  const recoveryPassword = require('node:crypto').randomBytes(24).toString('base64url')
  const env = {
    ...sanitizeInheritedEnvironment(process.env),
    AIMS_ML_PYTHON: python,
    APP_NAME: 'AIMS', APP_ENV: 'production', APP_DEBUG: 'false',
    APP_KEY: 'base64:'+require('node:crypto').randomBytes(32).toString('base64'),
    APP_URL: `http://127.0.0.1:${port}`, APP_OPERATION_MODE: 'offline',
    DB_CONNECTION: 'sqlite', DB_DATABASE: database, DB_URL: '',
    CACHE_STORE: 'file', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'database', DB_QUEUE_RETRY_AFTER:'300', REDIS_ENABLED:'false',
    FRONTEND_URL:`http://127.0.0.1:${port}`, CORS_ALLOWED_ORIGINS:`http://127.0.0.1:${port}`, LOG_CHANNEL:'daily', LOG_DAILY_DAYS:'14', APP_TIMEZONE:'UTC', AIMS_COMPANY_TIMEZONE:'Europe/Budapest', APP_LOCALE:'en',
    LARAVEL_STORAGE_PATH:path.join(work,'storage'), AIMS_DOCUMENT_ROOT:path.join(work,'documents'), AIMS_BACKUP_ROOT:path.join(work,'backups'), AIMS_BACKUP_SCHEDULE:'manual', AIMS_MAIL_ENABLED:'false', APP_CONFIG_CACHE:path.join(work,'config.php'),
    BROADCAST_CONNECTION: 'log', MAIL_MAILER: 'array',
    TRACKING_EXTERNAL_ENABLED: 'false', PHPRC: portablePhpIni(), PHP_INI_SCAN_DIR: '',
    AIMS_DESKTOP_RECOVERY_PASSWORD: recoveryPassword,
  }
  const setup = spawnSync(php, ['artisan', 'aims:desktop-setup'], {
    cwd: backend, env, encoding: 'utf8', windowsHide: true, timeout: 120000,
  })
  if (setup.status !== 0) fail((setup.stderr || setup.stdout || 'database initialization failed').trim())
  const check = spawnSync(php,['artisan','aims:production-check','--before-start','--json'],{cwd:backend,env,encoding:'utf8',windowsHide:true,timeout:60000})
  if(check.status!==0) fail(`production dependencies: ${check.stdout || check.stderr}`)
  supervisor = createSupervisor(path.join(work,'processes'))
  for (const queue of ['default','supply-optimizer','strategic-simulation']) {
    const worker=spawnSync(php,['artisan','aims:work',queue,'--once'],{cwd:backend,env,encoding:'utf8',windowsHide:true,timeout:60000})
    if(worker.status!==0)fail(`${queue} worker failed: ${worker.stderr || worker.stdout}`)
  }
  const scheduler=spawnSync(php,['artisan','schedule:run'],{cwd:backend,env,encoding:'utf8',windowsHide:true,timeout:60000})
  if(scheduler.status!==0)fail('scheduler failed')
  const fullCheck=spawnSync(php,['artisan','aims:production-check','--json'],{cwd:backend,env,encoding:'utf8',windowsHide:true,timeout:60000})
  if(fullCheck.status!==0)fail(`worker readiness: ${fullCheck.stdout || fullCheck.stderr}`)
  const startServer = () => {
  server = supervisor.spawn(php, ['artisan', 'serve', '--no-reload', '--host=127.0.0.1', `--port=${port}`], {
    cwd: backend, env, windowsHide: true, shell: false, stdio: ['ignore', 'pipe', 'pipe'],
  })
  server.stdout.on('data', (chunk) => { serverOutput += chunk.toString() })
  server.stderr.on('data', (chunk) => { serverOutput += chunk.toString() })
  }
  startServer()
  const status = JSON.parse(await waitForBackend())
  if (!status || status.status !== 'ok') fail('the bundled API returned an invalid health response')
  const login=JSON.parse(await requestStatus('/api/login',{email:'aimsadmin@company.com',password:recoveryPassword}))
  if(!login.access_token || !login.user?.must_change_password)fail('unique recovery login/mandatory password change failed')
  const permanentPassword=require('node:crypto').randomBytes(24).toString('base64url')
  const changed=JSON.parse(await requestStatus('/api/change-password',{password:permanentPassword,password_confirmation:permanentPassword},login.access_token))
  if(changed.user?.must_change_password)fail('mandatory password change did not complete')
  const ready=JSON.parse(await requestStatus('/api/readiness'))
  if(ready.status!=='ready')fail('production readiness with local worker heartbeats failed')
  const priorPid=server.pid
  spawnSync('taskkill.exe',['/pid',String(priorPid),'/t','/f'],{windowsHide:true,stdio:'ignore'})
  await new Promise(resolve=>server.once('close',resolve))
  startServer();await waitForBackend()
  const restart=spawnSync(php,['artisan','aims:desktop-setup'],{cwd:backend,env,encoding:'utf8',windowsHide:true,timeout:120000})
  if(restart.status!==0)fail('idempotent desktop restart failed')
  const loginAfter=JSON.parse(await requestStatus('/api/login',{email:'aimsadmin@company.com',password:permanentPassword}))
  if(!loginAfter.access_token)fail('recovery password was overwritten on restart')
  process.stdout.write('AIMS desktop startup certification passed: bundled production dependencies, local assets/Python, fresh SQLite, recovery login, three workers/scheduler, backend crash/restart and idempotent upgrade.\n')
}

main().catch((error) => {
  process.stderr.write(`${error.message}\n`)
  process.exitCode = 1
}).finally(() => {
  if(supervisor)supervisor.shutdown()
  if (server && !server.killed) {
    if (process.platform === 'win32') spawnSync('taskkill.exe', ['/pid', String(server.pid), '/t', '/f'], { windowsHide: true, stdio: 'ignore' })
    else server.kill('SIGTERM')
  }
  fs.rmSync(work, { recursive: true, force: true })
})
