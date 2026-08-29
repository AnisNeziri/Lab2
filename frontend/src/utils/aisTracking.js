const LIVE_POSITION_MS = 10 * 60 * 1000
const COVERAGE_WARNING_MS = 24 * 60 * 60 * 1000

function validCoordinate(value, minimum, maximum) {
  const number = Number(value)
  return Number.isFinite(number) && number >= minimum && number <= maximum
}

export function aisPositionState(vessel, at = Date.now()) {
  if (vessel?.position_state) return vessel.position_state
  if (!validCoordinate(vessel?.current_lat, -90, 90) || !validCoordinate(vessel?.current_lng, -180, 180)) {
    return 'waiting_for_data'
  }
  const timestamp = new Date(vessel?.position_updated_at || '').getTime()
  if (!Number.isFinite(timestamp)) return 'last_known'
  const age = Math.max(0, at - timestamp)
  if (age <= LIVE_POSITION_MS) return 'live'
  if (age <= COVERAGE_WARNING_MS) return 'last_known'
  return 'outside_coverage'
}

export function aisPositionLabel(vessel, t) {
  return t(`shipments.aisState.${aisPositionState(vessel)}`)
}

export function relativeAisTime(value, t) {
  if (!value) return '—'
  const timestamp = new Date(value).getTime()
  if (!Number.isFinite(timestamp)) return '—'
  const seconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000))
  if (seconds < 45) return t('shipments.aisTime.justNow')
  if (seconds < 3600) return t('shipments.aisTime.minutesAgo').replace('{count}', String(Math.floor(seconds / 60)))
  if (seconds < 86400) return t('shipments.aisTime.hoursAgo').replace('{count}', String(Math.floor(seconds / 3600)))
  return t('shipments.aisTime.daysAgo').replace('{count}', String(Math.floor(seconds / 86400)))
}

export function navigationStatusLabel(value, t) {
  if (value === null || value === undefined || value === '') return '—'
  const key = `shipments.navigationStatus.${Number(value)}`
  const translated = t(key)
  return translated === key ? String(value) : translated
}

export function aisConnectionLabel(state, t) {
  const key = `shipments.aisConnection.${state || 'starting'}`
  const translated = t(key)
  return translated === key ? t('shipments.aisConnection.starting') : translated
}

export function aisConnectionTone(state) {
  if (state === 'connected') return 'live'
  if (['idle', 'connecting', 'updating_subscription', 'starting'].includes(state)) return 'pending'
  return 'unavailable'
}
