const {test} = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const {execFileSync} = require('node:child_process')

test('Windows Python executable aliases are bundled as independent files', {skip: process.platform !== 'win32', timeout: 60000}, t => {
  const desktop = path.resolve(__dirname, '..')
  const runtime = path.join(desktop, 'resources/python')
  if (!fs.existsSync(path.join(runtime, 'python.exe'))) {
    assert.ok(!process.env.CI, 'Run desktop asset preparation before CI packaging tests')
    t.skip('Prepared Windows Python runtime is required')
    return
  }
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'aims-python-bundle-'))
  try {
    const source = path.join(fixture, 'source')
    fs.mkdirSync(source)
    for (const name of fs.readdirSync(runtime)) {
      const file = path.join(runtime, name)
      if (fs.statSync(file).isFile()) fs.copyFileSync(file, path.join(source, name))
    }
    for (const name of ['Lib', 'DLLs']) {
      fs.symlinkSync(path.join(runtime, name), path.join(source, name), 'junction')
    }
    const alias = path.join(source, 'python3.exe')
    // The fixture must contain an actual symlink, as in the hosted toolchain.
    if (fs.existsSync(alias)) fs.unlinkSync(alias)
    try {
      fs.symlinkSync(path.join(source, 'python.exe'), alias, 'file')
    } catch (error) {
      if (error.code !== 'EPERM' || process.env.CI) throw error
      t.skip('Creating executable symlinks requires Windows developer mode or elevation')
      return
    }
    assert.ok(fs.lstatSync(alias).isSymbolicLink())
    const script = path.join(fixture, 'desktop/scripts/prepare-python.cjs')
    fs.mkdirSync(path.dirname(script), {recursive: true})
    fs.copyFileSync(path.join(desktop, 'scripts/prepare-python.cjs'), script)
    const vendor = path.join(fixture, 'backend/ml/optimizer_vendor')
    fs.mkdirSync(path.dirname(vendor), {recursive: true})
    fs.cpSync(path.resolve(desktop, '../backend/ml/optimizer_vendor'), vendor, {recursive: true})
    execFileSync(process.execPath, [script], {env: {...process.env, AIMS_PYTHON_SOURCE: source}, windowsHide: true})
    const bundled = path.join(fixture, 'desktop/resources/python/python3.exe')
    assert.ok(fs.lstatSync(bundled).isFile())
    assert.equal(fs.lstatSync(bundled).isSymbolicLink(), false)
    assert.deepEqual(fs.readFileSync(bundled), fs.readFileSync(path.join(source, 'python.exe')))
    const version = execFileSync(bundled, ['-I', '-c', 'import sys; from scipy.optimize import milp; print(sys.version.split()[0])'], {encoding: 'utf8', windowsHide: true}).trim()
    assert.equal(version, '3.12.2')
    assert.ok(fs.lstatSync(alias).isSymbolicLink(), 'The source alias must remain untouched')
  } finally {
    const parent = path.resolve(os.tmpdir()) + path.sep
    assert.ok(path.resolve(fixture).startsWith(parent), 'Cleanup must stay inside the temporary directory')
    fs.rmSync(fixture, {recursive: true, force: true})
  }
})
