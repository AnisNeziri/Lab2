import { formatOrderMoney, formatOrderDate } from './orderPresentation'

export default function OrderOverview({ detail, t, language, label }) {
  const order = detail.order
  const currency = order?.currency || detail.payload?.currency
  const facts = [
    [t('Order value','Vlera e porosisë'), formatOrderMoney(order?.total_amount, currency, language)],
    [t('Payment','Pagesa'), label(detail.states?.payment)],
    [t('Product lines','Rreshta produktesh'), order?.items?.length ?? '—'],
    [t('Fulfillment','Përmbushja'), label(detail.states?.fulfillment)],
  ]
  return <div className="hub-overview">
    <div className="hub-order-heading"><div><p className="workspace-eyebrow">{t('Order overview','Përmbledhja e porosisë')}</p><h2>{order?.order_number || detail.external_id || `#${detail.id}`}</h2></div><span className="fulfillment-badge">{label(detail.states?.order || detail.state)}</span></div>
    <dl className="hub-order-metrics">{facts.map(([name,value])=><div key={name}><dt>{name}</dt><dd>{value ?? '—'}</dd></div>)}</dl>
    <dl className="hub-order-context">
      {[
        [t('Customer','Klienti'),order?.customer?.name || detail.payload?.customer?.name],
        [t('Order date','Data e porosisë'),formatOrderDate(order?.order_date,language)],
        [t('Delivery date','Data e dorëzimit'),formatOrderDate(order?.requested_delivery_date,language)],
        [t('Warehouse','Depoja'),order?.warehouse?.name],
        [t('Channel','Kanali'),detail.channel?.name],
      ].map(([name,value])=><div key={name}><dt>{name}</dt><dd>{value || '—'}</dd></div>)}
    </dl>
  </div>
}
