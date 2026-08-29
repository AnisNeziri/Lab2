const fs = require('fs')
const path = require('path')
const { spawnSync } = require('child_process')

// NSIS is stricter than Electron about ICO files. Generate a conventional
// 256x256 32-bit BMP-backed ICO through Windows Imaging/GDI so the same icon
// works in the executable, installer, shortcuts, and Add/Remove Programs.
const source = path.resolve(__dirname, '..', '..', 'frontend', 'public', 'aims-logo.png')
if (!fs.existsSync(source)) throw new Error(`AIMS logo was not found: ${source}`)

const bmpPath = path.join(__dirname, '..', 'build', '.aims-icon.bmp')
const ps = [
  '$ErrorActionPreference = "Stop";',
  'Add-Type -AssemblyName System.Drawing;',
  `$source = [System.Drawing.Image]::FromFile('${source.replace(/'/g, "''")}');`,
  '$bitmap = New-Object System.Drawing.Bitmap 256,256;',
  '$graphics = [System.Drawing.Graphics]::FromImage($bitmap);',
  '$graphics.DrawImage($source,0,0,256,256);',
  `$bitmap.Save('${bmpPath.replace(/'/g, "''")}',[System.Drawing.Imaging.ImageFormat]::Bmp);`,
  '$graphics.Dispose(); $bitmap.Dispose(); $source.Dispose();',
].join(' ')
const result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', ps], { encoding: 'utf8' })
if (result.status !== 0 || !fs.existsSync(bmpPath)) {
  throw new Error(`Could not convert AIMS logo to an installer icon: ${result.stderr || result.stdout || 'Windows image conversion failed.'}`)
}

const bmp = fs.readFileSync(bmpPath)
try {
  if (bmp.readUInt16LE(0) !== 0x4d42) throw new Error('Generated icon bitmap is invalid.')
  const dibOffset = bmp.readUInt32LE(10)
  const dib = Buffer.from(bmp.subarray(14, dibOffset))
  const pixels = bmp.subarray(dibOffset)
  const width = dib.readInt32LE(4)
  const height = Math.abs(dib.readInt32LE(8))
  if (width !== 256 || height !== 256) throw new Error('Generated icon bitmap must be 256x256.')
  dib.writeInt32LE(height * 2, 8)
  const image = Buffer.concat([dib, pixels])

const header = Buffer.alloc(6)
header.writeUInt16LE(0, 0)
header.writeUInt16LE(1, 2)
header.writeUInt16LE(1, 4)

const entry = Buffer.alloc(16)
entry.writeUInt8(0, 0)
entry.writeUInt8(0, 1)
entry.writeUInt8(0, 2)
entry.writeUInt8(0, 3)
entry.writeUInt16LE(1, 4)
entry.writeUInt16LE(32, 6)
entry.writeUInt32LE(image.length, 8)
entry.writeUInt32LE(header.length + entry.length, 12)

const target = path.join(__dirname, '..', 'build', 'icon.ico')
fs.mkdirSync(path.dirname(target), { recursive: true })
fs.writeFileSync(target, Buffer.concat([header, entry, image]))
} finally {
  fs.rmSync(bmpPath, { force: true })
}
