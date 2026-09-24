import { productMatchesShelf, sectionLocationKey, STANDARD_FLOOR_HEIGHT } from './warehouseLayout'

export function productHealthPercent(product) {
  const qty = Number(product?.quantity ?? 0)
  if (qty <= 0) return 0
  const min = Number(product?.min_quantity ?? 0)
  if (min <= 0) return 100
  return Math.min(100, Math.round((qty / min) * 100))
}

export function shelfHealthFromProducts(products) {
  if (!products?.length) return null
  if (products.every((p) => Number(p.quantity) <= 0)) return 0
  return Math.min(...products.map(productHealthPercent))
}

export function buildShelfStockMap(products, shelves) {
  const levels = {}
  shelves.forEach((shelf) => {
    const locatedProducts = products.flatMap((product) => {
      const balance = (product.warehouse_stock ?? []).find((item) => Number(item.location_id) === Number(shelf.locationId))
      if (!balance) return []
      return [{ ...product, quantity: Number(balance.available_quantity ?? balance.quantity ?? 0) }]
    })
    const prods = shelf.locationId != null
      ? locatedProducts
      : products.filter((p) => productMatchesShelf(p, shelf))
    levels[shelf.id] = prods.length ? shelfHealthFromProducts(prods) : null
  })
  return { levels }
}

export function sectionsToShelves(sections) {
  return (sections ?? []).map((s) => {
    const floorLevel = Number(s.floor_level ?? 1)
    return {
      id: sectionLocationKey(floorLevel, s.code),
      code: s.code,
      x: Number(s.pos_x),
      y: (floorLevel - 1) * STANDARD_FLOOR_HEIGHT,
      z: Number(s.pos_z),
      floorLevel,
      zoneId: `${String(s.location_path || s.code).replace(/^L\d+-/, '').split('/')[0].charAt(0)}-L${floorLevel}`,
      zoneLabel: s.name,
      zoneColor: s.color,
      lightColor: s.light_color,
      sectionId: s.id,
      locationId: s.warehouse_location_id,
      locationPath: s.location_path,
    }
  })
}

export function sectionsToZones(sections) {
  const map = {}
  sectionsToShelves(sections).forEach((shelf) => {
    const key = shelf.zoneId
    if (!map[key]) {
      map[key] = {
        id: key,
        label: shelf.zoneLabel.split(/\s*(?:—|\?{2,3})\s*/)[0]?.trim() || `Zone ${key}`,
        color: shelf.zoneColor,
        lightColor: shelf.lightColor,
        shelves: [],
      }
    }
    map[key].shelves.push(shelf)
  })
  return Object.values(map)
}
