const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto')
const root = path.resolve(__dirname, '..'), release = JSON.parse(fs.readFileSync(path.join(root, 'RELEASE.json'), 'utf8'))
const hash = filename => crypto.createHash('sha256').update(fs.readFileSync(filename)).digest('hex')
const components = [], inputs = []
const composerFile = path.join(root, 'backend/composer.lock'), composer = JSON.parse(fs.readFileSync(composerFile, 'utf8'))
inputs.push({ path: 'backend/composer.lock', sha256: hash(composerFile) })
for (const [scope, packages] of [['runtime', composer.packages], ['development', composer['packages-dev']]]) for (const p of packages || []) components.push({ ecosystem: 'composer', workspace: 'backend', name: p.name, version: p.version, scope, license: p.license || [], source_revision: p.source?.reference || null })
for (const workspace of ['frontend', 'desktop']) {
  const filename = path.join(root, workspace, 'package-lock.json'), lock = JSON.parse(fs.readFileSync(filename, 'utf8'))
  inputs.push({ path: `${workspace}/package-lock.json`, sha256: hash(filename) })
  for (const [location, p] of Object.entries(lock.packages || {})) if (location && p.version) components.push({ ecosystem: 'npm', workspace, name: p.name || location.split('node_modules/').at(-1), version: p.version, scope: p.dev ? 'development' : 'runtime', integrity: p.integrity || null, license: p.license || null })
}
const pnpmFile = path.join(root, 'frontend/pnpm-lock.yaml')
if (fs.existsSync(pnpmFile)) inputs.push({ path: 'frontend/pnpm-lock.yaml', sha256: hash(pnpmFile) })
const requirements = 'backend/ml/supply_optimizer_requirements.txt'
inputs.push({ path: requirements, sha256: hash(path.join(root, requirements)) })
for (const line of fs.readFileSync(path.join(root, requirements), 'utf8').split(/\r?\n/)) {
  const match = line.match(/^([a-zA-Z0-9_-]+)==([^\s#]+)/)
  if (match) components.push({ ecosystem: 'python', workspace: 'local-optimizer', name: match[1], version: match[2], scope: 'runtime', evidence: 'pinned requirements' })
}
const document = { format: 'aims-dependency-inventory-v1', generated_at: new Date().toISOString(), application: { version: release.version, build_id: release.build_id, commit: release.commit || null }, inputs, native_runtime_requirements: release.toolchain, components, limitations: ['Inventory, not a vulnerability scan or certification.', 'Python records are declared pins; native/system libraries require host-specific inventory.', 'Run ecosystem audit commands separately and retain their results.'] }
const output = path.resolve(process.argv[2] || path.join(root, 'output/dependency-inventory.json'))
fs.mkdirSync(path.dirname(output), { recursive: true });fs.writeFileSync(output, JSON.stringify(document, null, 2) + '\n')
console.log(JSON.stringify({ file: path.relative(root, output), components: components.length, inputs: inputs.length }))
