const fs = require('node:fs')
const path = require('node:path')
const { spawn, spawnSync } = require('node:child_process')

function killTree(child) {
  if (!child?.pid || child.exitCode !== null || child.signalCode !== null) return
  if (process.platform === 'win32') spawnSync('taskkill.exe', ['/pid', String(child.pid), '/t', '/f'], { windowsHide: true, stdio: 'ignore' })
  else child.kill('SIGTERM')
}

function createSupervisor(directory) {
  fs.mkdirSync(directory, { recursive: true })
  const registry = path.join(directory, `children-${process.pid}.json`)
  const children = new Set()
  let stopping = false
  const publish = () => {
    if (stopping) return
    const temporary = `${registry}.partial`
    fs.writeFileSync(temporary, JSON.stringify([...children].filter(child => child.pid && child.exitCode === null && child.signalCode === null).map(child => ({ pid: child.pid, startedAfter: child.aimsStartedAfter, startedBefore: child.aimsStartedBefore }))))
    fs.renameSync(temporary, registry)
  }
  publish()
  const watcher = process.platform === 'win32' ? spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', path.join(__dirname, 'process-watchdog.ps1'), '-ParentId', String(process.pid), '-Registry', registry], { windowsHide: true, stdio: 'ignore' }) : null
  if (watcher) watcher.on('error', () => {})
  return {
    spawn(file, args, options) {
      const started = Date.now() - 2000
      const child = spawn(file, args, { ...options, windowsHide: true, shell: false })
      child.aimsStartedAfter = started
      child.aimsStartedBefore = Date.now() + 2000
      children.add(child); publish()
      child.once('close', () => { children.delete(child); publish() })
      return child
    },
    shutdown() {
      stopping = true
      for (const child of children) killTree(child)
      if (watcher) killTree(watcher)
      try { fs.unlinkSync(registry) } catch {}
    },
  }
}

module.exports = { createSupervisor, killTree }
