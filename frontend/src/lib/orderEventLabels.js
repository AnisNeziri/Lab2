const labels = {
  'sales_order.created': ['Order created', 'Porosia u krijua'],
  'sales_order.updated': ['Order updated', 'Porosia u përditësua'],
  'sales_order.confirmed': ['Order confirmed', 'Porosia u konfirmua'],
  'sales_order.cancelled': ['Order cancelled', 'Porosia u anulua'],
  'inventory.reserved': ['Stock reserved', 'Stoku u rezervua'],
  'inventory.released': ['Stock reservation released', 'Rezervimi i stokut u lirua'],
  'fulfillment.allocated': ['Warehouse stock allocated', 'Stoku i depos u caktua'],
  'pick_task.created': ['Picking task created', 'Detyra e mbledhjes u krijua'],
  'pick_task.started': ['Picking started', 'Mbledhja filloi'],
  'pick_task.completed': ['Products verified', 'Produktet u verifikuan'],
  'pick_task.assigned': ['Picking task assigned', 'Detyra e mbledhjes u caktua'],
  'pick_task.short': ['Picking shortage recorded', 'Mungesa gjatë mbledhjes u regjistrua'],
  'packing.completed': ['Products packed', 'Produktet u paketuan'],
  'dispatch.departed': ['Dispatched — sale recorded', 'U nis — shitja u regjistrua'],
  'delivery.completed': ['Delivery recorded', 'Dorëzimi u regjistrua'],
  'delivery.failed': ['Delivery failed', 'Dorëzimi dështoi'],
  'customer_return.created': ['Return requested', 'Kthimi u kërkua'],
  'customer_return.received': ['Returned goods received', 'Mallrat e kthyera u pranuan'],
  'customer_return.resolved': ['Return resolved', 'Kthimi u përfundua'],
  'order.payment_received': ['Customer payment recorded', 'Pagesa e klientit u regjistrua'],
  'order.invoice_created': ['Invoice issued', 'Fatura u lëshua'],
  'order.return_documented': ['Return credit note issued', 'Nota kreditore e kthimit u lëshua'],
  'order.internal_note': ['Internal note added', 'Shënimi i brendshëm u shtua'],
  'order.validated': ['Order validated', 'Porosia u validua'],
  'order.validation_failed': ['Order needs review', 'Porosia kërkon shqyrtim'],
  'order.reviewed': ['Order reviewed', 'Porosia u shqyrtua'],
  'order.backorder_reviewed': ['Stock shortage reviewed', 'Mungesa e stokut u shqyrtua'],
  'order.sync_conflict': ['Source change needs review', 'Ndryshimi i burimit kërkon shqyrtim'],
}

export function orderEventLabel(type, language) {
  return (labels[type] || ['Order activity', 'Aktivitet i porosisë'])[language === 'sq' ? 1 : 0]
}
