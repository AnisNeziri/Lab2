// Only recognized numeric business IDs cross the feedback boundary. Never forward a URL or search term.
export function pilotEntityContext(path, search = '') {
  const registry = {
    '/products':['product','product'], '/customer-debts':['customer','customer'],
    '/suppliers':['supplier','supplier'], '/purchase-orders':['purchase-order','po'],
    '/warehouse-operations':['goods-receipt','receipt'], '/fulfillment':['sales-order','order'],
    '/invoices':['invoice','invoice'], '/shipments/my-shipments':['shipment','shipment'],
  }
  const config=registry[path]
  if (!config) return {}
  const value=new URLSearchParams(search).get(config[1])
  if (!/^[1-9]\d{0,14}$/.test(value || '') || !Number.isSafeInteger(Number(value))) return {}
  return { entity_type:config[0], entity_id:Number(value) }
}
