const crypto = require('crypto')
const fs = require('fs')
const os = require('os')
const path = require('path')

const workspace = path.resolve(__dirname, '..', '..')
const authorityDirectory = path.join(os.homedir(), '.aims-license-authority')
const privateKeyPath = path.join(authorityDirectory, 'private.pem')
const authorityPublicKeyPath = path.join(authorityDirectory, 'public.pem')
const passphrasePath = path.join(authorityDirectory, 'passphrase.txt')
const applicationPublicKeyPath = path.join(workspace, 'desktop', 'build', 'license-public.pem')

fs.mkdirSync(authorityDirectory, { recursive: true })
fs.mkdirSync(path.dirname(applicationPublicKeyPath), { recursive: true })
if (! fs.existsSync(passphrasePath)) {
  fs.writeFileSync(passphrasePath, crypto.randomBytes(48).toString('base64url'), { encoding: 'utf8', mode: 0o600 })
}
const passphrase = process.env.AIMS_LICENSE_KEY_PASSWORD || fs.readFileSync(passphrasePath, 'utf8').trim()

if (! fs.existsSync(privateKeyPath) || ! fs.existsSync(authorityPublicKeyPath)) {
  const { privateKey, publicKey } = crypto.generateKeyPairSync('rsa', {
    modulusLength: 4096,
    publicKeyEncoding: { type: 'spki', format: 'pem' },
    privateKeyEncoding: {
      type: 'pkcs8',
      format: 'pem',
      cipher: 'aes-256-cbc',
      passphrase,
    },
  })
  fs.writeFileSync(privateKeyPath, privateKey, { encoding: 'utf8', mode: 0o600 })
  fs.writeFileSync(authorityPublicKeyPath, publicKey, { encoding: 'utf8', mode: 0o644 })
}

fs.copyFileSync(authorityPublicKeyPath, applicationPublicKeyPath)
const fingerprint = crypto.createHash('sha256')
  .update(fs.readFileSync(authorityPublicKeyPath))
  .digest('hex')
  .toUpperCase()
  .match(/.{1,8}/g)
  .join(':')

console.log(`AIMS licence authority is ready.`)
console.log(`Private key: ${privateKeyPath}`)
console.log(`Public key fingerprint: ${fingerprint}`)
