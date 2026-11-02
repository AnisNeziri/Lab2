import { apiRequest } from '../api/client.js'

export function decodeBase64Url(value) {
  const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(binary, c => c.charCodeAt(0)).buffer
}
export function encodeBase64Url(value) {
  return btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
export function browserOptions(options) {
  const result = { ...options, challenge: decodeBase64Url(options.challenge) }
  if (options.user) result.user = { ...options.user, id: decodeBase64Url(options.user.id) }
  for (const key of ['allowCredentials', 'excludeCredentials']) if (options[key]) result[key] = options[key].map(c => ({ ...c, id: decodeBase64Url(c.id) }))
  return result
}
export function credentialPayload(credential, challengeId, name) {
  const r = credential.response, response = { clientDataJSON: encodeBase64Url(r.clientDataJSON) }
  for (const key of ['attestationObject', 'authenticatorData', 'signature', 'userHandle']) if (r[key]) response[key] = encodeBase64Url(r[key])
  if (r.getTransports) response.transports = r.getTransports()
  return { challenge_id: challengeId, rawId: encodeBase64Url(credential.rawId), response, ...(name ? { name } : {}) }
}
export async function usePasskey(purpose, name) {
  if (!window.isSecureContext || !window.PublicKeyCredential) throw new Error('Passkeys require HTTPS or localhost and a supported browser.')
  const prefix = purpose === 'login' ? '/passkeys/login' : `/security/passkeys/${purpose}`
  const data = await apiRequest(`${prefix}/options`, { method: 'POST' })
  const rp = data.publicKey.rp?.id || data.publicKey.rpId
  if (rp === 'localhost' && window.location.hostname !== 'localhost') throw new Error('For local passkeys, open AIMS using localhost instead of 127.0.0.1. Your password remains available here.')
  try {
    const credential = await navigator.credentials[purpose === 'register' ? 'create' : 'get']({ publicKey: browserOptions(data.publicKey) })
    if (!credential) throw new Error('No passkey was selected.')
    return apiRequest(`${prefix}/verify`, { method: 'POST', body: JSON.stringify(credentialPayload(credential, data.challenge_id, name)) })
  } catch (error) {
    if (error.name === 'NotAllowedError') throw new Error('Passkey request cancelled or timed out. Try again or use your password.')
    throw error
  }
}
