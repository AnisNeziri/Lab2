export function companyDate(timeZone = 'UTC', instant = new Date()) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(instant)
  const date = Object.fromEntries(parts.map(part => [part.type, part.value]))
  return `${date.year}-${date.month}-${date.day}`
}

export function addCalendarDays(day, days) {
  const date = new Date(`${day}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + Number(days || 0))
  return date.toISOString().slice(0, 10)
}
