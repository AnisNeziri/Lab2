import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { getSupplierWorkbench } from '../api/accounting'
import { getPurchaseOrders } from '../api/purchaseOrders'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'
import { businessMoney, businessDate } from '../utils/businessFormat'
import { businessStatus } from '../utils/businessStatus'
import { BusinessFacts } from './WorkflowSummary'

export default function SupplierPosition({ supplier, onClose }) {
  const { language } = useTranslation(), t=(en,sq)=>language==='sq'?sq:en
  const permissions=useAuthStore(s=>s.permissions), can=p=>permissions.includes(p)
  const [data,setData]=useState(null),[orders,setOrders]=useState(null),[error,setError]=useState(''),[retry,setRetry]=useState(0)
  const finance=can('supplier_invoices.view')&&can('finance.view'), purchasing=can('purchase_orders.view')
  useEffect(()=>{
    let alive=true;setData(null);setOrders(null);setError('')
    Promise.all([finance?getSupplierWorkbench(supplier.id):null,purchasing?getPurchaseOrders({supplier_id:supplier.id,per_page:5}):null])
      .then(([d,o])=>{if(alive){setData(d);setOrders(o)}}).catch(e=>alive&&setError(e.message))
    return()=>{alive=false}
  },[supplier.id,finance,purchasing,retry])
  return <section className="supplier-position" aria-label={t('Supplier overview','Përmbledhja e furnitorit')}>
    <header><div><h2>{supplier.name}</h2><p>{[supplier.email,supplier.phone].filter(Boolean).join(' · ')||t('No contact details recorded','Pa të dhëna kontakti')}</p></div><button type="button" className="secondary" onClick={onClose}>{t('Back to suppliers','Kthehu te furnitorët')}</button></header>
    {error&&<p role="alert">{error} <button onClick={()=>setRetry(x=>x+1)}>{t('Retry','Provo përsëri')}</button></p>}
    {finance&&!data&&!error&&<p role="status">{t('Loading posted supplier position…','Duke ngarkuar gjendjen e postuar…')}</p>}
    {data&&<BusinessFacts facts={[
      {label:t('Outstanding payable','Detyrimi i papaguar'),value:businessMoney(data.summary.total,data.base_currency,language)},
      {label:t('Open supplier bills','Fatura të hapura'),value:data.summary.open_count},
      {label:t('Due date not recorded','Pa afat pagese'),value:businessMoney(data.summary.unknown_due,data.base_currency,language)},
      {label:t('Advance payments','Paradhënie'),value:data.advances.total,note:t('Review currency and remaining allocation in the payment workbench.','Shqyrto valutën dhe alokimin e mbetur në hapësirën e pagesave.')},
    ]}/>}
    <nav aria-label={t('Supplier records','Regjistrimet e furnitorit')}>
      {purchasing&&<Link to={'/purchase-orders?supplier='+supplier.id}>{t('Purchase orders','Porositë e blerjes')}</Link>}
      {finance&&<Link to={'/accounting?tab=ap&supplier='+supplier.id+'&workbench=1'}>{t('Bills, advances & payments','Faturat, paradhëniet dhe pagesat')}</Link>}
      {can('shipments.view')&&<Link to="/shipments/my-shipments">{t('Incoming shipments','Dërgesat në ardhje')}</Link>}
    </nav>
    {orders?.data?.length>0&&<><h3>{t('Recent purchase orders','Porositë e fundit të blerjes')}</h3><div className="fc-table-scroll"><table><thead><tr>{[t('Order','Porosia'),t('Status','Statusi'),t('Expected','Pritet'),t('Committed value','Vlera e porosisë')].map(h=><th key={h}>{h}</th>)}</tr></thead><tbody>{orders.data.map(o=><tr key={o.id}><td><Link to={'/purchase-orders?po='+o.id}>{o.po_number}</Link></td><td>{businessStatus(o.status,language)}</td><td>{businessDate(o.expected_at,language)}</td><td className="numeric">{businessMoney(o.total_amount,o.currency,language)}</td></tr>)}</tbody></table></div><p className="wf-note">{t('Showing up to 5 recent orders. Order commitments are separate from posted supplier bills.','Shfaqen deri në 5 porosi të fundit. Porositë janë të veçanta nga faturat e postuara.')}</p></>}
  </section>
}
