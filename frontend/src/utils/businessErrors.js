const technical = /SQLSTATE|PDOException|Stack trace|(?:vendor|node_modules)[/\\]|(?:select|insert|update|delete)\s+.+\s+(?:from|into|set)\s|API request failed|Request failed with status code|Unexpected token|fetch failed|Failed to fetch|NetworkError|ECONN|php_network|Traceback|Call to undefined|Undefined (?:array key|variable)|TypeError|file_get_contents|cURL error/i
const salesSq={
 'Pricing changed since this quotation. Prepare a new quotation for customer review before conversion.':'Çmimet kanë ndryshuar nga kjo ofertë. Përgatit një ofertë të re për shqyrtim nga klienti para kthimit në porosi.',
 'Only an accepted, unexpired quotation can become an order.':'Vetëm një ofertë e pranuar dhe e paskaduar mund të kthehet në porosi.',
 'Only an unsent draft quotation can be edited.':'Mund të ndryshohet vetëm një draft oferte që ende nuk është dërguar.',
 'The draft changed. Reopen it and review the current quotation before editing.':'Drafti ka ndryshuar. Hape sërish dhe shqyrto ofertën aktuale para ndryshimit.',
 'This quotation is converted or expired. Create a new reviewed quotation.':'Kjo ofertë është kthyer në porosi ose ka skaduar. Krijo një ofertë të re të shqyrtuar.',
 'This quotation cannot make that transition.':'Ky ndryshim statusi nuk lejohet për ofertën aktuale.',
 'The opportunity belongs to a different customer.':'Mundësia i përket një klienti tjetër.',
 'Choose a customer or enter a prospect name.':'Zgjidh një klient ose shkruaj emrin e prospektit.',
 'Open opportunities require a next action and date.':'Mundësitë e hapura kërkojnë një veprim vijues dhe datën e tij.',
 'Choose an active customer and order channel in company currency.':'Zgjidh një klient aktiv dhe një kanal porosish në valutën e kompanisë.',
 'The customer relationship upgrade requires its reviewed database migration. Existing receivables and order workflows remain available.':'Përmirësimi i marrëdhënieve me klientët kërkon migrimin e shqyrtuar të bazës së të dhënave. Proceset ekzistuese të borxheve dhe porosive mbeten të disponueshme.',
}
export function businessError(message, fallback = 'The request could not be completed. Please try again.', language = 'en') {
  if (!message || technical.test(String(message))) return language === 'sq' ? 'Kërkesa nuk mund të përfundohej. Provo përsëri.' : fallback
  return language==='sq'?(salesSq[String(message)]||String(message)):String(message)
}
export function businessFieldErrors(errors, language='en') {
  if(!errors || typeof errors!=='object')return undefined
  return Object.fromEntries(Object.entries(errors).map(([field,messages])=>[field,
    (Array.isArray(messages)?messages:[messages]).map(message=>businessError(message,undefined,language))
  ]))
}
