import { Link } from 'react-router-dom'
import { NextStep } from './WorkflowSummary'
import { orderNextAction } from './orderNextAction'

export default function OrderNextStep({ detail, t, busy, manage, action, edit }) {
  const next=orderNextAction(detail)
  const labels={review:t('Review order details','Shqyrto detajet e porosisë'),approval:t('Request order approval','Kërko miratimin e porosisë'),backorder:t('Review stock shortage','Shqyrto mungesën e stokut'),confirm:t('Confirm & reserve','Konfirmo dhe rezervo'),stock:t('Review incoming availability','Shqyrto disponueshmërinë në ardhje'),prepare:t('Prepare fulfillment','Përgatit përmbushjen'),dispatch:t('Review dispatch','Shqyrto nisjen'),deliver:t('Review delivery','Shqyrto dorëzimin'),documents:t('View history & documents','Shiko historinë dhe dokumentet'),none:t('No further operational action','Nuk kërkohet veprim tjetër operacional')}
  const request=(name,message)=>{const reason=window.prompt(message);if(reason?.trim())action(name,{reason})}
  let control=null
  if(manage&&next==='review')control=<button className="hub-primary" disabled={busy} onClick={edit}>{labels[next]}</button>
  if(manage&&next==='approval')control=<button className="hub-primary" disabled={busy} onClick={()=>request('request-approval',t('Reason for order approval','Arsyeja e miratimit të porosisë'))}>{labels[next]}</button>
  if(manage&&next==='backorder')control=<button className="hub-primary" disabled={busy} onClick={()=>request('review-stock',t('Explain why this customer accepts waiting for stock. The order is not confirmed by this review.','Shpjego pse klienti pranon të presë stokun. Ky shqyrtim nuk konfirmon porosinë.'))}>{labels[next]}</button>
  if(manage&&next==='confirm')control=<button className="hub-primary" disabled={busy} onClick={()=>action('confirm')}>{labels[next]}</button>
  if(['stock','prepare','dispatch','deliver'].includes(next))control=<Link className="hub-warehouse-link" to={'/fulfillment?order='+detail.order.id}>{labels[next]}</Link>
  if(next==='documents')control=<a href="#order-history">{labels[next]}</a>
  return <NextStep title={labels[next]} tone={['review','approval','backorder','stock'].includes(next)?'attention':'active'} action={control}>
    {next==='backorder'?t('Available stock does not cover this order. Record the customer agreement before confirming.','Stoku në dispozicion nuk mbulon porosinë. Regjistro marrëveshjen me klientin para konfirmimit.'):next==='confirm'?t('Confirmation reserves eligible stock. It does not record a sale or receive payment.','Konfirmimi rezervon stokun e lejuar. Nuk regjistron shitje ose pagesë.'):next==='none'?t('Order history remains available below.','Historia e porosisë mbetet më poshtë.'):null}
  </NextStep>
}
