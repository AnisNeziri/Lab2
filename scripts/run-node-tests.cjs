const { readdirSync } = require('node:fs')
const { spawnSync } = require('node:child_process')
const path = require('node:path')
const directory = path.resolve(process.cwd(), 'tests')
const files = readdirSync(directory).filter(file => /\.test\.(?:mjs|cjs)$/.test(file)).sort().map(file => path.join(directory, file))
if (!files.length) throw new Error('No test files found')
const result = spawnSync(process.execPath, ['--test', ...files], { stdio: 'inherit', windowsHide: true })
process.exitCode = result.status ?? 1
