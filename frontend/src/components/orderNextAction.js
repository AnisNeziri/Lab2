// A display hint from the existing intake, approval, availability and workflow projection.
// Every mutation still goes through OrderHubService and its current validation.
export function orderNextAction(detail) {
  const order=detail?.order
  if(order?.status==='cancelled')return 'none'
  if(!order||detail.issues?.length)return 'review'
  if(!order.confirmed_at) {
    if(detail.acceptance_approval_required&&!['approved','consumed'].includes(detail.acceptance_approval?.status))return 'approval'
    const shortage=order.items?.some(i=>order.availability?.[i.product_id]?.available!=null&&Number(i.base_quantity)>Number(order.availability[i.product_id].available))
    if(shortage&&detail.channel?.oversale_policy==='require_review'&&!detail.resolution?.stock_review)return 'backorder'
    return 'confirm'
  }
  return ({stock_review:'stock',prepare:'prepare',dispatch:'dispatch',deliver:'deliver',documents:'documents',none:'none'})[detail.connections?.next_action]||'review'
}
