const technical = /SQLSTATE|PDOException|Stack trace|(?:vendor|node_modules)[/\\]|(?:select|insert|update|delete)\s+.+\s+(?:from|into|set)\s|API request failed|Request failed with status code|Unexpected token|fetch failed|Failed to fetch|NetworkError|ECONN|php_network|Traceback|Call to undefined|Undefined (?:array key|variable)|TypeError|file_get_contents|cURL error/i
const messagesSq={
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
 'Use an open annual period that has ended.':'Zgjidh një periudhë vjetore që ka përfunduar dhe është ende e hapur.',
 'The fiscal calendar cannot change after accounting periods have been created.':'Kalendari fiskal nuk mund të ndryshojë pasi të jenë krijuar periudhat kontabël.',
 'The tax jurisdiction must agree with the company invoice profile.':'Juridiksioni tatimor duhet të përputhet me profilin e faturimit të kompanisë.',
 'The evidence must cover the full annual period.':'Dëshmia duhet të mbulojë tërë periudhën vjetore.',
 'The approved treatment must reconcile to current tax in the posted books.':'Trajtimi i miratuar duhet të rakordohet me tatimin aktual të postuar në libra.',
 'Record a current supported actual-income policy before calculating this worksheet.':'Regjistro politikën aktuale të mbështetur për të ardhurat reale para se të llogaritësh fletën.',
 'The ledger or adjustments changed. Run a fresh calculation before approval.':'Libri kryesor ose korrigjimet kanë ndryshuar. Rillogarit fletën para miratimit.',
 'Current-tax journals need accountant reconciliation before approval.':'Kontabilisti duhet të rakordojë ditarët e tatimit aktual para miratimit.',
 'Use an ended, open fiscal year with current foreign monetary-balance review.':'Zgjidh një vit fiskal të përfunduar, ende të hapur, me shqyrtim aktual të gjendjeve në valuta të huaja.',
 'Select a dedicated, compatible posting account in accounting setup. Review prior journals before changing an existing mapping.':'Zgjidh një llogari të dedikuar dhe të përputhshme në konfigurimin kontabël. Shqyrto ditarët e mëparshëm para ndryshimit të lidhjes.',
 'A posted control account cannot be changed without a reviewed balance migration.':'Llogaria e kontrollit me postime kërkon bartjen e shqyrtuar të gjendjeve para ndryshimit.',
}
export function businessError(message, fallback = 'The request could not be completed. Please try again.', language = 'en') {
  if (!message || technical.test(String(message))) return language === 'sq' ? 'Kërkesa nuk mund të përfundohej. Provo përsëri.' : fallback
  return language==='sq'?(messagesSq[String(message)]||String(message)):String(message)
}
export function businessFieldErrors(errors, language='en') {
  if(!errors || typeof errors!=='object')return undefined
  return Object.fromEntries(Object.entries(errors).map(([field,messages])=>[field,
    (Array.isArray(messages)?messages:[messages]).map(message=>businessError(message,undefined,language))
  ]))
}
