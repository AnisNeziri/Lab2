import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

let echoInstance = null

export function getEcho() {
  return echoInstance
}

export function initEcho(token, companyId) {
  if (!token || !companyId) {
    return null
  }

  if (echoInstance) {
    echoInstance.disconnect()
    echoInstance = null
  }

  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'
  const host = import.meta.env.VITE_REVERB_HOST || '127.0.0.1'
  const port = Number(import.meta.env.VITE_REVERB_PORT || 8080)
  const key = import.meta.env.VITE_REVERB_APP_KEY || 'aims-key'

  Pusher.logToConsole = import.meta.env.DEV

  echoInstance = new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
        Accept: 'application/json',
      },
    },
  })

  echoInstance.connector.pusher.connection.bind('connected', () => {
    if (import.meta.env.DEV) {
      console.info('[Echo] Connected to Reverb')
    }
  })

  echoInstance.connector.pusher.connection.bind('error', (error) => {
    console.error('[Echo] Connection error', error)
  })

  return echoInstance
}

export function disconnectEcho() {
  if (echoInstance) {
    echoInstance.disconnect()
    echoInstance = null
  }
}
