// Only known server tool fields can become UI. No model-authored markup or charts.
const text=(en,sq,al)=>sq?al:en
export function answerView(reply,sq=false){
 const cards=reply.cards||[], first=cards[0], e=first?.evidence||{}, tool=first?.tool
 const fields=(pairs,unit='')=>pairs.map(([label,value])=>({label,value,unit})).filter(f=>f.value!==undefined&&f.value!==null)
 const sales=reply.presentation
 if(sales?.kind==='sales'&&sales.basis==='operational_sales'&&sales.total!=null)return {
  title:text('Sales',sq,'Shitjet'),kind:'sales',headline:text('Operational sales',sq,'Shitjet operative'),value:sales.total,unit:sales.currency,
  period:sales.period?`${sales.period.from} — ${sales.period.to}`:null,meaning:text('Recorded sales activity. This is not booked revenue or profit.',sq,'Aktiviteti i regjistruar i shitjeve. Kjo nuk është e ardhur ose fitim kontabël.'),facts:[],url:sales.url||'/analytics?area=sales',
 }
 if(tool==='get_product_availability'&&cards.length===1&&e.position&&e.product)return {
  title:e.product.name||first.title,kind:'inventory',headline:text('Available now',sq,'Në dispozicion tani'),value:e.position.available,unit:e.product.unit,
  meaning:reply.meaning||text('Reserved and held stock is excluded from availability.',sq,'Stoku i rezervuar dhe i bllokuar nuk përfshihet në sasinë e disponueshme.'),
  facts:fields([[text('Physical',sq,'Fizik'),e.position.physical],[text('Reserved',sq,'Rezervuar'),e.position.reserved],[text('Picking',sq,'Në përgatitje'),e.position.allocated],[text('Incoming',sq,'Në ardhje'),e.position.incoming]],e.product.unit),url:first.url,
 }
 if(tool==='get_sales_analytics'&&e.metrics?.revenue!=null)return {
  title:text('Sales',sq,'Shitjet'),kind:'sales',headline:text('Operational sales',sq,'Shitjet operative'),value:e.metrics.revenue,unit:e.currency||first.currency||'',
  period:e.period?`${e.period.from} — ${e.period.to}`:null,meaning:text('Recorded sales activity for this period. This is not booked revenue or profit.',sq,'Aktiviteti i shitjeve në këtë periudhë. Kjo nuk është e ardhur ose fitim kontabël.'),facts:[],url:first.url,
 }
 if(tool==='get_customer'&&cards.length===1&&e.credit)return {
  title:first.title,kind:'customer',headline:text('Outstanding balance',sq,'Borxhi i pashlyer'),value:e.credit.current_debt,unit:first.currency||'',
  meaning:text('Current debt, advances and order commitments are shown separately.',sq,'Borxhi aktual, paradhëniet dhe angazhimet e porosive shfaqen veçmas.'),
  facts:fields([[text('Overdue',sq,'Me vonesë'),e.credit.overdue],[text('Advances',sq,'Paradhënie'),e.credit.advance],[text('Order commitments',sq,'Angazhimet e porosive'),e.credit.committed_exposure],[text('Credit limit',sq,'Kufiri i kredisë'),e.credit.credit_limit]],first.currency),url:first.url,
 }
 if(tool==='get_inventory_plan'&&e.plan){
  const qualified=e.plan.coverage_supported===true&&e.plan.feasible===true&&e.plan.base_quantity!=null
  return {kind:'recommendation',title:text('Replenishment review',sq,'Shqyrtimi i furnizimit'),headline:qualified?text('Suggested reorder quantity',sq,'Sasia e sugjeruar për riporosi'):text('A reliable reorder quantity is not available yet',sq,'Ende nuk ka sasi të besueshme për riporosi'),value:qualified?e.plan.base_quantity:null,unit:e.plan.unit,
   meaning:qualified?text('Review the source evidence before preparing a purchase request.',sq,'Shqyrto dëshmitë para përgatitjes së kërkesës për blerje.'):text('Review demand evidence and supplier lead time in planning.',sq,'Shqyrto kërkesën dhe afatin e furnitorit në planifikim.'),facts:[],url:first.url}
 }
 return {kind:cards.length?'summary':'plain',title:null,headline:reply.text,meaning:reply.meaning||null,facts:[],url:reply.next_action?.url||first?.url}
}
