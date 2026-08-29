import { useEffect, useRef } from 'react'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import './ShipmentMap.css'

const shipIcon = L.divIcon({
  className: 'shipment-ship-marker',
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
  return Number.isFinite(Number(lat)) && Number.isFinite(Number(lng))
    && Number(lat) >= -90 && Number(lat) <= 90
    && Number(lng) >= -180 && Number(lng) <= 180
}

export default function ShipmentMap({ shipment }) {
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

    if (!validPoint(shipment.origin_lat, shipment.origin_lng) || !validPoint(shipment.destination_lat, shipment.destination_lng)) return

    const origin = [Number(shipment.origin_lat), Number(shipment.origin_lng)]
    const destination = [Number(shipment.destination_lat), Number(shipment.destination_lng)]
    const current = validPoint(shipment.current_lat, shipment.current_lng)
      ? [Number(shipment.current_lat), Number(shipment.current_lng)]
      : null

    Object.values(layersRef.current).forEach((layer) => map.removeLayer(layer))
    layersRef.current = {}

    layersRef.current.origin = L.marker(origin, { icon: portIcon }).addTo(map)
      .bindPopup(`Origin: ${shipment.origin_port}`)
    layersRef.current.destination = L.marker(destination, { icon: portIcon }).addTo(map)
      .bindPopup(`Destination: ${shipment.destination_port}`)
    if (current) {
      layersRef.current.ship = L.marker(current, { icon: shipIcon }).addTo(map)
        .bindPopup(shipment.vessel_name || 'Vessel')
    }

    layersRef.current.route = L.polyline(current ? [origin, current, destination] : [origin, destination], {
      color: '#2563eb',
      weight: 3,
      opacity: 0.75,
      dashArray: shipment.status === 'arriving' ? null : '8 8',
    }).addTo(map)

    const bounds = L.latLngBounds(current ? [origin, destination, current] : [origin, destination])
    map.fitBounds(bounds.pad(0.2))
  }, [shipment])

  return <div ref={mapRef} className="shipment-map" aria-label="Shipment tracking map" />
}
