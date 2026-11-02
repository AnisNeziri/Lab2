const labels = {
 purchasing:['Purchasing','Blerjet'],warehouse:['Warehouse','Depoja'],finance:['Finance','Financat'],management:['Management','Menaxhmenti'],hr:['HR','Burimet njerëzore'],admin:['Administrator','Administrator'],accountant:['Accountant','Kontabilist'],read_only:['Read only','Vetëm lexim'],staff:['Staff','Stafi'],
  core: ['Core workspace', 'Hapësira bazë'], inventory: ['Inventory', 'Inventari'], wms: ['Warehouse operations', 'Operacionet e depos'], sales: ['Sales', 'Shitjet'], crm: ['Customers', 'Klientët'], procurement: ['Purchasing', 'Blerjet'], shipments: ['Shipments', 'Dërgesat'], accounting: ['Accounting', 'Kontabiliteti'], tax: ['Tax & fiscalization', 'Tatimet dhe fiskalizimi'], workforce: ['Workforce', 'Personeli'], payroll: ['Payroll', 'Pagat'], intelligence: ['Intelligence', 'Inteligjenca'], automation: ['Automation', 'Automatizimi'], integrations: ['Integrations', 'Integrimet'], mcp: ['MCP access', 'Qasja MCP'], analytics: ['Reports & analytics', 'Raportet dhe analizat'],
  platform: ['AIMS Platform', 'Platforma AIMS'], distribution: ['Distribution', 'Distribucioni'], 'core-accounting': ['Core Accounting', 'Kontabiliteti bazë'],
  encryption_key: ['Installation protection', 'Mbrojtja e instalimit'], database: ['Database', 'Baza e të dhënave'], migrations: ['Database updates', 'Përditësimet e bazës'], default: ['Background operations', 'Operacionet në prapavijë'], 'supply-optimizer': ['Supply planning worker', 'Përpunimi i furnizimit'], 'strategic-simulation': ['Scenario worker', 'Përpunimi i skenarëve'], scheduler: ['Scheduled operations', 'Operacionet e planifikuara'], storage: ['File storage', 'Ruajtja e skedarëve'], redis: ['Optional cache', 'Memoria opsionale'], mail: ['Email delivery', 'Dërgimi i emailit'], reverb: ['Live updates', 'Përditësimet në kohë reale'], backup: ['Verified backup', 'Kopja rezervë e verifikuar'], observability: ['Technical diagnostics', 'Diagnostikimi teknik'], license: ['Company license', 'Licenca e kompanisë'], onboarding: ['Company opening', 'Hapja e kompanisë'], configuration: ['Deployment configuration', 'Konfigurimi i instalimit'], fiscalization: ['Fiscalization readiness', 'Gatishmëria e fiskalizimit'],
}
export function administrationLabel(value, language = 'en') {
  return labels[value]?.[language === 'sq' ? 1 : 0] || String(value || '—').replaceAll('_', ' ')
}
export function administrationDate(value, language = 'en') {
  if (!value) return '—'
  const date = new Date(String(value).includes('T') ? value : String(value).replace(' ', 'T') + 'Z')
  return Number.isNaN(date.valueOf()) ? value : new Intl.DateTimeFormat(language === 'sq' ? 'sq-AL' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

const messagesSq = {
 'Installation key evidence verified.':'Mbrojtja e çelësit të instalimit u verifikua.',
 'The original encryption key or installation evidence is missing. Do not generate a new key.':'Mungon çelësi origjinal ose dëshmia e instalimit. Mos krijo çelës të ri.',
 'Database connected.':'Baza e të dhënave është e lidhur.',
 'Database connection failed.':'Lidhja me bazën e të dhënave dështoi.',
 'Schema is current.':'Struktura e bazës së të dhënave është e përditësuar.',
 'Current release heartbeat received.':'Procesi konfirmoi aktivitetin me versionin aktual.',
 'Worker/scheduler heartbeat missing or stale; start the supervised process.':'Mungon sinjali i fundit i procesit. Nis procesin e mbikëqyrur në instalim.',
 'Storage writable.':'Ruajtja e skedarëve është e disponueshme.',
 'Storage is not writable.':'Ruajtja e skedarëve nuk lejon shkrim.',
 'Redis reachable.':'Memoria Redis është e qasshme.',
 'Redis unavailable.':'Memoria Redis nuk është e qasshme.',
 'Optional Redis is disabled for this installation.':'Memoria opsionale Redis është e çaktivizuar në këtë instalim.',
 'Mail is configured; delivery is not probed or sent by this page.':'Emaili është konfiguruar. Kjo faqe nuk provon ose dërgon email.',
 'Email recovery requires the existing mail integration.':'Rikuperimi me email kërkon konfigurimin e shërbimit të postës.',
 'Existing Reverb transport retained; network delivery is not inferred from configuration.':'Përdoret transporti ekzistues Reverb. Konfigurimi nuk provon dërgimin në rrjet.',
 'A verified archive checkpoint is recorded. Reverify before an update.':'Është regjistruar një arkiv i verifikuar. Verifikoje sërish para përditësimit.',
 'No authenticated archive checkpoint is recorded.':'Nuk është regjistruar kopje rezervë e verifikuar.',
 'Integration API enabled; scoped credentials required.':'API e integrimeve është aktive. Kërkohen kredenciale me leje të kufizuara.',
 'Integration API disabled.':'API e integrimeve është e çaktivizuar.',
 'Local sanitized tracing enabled.':'Diagnostikimi teknik lokal është aktiv.',
 'Local tracing disabled by company policy.':'Diagnostikimi lokal është çaktivizuar nga politika e kompanisë.',
 'Operational cutover configured.':'Kalimi në operim është konfiguruar.',
 'Complete company opening in the existing onboarding workspace.':'Përfundo hapjen e kompanisë në hapësirën ekzistuese të konfigurimit fillestar.',
 'Accounting start date configured.':'Data e fillimit të kontabilitetit është konfiguruar.',
 'Review the accounting setup and opening balances.':'Shqyrto konfigurimin e kontabilitetit dhe bilancet fillestare.',
 'Review the existing fiscalization test/production boundary if this capability is used.':'Shqyrto kufirin e testimit dhe prodhimit nëse përdoret fiskalizimi.',
 'Run aims:production-check before commercial deployment; development configuration is not certification.':'Kontrollo gatishmërinë para instalimit komercial. Konfigurimi i zhvillimit nuk është certifikim.',
 'Configure verified email recovery or a protected installation-owner recovery key before requiring passkeys.':'Konfiguro rikuperimin me email të verifikuar ose çelësin e mbrojtur të pronarit para se të kërkosh passkeys.',
 'A privileged account has only password authentication. Add a passkey and keep a recovery method.':'Një llogari me privilegje përdor vetëm fjalëkalim. Shto një passkey dhe mbaj një mënyrë rikuperimi.',
 'MCP is enabled without a current service credential. Disable it if unused.':'MCP është aktiv pa kredencial shërbimi të vlefshëm. Çaktivizoje nëse nuk përdoret.',
 'This user can prepare, approve and pay payroll. A second reviewer is recommended.':'Ky përdorues mund të përgatisë, miratojë dhe paguajë pagat. Rekomandohet një shqyrtues i dytë.',
 'This user can approve supplier bills and record supplier payments. Use independent review.':'Ky përdorues mund të miratojë faturat dhe të regjistrojë pagesat e furnitorëve. Përdor shqyrtim të pavarur.',
 'Integration administration and financial posting are combined. Review this responsibility.':'Administrimi i integrimeve dhe postimi financiar janë bashkuar. Shqyrto ndarjen e përgjegjësive.',
}
export function administrationMessage(value, language = 'en') {
 if (language !== 'sq') return value
 if (messagesSq[value]) return messagesSq[value]
 const stale = /^(\d+) active service credentials have not been used for 90 days\. Review or revoke them\.$/.exec(value)
 if (stale) return `${stale[1]} kredenciale aktive nuk janë përdorur për 90 ditë. Shqyrtoji ose revokoji.`
 if (String(value).startsWith('License/package state:')) return 'Konfigurimi manual i paketës nuk është licencë komerciale e nënshkruar nga lëshuesi.'
 return value
}
