const LEGACY_PRODUCT_QUANTITY_FIELDS = new Set(['on_hand', 'available'])

export function getInventoryQuantity(product, field) {
  const directField = field === 'on_hand' ? 'on_hand_quantity' : `${field}_quantity`
  const fallback = LEGACY_PRODUCT_QUANTITY_FIELDS.has(field) ? product?.quantity : 0

  return Number(product?.[directField] ?? product?.inventory?.[field] ?? fallback ?? 0)
}

export function getWarehouseOnHand(balance) {
  return ['available', 'reserved', 'damaged', 'quarantine', 'blocked']
    .reduce((total, state) => total + Number(balance?.[`${state}_quantity`] ?? 0), 0)
}
