export function formatOrderMoney(value, currency, language = 'en') {
  if (value == null || value === '' || !Number.isFinite(Number(value))) return '—'
  const locale = language === 'sq' ? 'sq-AL' : 'en-GB'
  const number = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value))
  return currency ? `${number} ${currency}` : number
}

export function formatOrderDate(value, language = 'en') {
  if (!value) return '—'
  const date = new Date(`${String(value).slice(0,10)}T12:00:00`)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString(language === 'sq' ? 'sq-AL' : 'en-GB', { day:'numeric', month:'short', year:'numeric' })
}
