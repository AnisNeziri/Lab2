import { useEffect, useRef, useState } from 'react'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import '../ShipmentMap.css'

const shipIcon = (active = false) => L.divIcon({
  className: `shipment-ship-marker${active ? ' is-active' : ''}`,
  html: '<span class="shipment-ship-dot"></span>',
  iconSize: [18, 18],
  iconAnchor: [9, 9],
})

const portIcon = L.divIcon({
  className: 'shipment-port-marker',
  html: '<span class="shipment-port-pin"></span>',
  iconSize: [14, 14],
  iconAnchor: [7, 7],
})

function validPoint(lat, lng) {
  if (lat === null || lat === undefined || lat === '' || lng === null || lng === undefined || lng === '') return false
  return Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))
    && Number(lat) >= -90 && Number(lat) <= 90
    && Number(lng) >= -180 && Number(lng) <= 180
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
  })[character])
}

export default function GlobalVesselMap({ vessels = [], selectedId, onSelectVessel, pendingLabel = 'Waiting for AIS signal', noPositionsLabel = 'No tracked vessels match these filters.' }) {
  const mapRef = useRef(null)
  const mapInstance = useRef(null)
  const layersRef = useRef([])
  const [mapError, setMapError] = useState('')
  const hasMappedVessel = vessels.some((vessel) => (
    validPoint(vessel.current_lat, vessel.current_lng)
  ))
  const pendingVessels = vessels.filter((vessel) => !validPoint(vessel.current_lat, vessel.current_lng))

  useEffect(() => {
    if (!mapRef.current || mapInstance.current) return

    try {
      mapInstance.current = L.map(mapRef.current, {
        zoomControl: true,
        scrollWheelZoom: true,
        preferCanvas: true,
      }).setView([25, 45], 3)

      const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 18,
      }).addTo(mapInstance.current)
      let fallbackTiles
      tiles.once('tileerror', () => {
        // Some company networks block one public tile host. Try a second
        // reputable provider before showing an error to the operator.
        mapInstance.current?.removeLayer(tiles)
        fallbackTiles = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
          attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
          maxZoom: 20,
        }).addTo(mapInstance.current)
        fallbackTiles.once('tileerror', () => {
          setMapError('Map tiles could not be loaded. Check the firewall or proxy settings and try Refresh again.')
        })
      })

      // Leaflet is often mounted while the sidebar/grid is still settling.
      // Recalculate its size after layout and whenever the panel changes size.
      const resize = () => mapInstance.current?.invalidateSize({ animate: false })
      const observer = typeof ResizeObserver !== 'undefined'
        ? new ResizeObserver(resize)
        : null
      observer?.observe(mapRef.current)
      requestAnimationFrame(resize)
      const timer = window.setTimeout(resize, 250)

      return () => {
        window.clearTimeout(timer)
        observer?.disconnect()
        fallbackTiles?.remove()
        mapInstance.current?.remove()
        mapInstance.current = null
      }
    } catch {
      setMapError('The map could not be initialized. Try Refresh again.')
    }

  }, [])

  useEffect(() => {
    const map = mapInstance.current
    if (!map) return

    layersRef.current.forEach((layer) => map.removeLayer(layer))
    layersRef.current = []

    const points = []

    vessels.forEach((vessel) => {
      // AIS position reports can arrive before the shipment's port geocodes.
      // Show the live vessel immediately and draw the route once both ports
      // are available instead of hiding the vessel altogether.
      if (!validPoint(vessel.current_lat, vessel.current_lng)) return

      const current = [vessel.current_lat, vessel.current_lng]
      const origin = validPoint(vessel.origin_lat, vessel.origin_lng)
        ? [vessel.origin_lat, vessel.origin_lng]
        : null
      const destination = validPoint(vessel.destination_lat, vessel.destination_lng)
        ? [vessel.destination_lat, vessel.destination_lng]
        : null
      points.push(current)
      if (origin) points.push(origin)
      if (destination) points.push(destination)

      const marker = L.marker(current, { icon: shipIcon(vessel.id === selectedId) })
        .addTo(map)
        .bindPopup(`<strong>${escapeHtml(vessel.name)}</strong><br>${escapeHtml(vessel.mmsi ? `MMSI ${vessel.mmsi}` : '')}${vessel.destination_port ? `<br>Destination: ${escapeHtml(vessel.destination_port)}` : ''}`)
        .on('click', () => onSelectVessel?.(vessel))

      layersRef.current.push(marker)

      const route = [origin, current, destination].filter(Boolean)
      if (route.length > 1) {
        layersRef.current.push(L.polyline(route, {
          color: vessel.id === selectedId ? '#1d4ed8' : '#64748b',
          weight: vessel.id === selectedId ? 3 : 2,
          opacity: vessel.id === selectedId ? 0.9 : 0.45,
          dashArray: '6 6',
        }).addTo(map))
      }
    })

    if (points.length) {
      map.fitBounds(L.latLngBounds(points).pad(0.15))
    }
  }, [vessels, selectedId, onSelectVessel])

  return (
    <div className="map-shell">
      <div ref={mapRef} className="shipment-map global-vessel-map" aria-label="Global vessel map" />
      {mapError ? <div className="map-fallback" role="status">{mapError}</div> : null}
      {!mapError && pendingVessels.length ? (
        <div className="map-pending-vessels" role="status">
          <strong>{pendingLabel}</strong>
          <div>{pendingVessels.slice(0, 6).map((vessel) => (
            <button type="button" key={vessel.id} className={vessel.id === selectedId ? 'active' : ''} onClick={() => onSelectVessel?.(vessel)}>
              {vessel.name || `MMSI ${vessel.mmsi}`}
            </button>
          ))}</div>
        </div>
      ) : null}
      {!mapError && !hasMappedVessel && !pendingVessels.length ? (
        <div className="map-empty-state" role="status">
          {noPositionsLabel}
        </div>
      ) : null}
    </div>
  )
}

export function ShipmentRouteMap({ shipment, pendingLabel = 'Waiting for the first AIS position broadcast' }) {
  const mapRef = useRef(null)
  const mapInstance = useRef(null)
  const layersRef = useRef({})

  useEffect(() => {
    if (!mapRef.current || mapInstance.current) return

    mapInstance.current = L.map(mapRef.current, {
      zoomControl: true,
      scrollWheelZoom: true,
    }).setView([20, 0], 2)

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 18,
    }).addTo(mapInstance.current)

    return () => {
      mapInstance.current?.remove()
      mapInstance.current = null
    }
  }, [])

  useEffect(() => {
    const map = mapInstance.current
    if (!map || !shipment) return

    const origin = validPoint(shipment.origin_lat, shipment.origin_lng)
      ? [Number(shipment.origin_lat), Number(shipment.origin_lng)]
      : null
    const destination = validPoint(shipment.destination_lat, shipment.destination_lng)
      ? [Number(shipment.destination_lat), Number(shipment.destination_lng)]
      : null
    const current = validPoint(shipment.current_lat, shipment.current_lng)
      ? [Number(shipment.current_lat), Number(shipment.current_lng)]
      : null

    Object.values(layersRef.current).forEach((layer) => map.removeLayer(layer))
    layersRef.current = {}

    if (origin) {
      layersRef.current.origin = L.marker(origin, { icon: portIcon }).addTo(map)
        .bindPopup(`Origin: ${escapeHtml(shipment.origin_port || 'Known origin')}`)
    }
    if (destination) {
      layersRef.current.destination = L.marker(destination, { icon: portIcon }).addTo(map)
        .bindPopup(`Destination: ${escapeHtml(shipment.destination_port || 'Known destination')}`)
    }
    if (current) {
      layersRef.current.ship = L.marker(current, { icon: shipIcon(true) }).addTo(map)
        .bindPopup(escapeHtml(shipment.vessel_name || shipment.tracking_number || 'Shipment'))
    }

    const route = [origin, current, destination].filter(Boolean)
    if (route.length > 1) {
      layersRef.current.route = L.polyline(route, {
        color: '#2563eb',
        weight: 3,
        opacity: 0.75,
        dashArray: shipment.status === 'arrived_at_port' ? null : '8 8',
      }).addTo(map)
      map.fitBounds(L.latLngBounds(route).pad(0.2))
    } else if (route.length === 1) {
      map.setView(route[0], 6)
    }
  }, [shipment])

  const hasPosition = validPoint(shipment?.current_lat, shipment?.current_lng)

  return <div className="map-shell shipment-route-map-shell">
    <div ref={mapRef} className="shipment-map" aria-label="Shipment route map" />
    {!hasPosition ? <div className="map-pending-vessel-detail" role="status">
      <span className="map-pending-radar" aria-hidden="true" />
      <div><strong>{shipment?.vessel_name || (shipment?.mmsi ? `MMSI ${shipment.mmsi}` : shipment?.tracking_number)}</strong><small>{pendingLabel}</small></div>
    </div> : null}
  </div>
}
