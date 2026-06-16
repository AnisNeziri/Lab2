export const STANDARD_FLOOR_HEIGHT = 8

export function footprintArea(lengthM, widthM) {
  const l = Number(lengthM) || 0
  const w = Number(widthM) || 0
  return Math.round(l * w * 100) / 100
}

export function sectionLocationKey(floorLevel, code) {
  const floor = Number(floorLevel) || 1
  const c = String(code || '').trim().toUpperCase()
  if (floor <= 1) return c
  return `L${floor}-${c}`
}

export function metersToPercent(pos, dimension) {
  const d = Number(dimension) || 1
  return ((Number(pos) / d) + 0.5) * 100
}

export const PX_PER_METER = 10

export function clientToMeters(clientX, clientY, rect, lengthM, widthM, options = {}) {
  const { snap = 1, offsetX = 0, offsetY = 0 } = options
  const relX = (clientX - offsetX - rect.left) / rect.width
  const relY = (clientY - offsetY - rect.top) / rect.height
  let pos_x = (relX - 0.5) * Number(lengthM)
  let pos_z = (relY - 0.5) * Number(widthM)
  if (snap > 0) {
    pos_x = snapMeters(pos_x, snap)
    pos_z = snapMeters(pos_z, snap)
  }
  return { pos_x, pos_z }
}

export function snapMeters(value, grid = 1) {
  const g = Number(grid) || 1
  return Math.round(Number(value) / g) * g
}

export function clampSectionPosition(pos_x, pos_z, section, lengthM, widthM) {
  const l = Number(lengthM) || 80
  const w = Number(widthM) || 90
  const halfW = Number(section?.width ?? 4) / 2
  const halfD = Number(section?.depth ?? 4) / 2
  const minX = -l / 2 + halfW
  const maxX = l / 2 - halfW
  const minZ = -w / 2 + halfD
  const maxZ = w / 2 - halfD
  return {
    pos_x: Math.min(maxX, Math.max(minX, pos_x)),
    pos_z: Math.min(maxZ, Math.max(minZ, pos_z)),
  }
}

export function sectionCenterClient(pos, rect, lengthM, widthM) {
  return {
    x: rect.left + (metersToPercent(pos.pos_x, lengthM) / 100) * rect.width,
    y: rect.top + (metersToPercent(pos.pos_z, widthM) / 100) * rect.height,
  }
}

export function sectionBoxPercent(section, lengthM, widthM) {
  const l = Number(lengthM) || 80
  const w = Number(widthM) || 90
  return {
    width: `${(Number(section.width) / l) * 100}%`,
    height: `${(Number(section.depth) / w) * 100}%`,
  }
}

export function canvasSize(lengthM, widthM, pxPerM = PX_PER_METER) {
  return {
    width: Number(lengthM) * pxPerM,
    height: Number(widthM) * pxPerM,
  }
}

export function productMatchesShelf(product, shelf) {
  const loc = product.location_code?.trim().toUpperCase()
  if (!loc) return false
  if (loc === shelf.id?.toUpperCase()) return true
  if (shelf.floorLevel === 1 && loc === String(shelf.code || '').toUpperCase()) return true
  return false
}
