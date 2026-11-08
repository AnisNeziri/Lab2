export function orderFulfillmentLabel(detail, label, t) {
 const state=detail.states?.fulfillment || detail.order?.status
 if(detail.states?.backordered && ['ready_to_allocate','allocated','partially_allocated'].includes(state))
  return detail.order?.items?.some(i=>Number(i.reserved_quantity)>0)?t('Partially reserved · stock shortage','Rezervuar pjesërisht · mungesë stoku'):t('Awaiting stock','Në pritje të stokut')
 return label(state)
}

export function orderConfirmationMessage(detail,language='en') {
 const sq=language==='sq',items=detail.order?.items
 if(!items?.length)return sq?'Porosia u konfirmua. Kontrolloni disponueshmërinë e stokut.':'Order confirmed. Review stock availability.'
 const groups=new Map()
 for(const i of items){const unit=i.product?.unit||i.unit||'',v=groups.get(unit)||{reserved:0,backordered:0};v.reserved+=Number(i.reserved_quantity)||0;v.backordered+=Number(i.backordered_quantity)||0;groups.set(unit,v)}
 const number=new Intl.NumberFormat(sq?'sq-AL':'en-US',{maximumFractionDigits:3})
 const quantities=key=>[...groups].map(([unit,v])=>`${number.format(v[key])} ${unit}`.trim()).join(', ')
 return sq?`Porosia u konfirmua. Rezervuar: ${quantities('reserved')}. Në pritje stoku: ${quantities('backordered')}.`:`Order confirmed. Reserved: ${quantities('reserved')}. Backordered: ${quantities('backordered')}.`
}
