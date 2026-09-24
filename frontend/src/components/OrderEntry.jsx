import { useEffect, useId, useRef, useState } from 'react'
import { orderHub as api } from '../api/orderHub'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../store/authStore'
import { isMeterUnit } from '../utils/formatQuantity'

function SearchPicker({kind,t,onSelect,label}) {
  const [search,setSearch]=useState(''),[rows,setRows]=useState([]),[open,setOpen]=useState(false),[active,setActive]=useState(0),[error,setError]=useState(''),[loading,setLoading]=useState(false)
  const id=useId(),input=useRef(null)
  useEffect(()=>{let live=true;setLoading(true);const timer=setTimeout(()=>api.lookups(kind,search).then(data=>{if(live){setRows(data);setActive(0);setError('')}}).catch(e=>{if(live)setError(e.message)}).finally(()=>{if(live)setLoading(false)}),180);return()=>{live=false;clearTimeout(timer)}},[kind,search])
  const choose=row=>{onSelect(row);setSearch('');setOpen(false)}
  return <div className="order-picker" onBlur={e=>{if(!e.currentTarget.contains(e.relatedTarget))setOpen(false)}}>
    <label htmlFor={id}>{label}</label><input id={id} ref={input} role="combobox" autoComplete="off" aria-expanded={open} aria-controls={`${id}-list`} aria-activedescendant={open&&rows[active]?`${id}-${rows[active].id}`:undefined} placeholder={kind==='product'?t('Name, SKU, barcode or category…','Emri, SKU, barkodi ose kategoria…'):t('Name, business, phone or email…','Emri, biznesi, telefoni ose emaili…')} value={search} onFocus={()=>setOpen(true)} onChange={e=>{setSearch(e.target.value);setOpen(true)}} onKeyDown={e=>{
      if(e.key==='Escape'){setOpen(false);return}
      if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();setOpen(true);setActive(n=>Math.max(0,Math.min(rows.length-1,n+(e.key==='ArrowDown'?1:-1))))}
      if(e.key==='Enter'){e.preventDefault();if(open&&!loading&&rows[active])choose(rows[active])}
    }}/>
    {open&&<div className="order-picker-results" id={`${id}-list`} role="listbox" aria-label={label}>
      {loading?<p role="status">{t('Searching…','Duke kërkuar…')}</p>:rows.map((r,n)=><button type="button" role="option" aria-selected={n===active} id={`${id}-${r.id}`} key={r.id} onMouseDown={e=>e.preventDefault()} onClick={()=>choose(r)}>
        <strong>{r.name}</strong><small>{kind==='product'?`${r.sku||'—'} · ${r.price} ${r.currency} / ${r.unit} · ${t('Available','Në dispozicion')}: ${r.availability?.available??'—'}`:[r.business_name,r.phone,r.email].filter(Boolean).join(' · ')}</small>
      </button>)}{!loading&&!rows.length&&<p>{t('No matches. Try another search.','Nuk ka rezultate. Provoni kërkim tjetër.')}</p>}{error&&<p role="alert">{error}</p>}
    </div>}
  </div>
}

export default function OrderEntry({t,busy,channels,initial,onSubmit,onCancel}) {
  const permissions=useAuthStore(s=>s.permissions)
  const [form,setForm]=useState(()=>({...initial,channel_id:'',order_date:initial?.order_date||new Date().toLocaleDateString('en-CA'),payment_type:initial?.payment_type||'cash',customer:initial?.customer||{},items:initial?.items||[]}))
  const [selectedCustomer,setSelectedCustomer]=useState(null),[review,setReview]=useState(false),[reason,setReason]=useState(''),[error,setError]=useState(''),[addingCustomer,setAddingCustomer]=useState(false),[customerBusy,setCustomerBusy]=useState(false)
  const key=useRef(initial?.idempotency_key||crypto.randomUUID()),quantities=useRef([])
  const field=(k,v)=>{setReview(false);setForm(f=>({...f,[k]:v}))}
  const line=(n,k,v)=>{setReview(false);setForm(f=>({...f,items:f.items.map((r,i)=>i===n?{...r,[k]:v}:r)}))}
  const addProduct=p=>{setReview(false);setForm(f=>{const found=f.items.findIndex(i=>i.product_id===p.id&&i.unit===p.unit);const items=[...f.items];if(found>=0)items[found]={...items[found],quantity:String(Number(items[found].quantity||0)+1)};else items.push({product_id:p.id,sku:p.sku,name:p.name,quantity:'1',unit:p.unit,unit_price:p.price,product:p});return{...f,items}});setTimeout(()=>quantities.current.filter(Boolean).at(-1)?.focus(),0)}
  const chooseCustomer=c=>{setSelectedCustomer(c);field('customer_id',c.id);field('customer',{name:c.name,email:c.email||undefined,phone:c.phone||undefined});if(!form.delivery_address)field('delivery_address',c.address||'')}
  const total=form.items.reduce((s,r)=>s+Number(r.quantity||0)*Number(r.unit_price||0),0)
  const clean=()=>({...form,idempotency_key:key.current,items:form.items.map(({product_id,sku,quantity,unit,unit_price})=>({product_id,sku,quantity,unit,...(unit_price!==''&&unit_price!=null?{unit_price}:{})})),...(reason?{review_reason:reason}:{})})
  return <form className="fulfillment-panel order-entry" onSubmit={e=>{e.preventDefault();if(!form.items.length){setError(t('Add at least one product.','Shtoni të paktën një produkt.'));return}if(!review){setReview(true);return}onSubmit(clean())}}>
    <h2>{initial?t('Edit order','Ndrysho porosinë'):t('Create order','Krijo porosi')}</h2>
    {error&&<p role="alert" className="fulfillment-error">{error}</p>}
    <fieldset disabled={busy||customerBusy}><legend>1. {t('Customer','Klienti')}</legend>
      <SearchPicker kind="customer" t={t} label={t('Find customer','Gjej klientin')} onSelect={chooseCustomer}/>
      {selectedCustomer&&<p className="hub-attention"><strong>{selectedCustomer.name}</strong> · {t('Debt','Borxhi')}: {selectedCustomer.current_debt} · {t('Advance','Parapagimi')}: {selectedCustomer.current_credit} · {t('Payment terms','Afati i pagesës')}: {selectedCustomer.payment_terms_days||0} {t('days','ditë')}{selectedCustomer.credit_status==='blocked'&&<strong> · {t('Credit blocked','Kredia e bllokuar')}</strong>}</p>}
      {form.customer_id&&<p>{form.customer.name||`#${form.customer_id}`} <button type="button" onClick={()=>{setSelectedCustomer(null);field('customer_id',null);field('customer',{});field('payment_type','cash')}}>{t('Use walk-in customer instead','Përdor blerës të rastësishëm')}</button></p>}
      {!form.customer_id&&<label>{t('Walk-in / contact name','Emri i blerësit / kontaktit')}<input required value={form.customer.name||''} onChange={e=>field('customer',{...form.customer,name:e.target.value})}/></label>}
      {permissions.includes('customers.manage')&&<details><summary>{t('Create customer here','Krijo klient këtu')}</summary><label>{t('Customer name','Emri i klientit')}<input value={typeof addingCustomer==='string'?addingCustomer:''} onChange={e=>setAddingCustomer(e.target.value)}/></label><button type="button" disabled={customerBusy||!addingCustomer} onClick={async()=>{if(customerBusy)return;setCustomerBusy(true);try{const c=await apiRequest('/customers',{method:'POST',body:JSON.stringify({name:addingCustomer,is_active:true})});chooseCustomer(c);setAddingCustomer(false)}catch(e){setError(e.message)}finally{setCustomerBusy(false)}}}>{t('Save customer','Ruaj klientin')}</button></details>}
    </fieldset>
    <fieldset disabled={busy}><legend>2. {t('Products','Produktet')}</legend>
      <SearchPicker kind="product" t={t} label={t('Add a product','Shto një produkt')} onSelect={addProduct}/>
      {form.items.map((r,n)=>{const factor=r.unit===r.product?.unit?1:Number(r.product?.units?.find(u=>u.code===r.unit)?.factor_to_base||1),available=r.product?.availability?.available,short=available==null?0:Number(r.quantity)*factor-Number(available);return <div className="order-entry-line" key={`${r.product_id||r.sku}-${n}`}>
        <div><strong>{r.name||r.product?.name||r.sku||`#${r.product_id}`}</strong><small>{r.sku}</small>{available!=null&&<small>{t('Available','Në dispozicion')}: {available} {r.product.unit} · {t('Reserved','Rezervuar')}: {r.product.availability.reserved}</small>}{short>0&&<small className="order-shortage">{t('Short','Mungojnë')}: {short.toFixed(3)} {r.product.unit}. {t('Reduce quantity or review backorder after saving.','Zvogëloni sasinë ose shqyrtoni porosinë në pritje stoku pas ruajtjes.')}</small>}</div>
        <label>{t('Quantity','Sasia')}<input ref={el=>quantities.current[n]=el} required type="number" min={isMeterUnit(r.product?.unit||r.unit)?'0.001':'1'} step={isMeterUnit(r.product?.unit||r.unit)?'0.001':'1'} value={r.quantity} onChange={e=>line(n,'quantity',e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();document.querySelector('.order-entry [role="combobox"][placeholder*="SKU"]')?.focus()}}}/></label>
        <label>{t('Unit','Njësia')}{r.product?<select value={r.unit} onChange={e=>{const unit=e.target.value,f=unit===r.product.unit?1:Number(r.product.units.find(u=>u.code===unit)?.factor_to_base||1);setForm(v=>({...v,items:v.items.map((x,j)=>j===n?{...x,unit,unit_price:(Number(r.product.price)*f).toFixed(2)}:x)}));setReview(false)}}><option value={r.product.unit}>{r.product.unit}</option>{r.product.units.map(u=><option key={u.code} value={u.code}>{u.code} ({u.factor_to_base} {r.product.unit})</option>)}</select>:<input value={r.unit||''} onChange={e=>line(n,'unit',e.target.value)}/>}</label>
        <label>{t('Price','Çmimi')}<input required type="number" min="0" step="0.01" value={r.unit_price??''} onChange={e=>line(n,'unit_price',e.target.value)}/></label>
        <strong>{(Number(r.quantity||0)*Number(r.unit_price||0)).toFixed(2)}</strong><button type="button" aria-label={`${t('Remove','Hiq')} ${r.name||r.sku}`} onClick={()=>field('items',form.items.filter((_,i)=>i!==n))}>{t('Remove','Hiq')}</button>
      </div>})}
    </fieldset>
    <fieldset disabled={busy}><legend>3. {t('Payment & delivery','Pagesa & dorëzimi')}</legend><div className="fulfillment-grid">
      <label>{t('Payment terms','Kushtet e pagesës')}<select value={form.payment_type} onChange={e=>field('payment_type',e.target.value)}><option value="cash">{t('Cash on dispatch','Para në dorë në nisje')}</option>{form.customer_id&&<><option value="credit">{t('On account (customer credit)','Me afat (kredi e klientit)')}</option><option value="prepaid">{t('Use recorded customer advance','Përdor parapagimin e klientit')}</option></>}</select></label>
      <label>{t('Order date','Data e porosisë')}<input required type="date" value={form.order_date} onChange={e=>field('order_date',e.target.value)}/></label><label>{t('Requested delivery','Dorëzimi i kërkuar')}<input type="date" min={form.order_date} value={form.requested_delivery_date||''} onChange={e=>field('requested_delivery_date',e.target.value||null)}/></label>
    </div><label>{t('Delivery address (optional)','Adresa e dorëzimit (opsionale)')}<input maxLength={1000} value={form.delivery_address||''} onChange={e=>field('delivery_address',e.target.value)}/></label></fieldset>
    <details><summary>{t('More details','Më shumë hollësi')}</summary><label>{t('Notes','Shënime')}<textarea maxLength={4000} value={form.notes||''} onChange={e=>field('notes',e.target.value)}/></label>{!initial&&<label>{t('Channel','Kanali')}<select value={form.channel_id} onChange={e=>field('channel_id',e.target.value)}><option value="">{t('Manual order','Porosi manuale')}</option>{channels.filter(c=>c.enabled&&c.type!=='manual').map(c=><option key={c.id} value={c.id}>{c.name}</option>)}</select></label>}<label>{t('External reference','Referenca e jashtme')}<input maxLength={150} value={form.external_id||''} onChange={e=>field('external_id',e.target.value)}/></label>{initial&&<label>{t('Reason for agreed price change','Arsyeja e ndryshimit të çmimit')}<input value={reason} onChange={e=>setReason(e.target.value)}/></label>}</details>
    <div className="order-entry-review"><strong>{t('Preview total','Totali paraprak')}: {total.toFixed(2)}</strong><p>{t('Prices, availability and credit are verified by the server. Stock and the Daily Sale are posted once when goods are dispatched.','Çmimet, disponueshmëria dhe kredia verifikohen nga serveri. Stoku dhe shitja ditore regjistrohen një herë kur mallrat nisen.')}</p>{review&&<p role="status">{t('Review complete. Save this draft, then follow the next action on the order.','Shqyrtimi përfundoi. Ruani draftin, pastaj ndiqni veprimin e radhës në porosi.')}</p>}</div>
    <div className="fulfillment-toolbar"><button disabled={busy||!form.items.length}>{review?t('Save order','Ruaj porosinë'):t('Review order','Shqyrto porosinë')}</button>{review&&<button type="button" onClick={()=>setReview(false)}>{t('Back to editing','Kthehu te ndryshimi')}</button>}<button type="button" disabled={busy} onClick={onCancel}>{t('Cancel','Anulo')}</button></div>
  </form>
}
