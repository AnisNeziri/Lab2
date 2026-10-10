export const businessLocale = language => language === 'sq' ? 'sq-AL' : 'en-GB'
const known = value => value !== null && value !== undefined && value !== '' && Number.isFinite(Number(value))
export function businessNumber(value, language = 'en', decimals = 3) {
  return known(value) ? new Intl.NumberFormat(businessLocale(language), { maximumFractionDigits: decimals }).format(Number(value)) : '—'
}
export function businessMoney(value, currency = 'EUR', language = 'en') {
  if (!known(value)) return '—'
  const number = new Intl.NumberFormat(businessLocale(language), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))
  return currency ? `${number} ${currency}` : number
}
export function businessDate(value, language = 'en', withTime = false) {
  if (!value) return '—'
  const input = !withTime && /^\d{4}-\d{2}-\d{2}(?:T|$)/.test(String(value)) ? String(value).slice(0,10) : value
  const date = new Date(/^\d{4}-\d{2}-\d{2}$/.test(String(input)) ? `${input}T12:00:00` : input)
  if (Number.isNaN(date.getTime())) return '—'
  const locale = businessLocale(language)
  return date.toLocaleDateString(locale, { day: 'numeric', month: 'short', year: 'numeric' }) + (withTime ? ` · ${date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', hour12: false })}` : '')
}
