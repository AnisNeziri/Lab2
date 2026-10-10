import {businessError} from './businessErrors.js'
export function financeWarning(warning,overview={},language='en') {
 const labels={
  missing_tax_profile:['Complete the company invoice and tax profile before preparing VAT.','Plotëso profilin e faturimit dhe tatimeve të kompanisë para përgatitjes së TVSH-së.'],
  missing_expense_proof:[`${overview.expenses_missing_proof_count??'—'} posted expenses need a supporting document.`,`${overview.expenses_missing_proof_count??'—'} shpenzime të regjistruara kanë nevojë për dokument mbështetës.`],
  unclassified_expenses:[`${overview.unclassified_expenses_count??'—'} expenses in Other need classification review.`,`${overview.unclassified_expenses_count??'—'} shpenzime te Të tjera kërkojnë rishikim të kategorisë.`],
  daily_sales_not_in_vat_book:['Daily Sales are shown separately, not in the VAT sales book. Use issued VAT documents for tax preparation.','Shitjet ditore shfaqen veçmas, jo në librin e shitjeve për TVSH. Përdor dokumentet e lëshuara me TVSH për përgatitjen tatimore.'],
  sales_sources_unreconciled:['Invoices and Daily Sales are not added together because they may describe the same sales.','Faturat dhe shitjet ditore nuk mblidhen së bashku, sepse mund të përfaqësojnë të njëjtat shitje.'],
  outflows_unreconciled:['Review purchase-order and expense payments for duplicates before using cash totals externally.','Kontrollo pagesat e porosive dhe shpenzimeve për dublikime para përdorimit të totalit të parasë jashtë sistemit.'],
  landed_cost_cogs_adjustment:['Late import costs for goods already sold affect cost of sales and margin, not cash paid again.','Kostot e importit të regjistruara më vonë për mallrat tashmë të shitura ndikojnë koston e shitjes dhe marzhin, jo një pagesë të re.'],
 }
 return labels[warning?.code]?.[language==='sq'?1:0]||businessError(warning?.message||warning?.label||String(warning),undefined,language)
}
