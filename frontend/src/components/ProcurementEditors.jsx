import { useEffect, useRef, useState } from 'react'
import { useUiText } from '../hooks/useUiText'
import { useSettingsStore } from '../store/settingsStore'
import { isMeterUnit } from '../utils/formatQuantity'
import { useTranslation } from '../hooks/useTranslation'

export function SupplierQuoteEditor({ rfq, suppliers, busy, onSave, preferredSupplier }) {
  const tx=useUiText(), baseCurrency=useSettingsStore(s=>s.base_currency||'EUR')
  const invited=new Set((rfq.suppliers||[]).map(row=>row.supplier_id)), awarded=new Set((rfq.awards||[]).map(row=>row.purchase_request_item_id))
  const {language}=useTranslation()
  const t=(en,sq)=>language==='sq'?sq:en
  const [quote,setQuote]=useState({supplier_id:invited.has(preferredSupplier)?String(preferredSupplier):'',currency:baseCurrency,exchange_rate:'',exchange_rate_date:'',shipping_terms:'',payment_terms:'',valid_until:'',items:{}})
  const [source,setSource]=useState('')
  const [received,setReceived]=useState('')
  const [deliveryEvidence,setDeliveryEvidence]=useState('')
  const editor=useRef(null)
  useEffect(()=>{
    if(window.location.hash!=='#received-supplier-quote') return
    editor.current?.focus()
  },[])
  const items=(rfq.purchase_request?.items||[]).filter(item=>!awarded.has(item.id))
  const change=(id,field,value)=>setQuote({...quote,items:{...quote.items,[id]:{...quote.items[id],[field]:value}}})
  return <form ref={editor} tabIndex={-1} id="received-supplier-quote" className="procurement-card" onSubmit={async event=>{
    event.preventDefault()
    const payload={...quote,exchange_rate:quote.currency===baseCurrency?1:quote.exchange_rate,valid_until:quote.valid_until||null,items:items.filter(item=>quote.items[item.id]?.included).map(item=>({purchase_request_item_id:item.id,offered_quantity:quote.items[item.id].offered_quantity??item.quantity,unit_price:quote.items[item.id].unit_price,minimum_order_quantity:quote.items[item.id].minimum_order_quantity||null,lead_time_days:quote.items[item.id].lead_time_days||null}))}
    payload.exchange_rate_date=quote.exchange_rate_date||null
    payload.notes=[source && t('Source: ','Burimi: ')+source,received && t('Received: ','Marrë më: ')+received,deliveryEvidence].filter(Boolean).join('\n') || null
    if(await onSave(payload)){setQuote({...quote,items:{}});setSource('');setReceived('');setDeliveryEvidence('')}
  }}>
    <h2>{tx('Record supplier quote')}</h2>
    <p>{t('Enter the terms the supplier actually sent. Attach the original in this request’s documents if available. Saving records a quote revision for review.','Vendos kushtet që dërgoi furnitori. Bashkëngjit origjinalin te dokumentet e kësaj kërkese, nëse e ke. Ruajtja regjistron një version të ofertës për shqyrtim.')}</p>
    <div className="procurement-fields">
      <label>{tx('Supplier')}<select aria-label={tx('Supplier')} required value={quote.supplier_id} onChange={e=>setQuote({...quote,supplier_id:e.target.value})}><option value="">{tx('Select')}</option>{suppliers.filter(s=>invited.has(s.id)).map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select></label>
      <label>{tx('Currency')}<input required maxLength={3} value={quote.currency} onChange={e=>setQuote({...quote,currency:e.target.value.toUpperCase()})}/></label>
      {quote.currency!==baseCurrency&&<label>{tx('Exchange rate')}<input required type="number" min=".00000001" step="any" value={quote.exchange_rate} onChange={e=>setQuote({...quote,exchange_rate:e.target.value})}/></label>}
      {quote.currency!==baseCurrency&&<label>{t('Exchange rate date','Data e kursit valutor')}<input type="date" value={quote.exchange_rate_date} onChange={e=>setQuote({...quote,exchange_rate_date:e.target.value})}/></label>}
      <label>{t('Offer reference or source','Referenca ose burimi i ofertës')}<input maxLength={255} value={source} onChange={e=>setSource(e.target.value)}/></label>
      <label>{t('Date received','Data e marrjes')}<input type="date" value={received} onChange={e=>setReceived(e.target.value)}/></label>
      <label>{t('Freight terms','Kushtet e transportit')}<input maxLength={255} value={quote.shipping_terms} onChange={e=>setQuote({...quote,shipping_terms:e.target.value})}/></label>
      <label>{tx('Payment terms')}<input value={quote.payment_terms} onChange={e=>setQuote({...quote,payment_terms:e.target.value})}/></label>
      <label>{tx('Valid until')}<input type="date" value={quote.valid_until} onChange={e=>setQuote({...quote,valid_until:e.target.value})}/></label>
    </div>
    <label>{t('Pack size, delivery destination, customs or other costs','Paketimi, destinacioni, dogana ose kosto të tjera')}<textarea maxLength={1400} value={deliveryEvidence} onChange={e=>setDeliveryEvidence(e.target.value)}/></label>
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
