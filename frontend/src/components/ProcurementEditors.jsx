import { useState } from 'react'
import { useUiText } from '../hooks/useUiText'
import { useSettingsStore } from '../store/settingsStore'
import { isMeterUnit } from '../utils/formatQuantity'

export function SupplierQuoteEditor({ rfq, suppliers, busy, onSave }) {
  const tx=useUiText(), baseCurrency=useSettingsStore(s=>s.base_currency||'EUR')
  const [quote,setQuote]=useState({supplier_id:'',currency:baseCurrency,exchange_rate:'',payment_terms:'',valid_until:'',items:{}})
  const invited=new Set((rfq.suppliers||[]).map(row=>row.supplier_id)), awarded=new Set((rfq.awards||[]).map(row=>row.purchase_request_item_id))
  const items=(rfq.purchase_request?.items||[]).filter(item=>!awarded.has(item.id))
  const change=(id,field,value)=>setQuote({...quote,items:{...quote.items,[id]:{...quote.items[id],[field]:value}}})
  return <form className="procurement-card" onSubmit={async event=>{
    event.preventDefault()
    const payload={...quote,exchange_rate:quote.currency===baseCurrency?1:quote.exchange_rate,valid_until:quote.valid_until||null,items:items.filter(item=>quote.items[item.id]?.included).map(item=>({purchase_request_item_id:item.id,offered_quantity:quote.items[item.id].offered_quantity??item.quantity,unit_price:quote.items[item.id].unit_price,minimum_order_quantity:quote.items[item.id].minimum_order_quantity||null,lead_time_days:quote.items[item.id].lead_time_days||null}))}
    if(await onSave(payload))setQuote({...quote,items:{}})
  }}>
    <h2>{tx('Record supplier quote')}</h2>
    <div className="procurement-fields">
      <label>{tx('Supplier')}<select aria-label={tx('Supplier')} required value={quote.supplier_id} onChange={e=>setQuote({...quote,supplier_id:e.target.value})}><option value="">{tx('Select')}</option>{suppliers.filter(s=>invited.has(s.id)).map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
      <label>{tx('Currency')}<input required maxLength={3} value={quote.currency} onChange={e=>setQuote({...quote,currency:e.target.value.toUpperCase()})}/></label>
      {quote.currency!==baseCurrency&&<label>{tx('Exchange rate')}<input required type="number" min=".00000001" step="any" value={quote.exchange_rate} onChange={e=>setQuote({...quote,exchange_rate:e.target.value})}/></label>}
      <label>{tx('Payment terms')}<input value={quote.payment_terms} onChange={e=>setQuote({...quote,payment_terms:e.target.value})}/></label>
      <label>{tx('Valid until')}<input type="date" value={quote.valid_until} onChange={e=>setQuote({...quote,valid_until:e.target.value})}/></label>
    </div>
    {items.map(item=><fieldset className="procurement-fields" key={item.id}><legend><label className="inline-check"><input type="checkbox" checked={!!quote.items[item.id]?.included} onChange={e=>change(item.id,'included',e.target.checked)}/>{item.description}</label></legend>{quote.items[item.id]?.included&&[['offered_quantity','Quantity',item.quantity],['unit_price','Unit price',''],['minimum_order_quantity','MOQ',''],['lead_time_days','Lead time (days)','']].map(([field,label,fallback])=><label key={field}>{tx(label)}<input type="number" min={field==='unit_price'||field==='lead_time_days'?'0':isMeterUnit(item.unit)?'.001':'1'} step={field==='lead_time_days'?'1':field==='unit_price'?'.01':isMeterUnit(item.unit)?'.001':'1'} required={field==='unit_price'||field==='offered_quantity'} value={quote.items[item.id]?.[field]??fallback} onChange={e=>change(item.id,field,e.target.value)}/></label>)}</fieldset>)}
    <button disabled={busy||!items.some(item=>quote.items[item.id]?.included)}>{tx('Save quote')}</button>
  </form>
}

export function AwardReview({ offers, busy, onConfirm, onBack }) {
  const tx=useUiText(), baseCurrency=useSettingsStore(s=>s.base_currency||'EUR')
  const groups=Object.values(offers.reduce((result,offer)=>{const id=`${offer.quote.supplier_id}-${offer.quote.currency}`;(result[id]??={quote:offer.quote,items:[]}).items.push(offer);return result},{}))
  const subtotal=items=>(items.reduce((total,item)=>total+Math.round(Number(item.line_subtotal)*100),0)/100).toFixed(2)
  return <section className="procurement-card award-review" role="region" aria-label={tx('Review award')}><h2>{tx('Review award')}</h2><p>{tx('Purchase Orders to create')}: <strong>{groups.length}</strong></p><p>{tx('Quote constraints may have changed. Review the server message and your selections.')}</p>
    {groups.map(group=><article key={`${group.quote.supplier_id}-${group.quote.currency}`}><h3>{group.quote.supplier?.name} · {group.quote.currency}</h3><div className="table-wrap"><table><thead><tr><th>{tx('Product')}</th><th>{tx('Quantity')}</th><th>{tx('Unit price')}</th><th>{tx('Line subtotal')}</th><th>{baseCurrency}</th><th>{tx('Lead time (days)')}</th></tr></thead><tbody>{group.items.map(offer=><tr key={offer.id}><td>{offer.request_item?.description}</td><td>{offer.offered_quantity}</td><td>{offer.unit_price}</td><td>{offer.line_subtotal}</td><td>{offer.base_subtotal??'—'}</td><td>{offer.lead_time_days??'—'}</td></tr>)}</tbody></table></div><p>{tx('Supplier subtotal')}: {group.quote.currency} {subtotal(group.items)}</p></article>)}
    <div className="procurement-actions"><button disabled={busy||!offers.length} onClick={onConfirm}>{tx('Confirm award')}</button><button disabled={busy} className="secondary" onClick={onBack}>{tx('Back to selection')}</button></div>
  </section>
}
