const crypto = require('crypto')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { LICENSE_SCHEMA, PRODUCT_NAME, machineFingerprint } = require('../../desktop/src/license.cjs')

function argument(name, fallback = '') {
  const index = process.argv.indexOf(`--${name}`)
  return index >= 0 ? process.argv[index + 1] : fallback
}

const customer = argument('customer')
const requestedMachine = argument('machine')
const output = argument('output')
const edition = argument('edition', 'commercial')
const expires = argument('expires', 'never')

if (! customer || ! requestedMachine || ! output) {
  console.error('Usage: node generate-license.cjs --customer "Company" --machine MACHINE-ID|current --output company.aims-license [--edition commercial] [--expires YYYY-MM-DD|never]')
  process.exit(1)
}

const machineId = requestedMachine.toLowerCase() === 'current' ? machineFingerprint() : requestedMachine.toUpperCase()
const privateKeyPath = process.env.AIMS_LICENSE_PRIVATE_KEY || path.join(os.homedir(), '.aims-license-authority', 'private.pem')
const passphrasePath = path.join(path.dirname(privateKeyPath), 'passphrase.txt')
if (! fs.existsSync(privateKeyPath)) {
  console.error('The private licence authority key is missing. Run create-authority.cjs first.')
  process.exit(1)
}

const issuedAt = new Date().toISOString()
const payload = {
  schema: LICENSE_SCHEMA,
  product: PRODUCT_NAME,
  license_id: `AIMS-${crypto.randomUUID().toUpperCase()}`,
  customer,
  machine_id: machineId,
  edition,
  features: [
    'inventory',
    'warehouses',
    'warehouse-transfers',
    'unit-conversions',
    'sales',
    'finance',
    'invoices',
    'profit-analysis',
    'debts',
    'purchase-orders',
    'shipments',
    'reports',
  ],
  issued_at: issuedAt,
  not_before: issuedAt,
  expires_at: expires.toLowerCase() === 'never' ? null : new Date(`${expires}T23:59:59.999Z`).toISOString(),
}
const payloadBytes = Buffer.from(JSON.stringify(payload), 'utf8')
const privateKey = crypto.createPrivateKey({
  key: fs.readFileSync(privateKeyPath, 'utf8'),
  format: 'pem',
  passphrase: process.env.AIMS_LICENSE_KEY_PASSWORD || fs.readFileSync(passphrasePath, 'utf8').trim(),
})
const signature = crypto.sign('RSA-SHA256', payloadBytes, privateKey)
const document = {
  payload: payloadBytes.toString('base64url'),
  signature: signature.toString('base64'),
}

const outputPath = path.resolve(output)
fs.mkdirSync(path.dirname(outputPath), { recursive: true })
fs.writeFileSync(outputPath, JSON.stringify(document, null, 2), { encoding: 'utf8', mode: 0o600 })
console.log(`Licence created: ${outputPath}`)
console.log(`Customer: ${customer}`)
console.log(`Machine ID: ${machineId}`)
console.log(`Licence ID: ${payload.license_id}`)
