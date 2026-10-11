import { formatOrderMoney, formatOrderDate } from './orderPresentation'

export default function OrderOverview({ detail, t, language, label }) {
  const order = detail.order
  const currency = order?.currency || detail.payload?.currency
  const warehouses=[...new Set((order?.allocations||[]).map(row=>row.warehouse?.name).filter(Boolean))]
  const facts = [
    [t('Order value','Vlera e porosisë'), formatOrderMoney(order?.total_amount, currency, language)],
    [t('Payment','Pagesa'), label(detail.states?.payment)],
    [t('Paid','Paguar'),formatOrderMoney(detail.states?.paid_amount,currency,language)],
    [t('Remaining order value','Vlera e mbetur e porosisë'),formatOrderMoney(detail.states?.remaining_amount,currency,language)],
    [t('Fulfillment','Përmbushja'), label(detail.states?.fulfillment)],
  ]
  return <div className="hub-overview">
    <div className="hub-order-heading"><div><p className="workspace-eyebrow">{t('Order overview','Përmbledhja e porosisë')}</p><h2>{order?.order_number || detail.external_id || `#${detail.id}`}</h2></div><span className="fulfillment-badge">{label(order?.status || detail.states?.order || detail.state)}</span></div>
    <dl className="hub-order-metrics">{facts.map(([name,value])=><div key={name}><dt>{name}</dt><dd>{value ?? '—'}</dd></div>)}</dl>
    <dl className="hub-order-context">
      {[
        [t('Customer','Klienti'),order?.customer?.name || detail.payload?.customer?.name],
        [t('Order date','Data e porosisë'),formatOrderDate(order?.order_date,language)],
        [t('Delivery date','Data e dorëzimit'),formatOrderDate(order?.requested_delivery_date,language)],
        [t('Warehouse','Depoja'),order?.warehouse?.name || warehouses.join(', ') || (order?.warehouse_id ? `#${order.warehouse_id}` : t('Not assigned yet','Ende pa depo të caktuar'))],
        [t('Channel','Kanali'),detail.channel?.name],
      ].map(([name,value])=><div key={name}><dt>{name}</dt><dd>{value || '—'}</dd></div>)}
    </dl>
    <p className="hub-status-help">{detail.states?.order==='cancelled'?t('This order was cancelled. Its history is retained; it will not be prepared or dispatched.','Kjo porosi u anulua. Historia ruhet; nuk do të përgatitet ose niset.'):!order?.confirmed_at?t('Review the items, customer and payment method, then confirm. No sale has been recorded yet.','Kontrolloni produktet, klientin dhe mënyrën e pagesës, pastaj konfirmoni. Ende nuk është regjistruar shitje.'):String(detail.states?.fulfillment).startsWith('partially_')?t('Some quantities have been prepared or sent; continue fulfillment for the remaining items.','Disa sasi janë përgatitur ose dërguar; vazhdoni përmbushjen për produktet e mbetura.'):detail.states?.fulfillment==='delivered'?t('Delivery is complete. Review payments, documents and history below.','Dorëzimi ka përfunduar. Shikoni pagesat, dokumentet dhe historinë më poshtë.'):t('Confirmed orders reserve stock. Preparing goods does not record a sale; dispatch records it once.','Porositë e konfirmuara rezervojnë stokun. Përgatitja nuk regjistron shitje; nisja e regjistron vetëm një herë.')}</p>
    {order?.payment_type!=='cash'&&<small>{t('Paid shows collections allocated to this order’s dispatched sales, not the customer’s shared advance. Remaining order value includes goods not yet dispatched; it is not necessarily overdue debt.','Paguar shfaq arkëtimet e alokuara te shitjet e nisura të kësaj porosie, jo parapagimin e përbashkët të klientit. Vlera e mbetur përfshin produktet ende të panisura; nuk është domosdoshmërisht borxh me vonesë.')}</small>}
  </div>
}
