const crypto = require('crypto')
const { execFileSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const LICENSE_SCHEMA = 1
const PRODUCT_NAME = 'AIMS'

function windowsMachineGuid() {
  if (process.platform !== 'win32') return null

  try {
    const output = execFileSync('reg.exe', [
      'query',
      'HKLM\\SOFTWARE\\Microsoft\\Cryptography',
      '/v',
      'MachineGuid',
    ], {
      encoding: 'utf8',
      windowsHide: true,
      stdio: ['ignore', 'pipe', 'ignore'],
    })
    return output.match(/MachineGuid\s+REG_\w+\s+([^\r\n]+)/i)?.[1]?.trim() || null
  } catch {
    return null
  }
}

function machineFingerprint() {
  const stableId = windowsMachineGuid() || `${os.hostname()}|${os.arch()}`
  return crypto.createHash('sha256')
    .update(`AIMS-LICENCE-V1|${process.platform}|${stableId}`)
    .digest('hex')
    .toUpperCase()
    .match(/.{1,8}/g)
    .join('-')
}

function parseLicenceDocument(contents) {
  const document = JSON.parse(contents)
  if (! document || typeof document.payload !== 'string' || typeof document.signature !== 'string') {
    throw new Error('The selected file is not an AIMS licence.')
  }
  return document
}

function verifyLicenceDocument(document, publicKey, expectedMachineId = machineFingerprint()) {
  const payloadBytes = Buffer.from(document.payload, 'base64url')
  const signature = Buffer.from(document.signature, 'base64')
  const signatureValid = crypto.verify('RSA-SHA256', payloadBytes, publicKey, signature)

  if (! signatureValid) {
    throw new Error('The licence signature is invalid.')
  }

  const payload = JSON.parse(payloadBytes.toString('utf8'))
  if (payload.schema !== LICENSE_SCHEMA || payload.product !== PRODUCT_NAME) {
    throw new Error('This licence was not issued for this version of AIMS.')
  }
  if (payload.machine_id !== expectedMachineId) {
    throw new Error(`This licence belongs to another computer. This computer ID is ${expectedMachineId}.`)
  }

  const now = Date.now()
  if (payload.not_before && new Date(payload.not_before).getTime() > now) {
    throw new Error('This licence is not active yet.')
  }
  if (payload.expires_at && new Date(payload.expires_at).getTime() < now) {
    throw new Error('This AIMS licence has expired.')
  }

  return {
    valid: true,
    machineId: expectedMachineId,
    licenseId: payload.license_id,
    customer: payload.customer,
    edition: payload.edition || 'commercial',
    issuedAt: payload.issued_at,
    expiresAt: payload.expires_at || null,
    features: Array.isArray(payload.features) ? payload.features : [],
  }
}

function readLicence(licencePath, publicKeyPath) {
  const machineId = machineFingerprint()
  if (! fs.existsSync(publicKeyPath)) {
    return { valid: false, machineId, error: 'The AIMS licence verification key is missing.' }
  }
  if (! fs.existsSync(licencePath)) {
    return { valid: false, machineId, error: 'This computer has not been activated.' }
  }

  try {
    const document = parseLicenceDocument(fs.readFileSync(licencePath, 'utf8'))
    const publicKey = fs.readFileSync(publicKeyPath, 'utf8')
    return verifyLicenceDocument(document, publicKey, machineId)
  } catch (error) {
    return { valid: false, machineId, error: error.message }
  }
}

function installLicence(sourcePath, destinationPath, publicKeyPath) {
  const document = parseLicenceDocument(fs.readFileSync(sourcePath, 'utf8'))
  const publicKey = fs.readFileSync(publicKeyPath, 'utf8')
  const status = verifyLicenceDocument(document, publicKey)
  fs.mkdirSync(path.dirname(destinationPath), { recursive: true })

  const temporaryPath = `${destinationPath}.tmp`
  fs.writeFileSync(temporaryPath, JSON.stringify(document, null, 2), { encoding: 'utf8', mode: 0o600 })
  fs.renameSync(temporaryPath, destinationPath)
  return status
}

module.exports = {
  LICENSE_SCHEMA,
  PRODUCT_NAME,
  installLicence,
  machineFingerprint,
  parseLicenceDocument,
  readLicence,
  verifyLicenceDocument,
}
