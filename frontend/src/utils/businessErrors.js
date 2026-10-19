const technical = /SQLSTATE|PDOException|Stack trace|(?:vendor|node_modules)[/\\]|(?:select|insert|update|delete)\s+.+\s+(?:from|into|set)\s|API request failed|Request failed with status code|Unexpected token|fetch failed|Failed to fetch|NetworkError|ECONN|php_network|Traceback|Call to undefined|Undefined (?:array key|variable)|TypeError|file_get_contents|cURL error/i
export function businessError(message, fallback = 'The request could not be completed. Please try again.', language = 'en') {
  if (!message || technical.test(String(message))) return language === 'sq' ? 'Kërkesa nuk mund të përfundohej. Provo përsëri.' : fallback
  return String(message)
}
export function businessFieldErrors(errors, language='en') {
  if(!errors || typeof errors!=='object')return undefined
  return Object.fromEntries(Object.entries(errors).map(([field,messages])=>[field,
    (Array.isArray(messages)?messages:[messages]).map(message=>businessError(message,undefined,language))
  ]))
}
