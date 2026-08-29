import { useEffect, useState } from 'react'
import { Anchor, Navigation, Ship } from 'lucide-react'

const STORAGE_KEY = 'aims.shipments-intro.v1'

export default function ShipmentsPageIntro({ message = 'Loading shipment network' }) {
  const [visible, setVisible] = useState(() => {
    try {
      return sessionStorage.getItem(STORAGE_KEY) !== 'seen'
    } catch {
      return true
    }
  })

  useEffect(() => {
    if (!visible) return undefined
    const timer = window.setTimeout(() => {
      setVisible(false)
      try { sessionStorage.setItem(STORAGE_KEY, 'seen') } catch { /* storage is optional */ }
    }, 1300)
    return () => window.clearTimeout(timer)
  }, [visible])

  if (!visible) return null

  return (
    <div className="shipments-intro" role="status" aria-live="polite">
      <div className="shipments-intro-grid" aria-hidden="true" />
      <div className="shipments-intro-route" aria-hidden="true"><span /><span /></div>
      <div className="shipments-intro-water" aria-hidden="true"><span /><span /><span /></div>
      <div className="shipments-intro-port shipments-intro-port-start"><Anchor size={15} /></div>
      <div className="shipments-intro-port shipments-intro-port-end"><Navigation size={15} /></div>
      <div className="shipments-intro-ship"><Ship size={44} strokeWidth={1.5} /></div>
      <strong>{message}</strong>
      <span className="shipments-intro-caption">Connecting ports, cargo, and inventory</span>
    </div>
  )
}
