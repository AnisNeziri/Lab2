import {businessMoney} from './businessFormat.js'
import {businessStatus} from './businessStatus.js'
import {formatQuantity} from './formatQuantity.js'
const labels={
 'Stock received':['Stock received','Stoku u pranua'],'Stock issued':['Stock issued','Stoku doli'],
 Debt:['Debt recorded','Borxhi u regjistrua'],Payment:['Payment recorded','Pagesa u regjistrua'],
 Adjustment:['Balance corrected','Gjendja u korrigjua'],Reversal:['Transaction reversed','Transaksioni u anulua me kundërveprim'],
 Advance:['Customer advance recorded','Parapagimi i klientit u regjistrua'],
 daily_sale:['Daily sale','Shitje ditore'],purchase_receipt:['Purchase receipt','Pranim nga porosia'],
 outbound_dispatch:['Customer dispatch','Dërgim te klienti'],customer_return:['Customer return','Kthim nga klienti'],
 transfer_in:['Transfer received','Transferi u pranua'],transfer_out:['Transfer dispatched','Transferi u nis'],
 adjustment:['Stock correction','Korrigjim stoku'],
}
const translate=(value,language)=>labels[value]?.[language==='sq'?1:0]||value
export function businessActivity(item,language='en') {
 let title=translate(item.title,language),detail=item.detail,technical=null
 if(item.title?.startsWith('Purchase order '))title=(language==='sq'?'Porosia e blerjes ':'Purchase order ')+item.title.slice(15)
 if(item.title?.startsWith('Goods receipt '))title=(language==='sq'?'Pranimi i mallrave ':'Goods receipt ')+item.title.slice(14)
 if(item.kind==='stock_movement'){
  detail=[labels[item.movement_code]?translate(item.movement_code,language):null,`${formatQuantity(item.quantity,item.unit,language)} ${item.unit||''}`,item.reason].filter(Boolean).join(' · ')
  if(item.movement_code&&!labels[item.movement_code])technical=item.movement_code
 }
 if(item.kind==='customer_transaction')detail=[businessMoney(item.amount,item.currency,language),item.note].filter(Boolean).join(' · ')
 if(item.status)detail=businessStatus(item.status,language)
 if(item.event_type){
  const parts=item.event_type.split('.'),verbs={created:['created','u krijua'],updated:['updated','u përditësua'],confirmed:['confirmed','u konfirmua'],cancelled:['cancelled','u anulua'],received:['received','u pranua'],completed:['completed','u përfundua'],paid:['paid','u pagua']},subjects={product:['Product','Produkti'],purchase_order:['Purchase order','Porosia e blerjes'],sales_order:['Order','Porosia'],shipment:['Shipment','Dërgesa'],customer:['Customer','Klienti'],supplier:['Supplier','Furnitori']},index=language==='sq'?1:0
  const subject=subjects[parts[0]],verb=verbs[parts.at(-1)]
  if(subject&&verb)title=`${subject[index]} ${verb[index]}`
  else {title=language==='sq'?'Aktivitet i regjistruar':'Recorded activity';technical=item.event_type}
 }
 return {title,detail,technical}
}
