const MAX_AGE = 8 * 60 * 60 * 1000
export const sessionKey = (scope, key) => `aims:work-session:${scope}:${key}`
// Full document navigations share React Router's "default" key. Keep those
// positions tied to the actual destination; client history keys stay stable.
export const historyEntryKey = location => location.key === 'default'
  ? `default:${location.pathname}${location.search || ''}`
  : location.key
export function readSession(storage, key, fallback, now = Date.now()) {
  try {
    const saved = JSON.parse(storage?.getItem(key) || 'null')
    return saved && now - saved.at < MAX_AGE && now >= saved.at ? saved.value : fallback
  } catch { return fallback }
}
export function writeSession(storage, key, value, now = Date.now()) {
  try { storage?.setItem(key, JSON.stringify({ at: now, value })) } catch { /* Storage may be unavailable. */ }
}
export function recentDestination(previous, destination) {
  if (!destination?.path?.startsWith('/') || destination.path.startsWith('//')) return previous || []
  return [destination, ...(previous || []).filter(item => item.path !== destination.path)].slice(0, 5)
}
