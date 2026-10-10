export function attentionPreview(items=[],now=Date.now()) {
 const groups=new Map()
 for(const item of items){const key=JSON.stringify([item.kind,item.title,item.description,item.url]);const previous=groups.get(key);if(previous)previous.occurrences++;else groups.set(key,{...item,occurrences:1})}
 const priority=item=>item.kind==='approval'?0:item.values?.risk==='CRITICAL'?1:item.due_at&&new Date(item.due_at).getTime()<now?2:3
 return [...groups.values()].sort((a,b)=>priority(a)-priority(b))
}
export function attentionDescription(item,language='en') {
 const labels={
  'Review incoming delivery risk':['Review incoming delivery risk','Rishiko rrezikun e furnizimit në ardhje'],
  'Review qualified candidate':['Review a qualified delivery forecast','Rishiko një parashikim të kualifikuar të dorëzimit'],
  'Review enterprise decision':['Review the recommended stock action','Rishiko veprimin e rekomanduar për stokun'],
  'Review warehouse transfer opportunity':['Review stock available in another warehouse','Rishiko stokun në dispozicion në një depo tjetër'],
  'Review inventory purchasing plan':['Review the suggested purchase plan','Rishiko planin e sugjeruar të blerjes'],
  'Review forecast performance and data quality':['Review forecast reliability','Rishiko besueshmërinë e parashikimit'],
  'Review forecast model candidate':['Review a new forecast option','Rishiko një alternativë të re parashikimi'],
  'Review forecast replenishment':['Review restocking needs','Rishiko nevojat për furnizim'],
  'Analytics data-quality problem':['Recorded information needs review','Informacioni i regjistruar kërkon rishikim'],
  'Purchase order overdue':['Purchase order arrival is overdue','Mbërritja e porosisë së blerjes është me vonesë'],
  'Review or replace expiring document':['Review or replace an expiring document','Rishiko ose zëvendëso dokumentin që po skadon'],
  'Customer payment overdue':['Customer payment is overdue','Pagesa e klientit është me vonesë'],
  'Review order':['Review this order before continuing','Rishiko këtë porosi para se të vazhdosh'],
 }
 return labels[item.description]?.[language==='sq'?1:0]||item.description
}
