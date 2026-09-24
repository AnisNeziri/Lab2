const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

// The script lives at <workspace>/desktop/scripts, so two levels up is the
// workspace root used by both the web and desktop builds.
const root = path.resolve(__dirname, '..', '..')
const desktop = path.join(root, 'desktop')
const backendSource = path.join(root, 'backend')
const frontendRoot = path.join(root, 'frontend')
const frontendSource = path.join(root, 'frontend', 'dist')

function buildFrontend() {
  if (!fs.existsSync(path.join(frontendRoot, 'package.json'))) {
    throw new Error(`Frontend package metadata was not found: ${frontendRoot}`)
  }
  const vite = path.join(frontendRoot, 'node_modules', 'vite', 'bin', 'vite.js')
  if (!fs.existsSync(vite)) {
    throw new Error('Frontend build dependencies are missing. Run npm install in the frontend folder first.')
  }

  // Always compile the current source before copying desktop resources. This
  // prevents a stale dist folder from silently shipping without new CSS/JS
  // animations and does not depend on Docker, Redis, XAMPP, or a web server.
  execFileSync(process.execPath, [vite, 'build'], {
    cwd: frontendRoot,
    env: process.env,
    stdio: 'inherit',
    windowsHide: true,
  })
}

function validateFrontendBundle() {
  const indexFile = path.join(frontendSource, 'index.html')
  if (!fs.existsSync(indexFile)) throw new Error('The frontend build did not create dist/index.html.')

  const index = fs.readFileSync(indexFile, 'utf8')
  if (/(?:src|href)=["']https?:\/\//i.test(index)) {
    throw new Error('The desktop entry page contains a remote script or stylesheet and is not offline-safe.')
  }

  const localReferences = [...index.matchAll(/(?:src|href)=["']\/([^"'?#]+)/gi)]
    .map((match) => decodeURIComponent(match[1]))
  for (const reference of localReferences) {
    if (!fs.existsSync(path.join(frontendSource, reference))) {
      throw new Error(`The desktop frontend is missing a referenced local asset: /${reference}`)
    }
  }

  const assetsDirectory = path.join(frontendSource, 'assets')
  if (!fs.existsSync(assetsDirectory)) throw new Error('The frontend build did not create its local assets directory.')
  for (const file of fs.readdirSync(assetsDirectory)) {
    if (!file.endsWith('.css')) continue
    const css = fs.readFileSync(path.join(assetsDirectory, file), 'utf8')
    if (/url\(\s*["']?https?:\/\//i.test(css)) {
      throw new Error(`The desktop stylesheet ${file} contains a remote asset URL.`)
    }
  }
}

buildFrontend()
validateFrontendBundle()

function findPhpSource() {
  const candidates = [
    process.env.AIMS_PHP_SOURCE,
    path.join(desktop, 'runtime', 'php'),
    process.env.PHP_HOME,
  ].filter(Boolean)
  for (const candidate of candidates) if (fs.existsSync(path.join(candidate, 'php.exe'))) return candidate
  try {
    const discovered = execFileSync('where.exe', ['php'], { encoding: 'utf8' }).split(/\r?\n/).find(Boolean)
    if (discovered) return path.dirname(discovered.trim())
  } catch {
    // Fall through to a clear build-time error.
  }
  throw new Error('A portable PHP runtime was not found. Set AIMS_PHP_SOURCE to a PHP folder before packaging.')
}

const phpSource = findPhpSource()

if (!fs.existsSync(frontendSource)) throw new Error('The frontend build output is missing.')
if (!fs.existsSync(path.join(backendSource, 'vendor', 'autoload.php'))) throw new Error('Laravel vendor dependencies are missing.')
if (!fs.existsSync(path.join(phpSource, 'php.exe'))) throw new Error(`Portable PHP source was not found: ${phpSource}`)

fs.rmSync(path.join(desktop, 'resources'), { recursive: true, force: true })
fs.mkdirSync(path.join(desktop, 'resources'), { recursive: true })
const packagedBackend = path.join(desktop, 'resources', 'backend')

function includeBackendSource(source) {
  const normalizedSource = source.replace(/^\\\\\?\\/, '')
  const relative = path.relative(backendSource, normalizedSource)
  if (!relative) return true

  const parts = relative.split(path.sep)
  const filename = parts.at(-1).toLowerCase()
  const isDirectory = fs.lstatSync(normalizedSource).isDirectory()

  if (parts.includes('.git') || filename === '.env' || filename.startsWith('.env.')) return false
  if (/\.(?:sqlite|sqlite3|db|sql)(?:-(?:wal|shm))?$/i.test(filename)) return false
  if (parts[0].toLowerCase() === 'public' && parts[1]?.toLowerCase() === 'storage') return false
  if (parts[0].toLowerCase() === 'tests' || parts[0].toLowerCase() === 'tmp') return false
  if (filename === '.phpunit.result.cache') return false

  // Ship Laravel's writable directory structure, never the web installation's
  // cache, compiled views, test databases, locks, uploads, or other company
  // runtime data. The desktop app creates and retains its own storage tree.
  if (parts[0].toLowerCase() === 'storage') {
    return isDirectory || filename === '.gitignore'
  }

  // Cached service/config manifests can contain build-machine paths and stale
  // settings. Keep only the directory marker and regenerate them at startup.
  if (parts[0].toLowerCase() === 'bootstrap' && parts[1]?.toLowerCase() === 'cache') {
    return isDirectory || filename === '.gitignore'
  }

  return true
}

fs.cpSync(backendSource, packagedBackend, {
  recursive: true,
  // The web build may contain MySQL-specific cached configuration. Desktop
  // injects its own SQLite path at runtime, so never ship Laravel bootstrap
  // caches into the offline package.
  filter: includeBackendSource,
})
for (const directory of [
  ['bootstrap', 'cache'],
  ['storage', 'app', 'private'],
  ['storage', 'app', 'public'],
  ['storage', 'framework', 'cache', 'data'],
  ['storage', 'framework', 'sessions'],
  ['storage', 'framework', 'views'],
  ['storage', 'logs'],
]) {
  const writableDirectory = path.join(packagedBackend, ...directory)
  fs.mkdirSync(writableDirectory, { recursive: true })
  fs.writeFileSync(path.join(writableDirectory, '.aims-runtime'), '')
}
fs.cpSync(frontendSource, path.join(desktop, 'resources', 'frontend'), { recursive: true })
const phpTarget = path.join(desktop, 'runtime', 'php')
const packagedPhp = path.join(desktop, 'resources', 'php')
fs.rmSync(packagedPhp, { recursive: true, force: true })
// A full XAMPP PHP tree contains documentation, tests, PEAR, duplicate PHP
// runtimes, and development tools. Ship only the interpreter's root runtime
// files plus extensions; this materially reduces the installer and attack
// surface while retaining everything Laravel needs offline.
fs.cpSync(phpSource, packagedPhp, {
  recursive: true,
  filter: (source) => {
    const normalizedSource = source.replace(/^\\\\\?\\/, '')
    const relative = path.relative(phpSource, normalizedSource)
    if (!relative) return true
    const parts = relative.split(path.sep)
    if (parts[0].toLowerCase() === 'ext') {
      if (parts.length === 1) return true
      const requiredExtensions = new Set([
        'php_bz2.dll', 'php_curl.dll', 'php_exif.dll', 'php_fileinfo.dll',
        'php_ftp.dll', 'php_gettext.dll', 'php_mbstring.dll',
        'php_mysqli.dll', 'php_openssl.dll', 'php_pdo_mysql.dll',
        'php_opcache.dll', 'php_pdo_sqlite.dll', 'php_sodium.dll',
        'php_sqlite3.dll', 'php_zip.dll',
      ])
      return parts.length === 2 && requiredExtensions.has(parts[1].toLowerCase())
    }
    const requiredRuntimeFiles = new Set([
      'php.exe', 'php-win.exe', 'php8ts.dll', 'php.ini',
      'libcrypto-3-x64.dll', 'libssl-3-x64.dll', 'libsqlite3.dll',
      'libsodium.dll', 'libssh2.dll', 'nghttp2.dll', 'zlib1.dll',
    ])
    return parts.length === 1 && !fs.lstatSync(normalizedSource).isDirectory() && requiredRuntimeFiles.has(parts[0].toLowerCase())
  },
})
fs.copyFileSync(path.join(root, 'frontend', 'public', 'aims-logo.svg'), path.join(desktop, 'resources', 'frontend', 'aims-logo.svg'))
fs.copyFileSync(path.join(root, 'frontend', 'public', 'aims-logo.png'), path.join(desktop, 'resources', 'frontend', 'aims-logo.png'))
require('./create-icon.cjs')
