import { useEffect, useRef } from 'react'
import { CheckCircle2, PackageCheck, ScanLine, Ship, Sparkles, X } from 'lucide-react'

const ICONS = {
  product: PackageCheck,
  shipment: Ship,
}

export default function SuccessAnimation({
  open,
  variant = 'product',
  title,
  message,
  reference,
  duration = 2200,
  onClose,
}) {
  const onCloseRef = useRef(onClose)

  useEffect(() => {
    onCloseRef.current = onClose
  }, [onClose])

  useEffect(() => {
    if (!open || !onCloseRef.current) return undefined
    const timer = window.setTimeout(() => onCloseRef.current?.(), duration)
    return () => window.clearTimeout(timer)
  }, [open, duration])

  if (!open) return null

  const Icon = ICONS[variant] ?? PackageCheck

  return (
    <div className={`success-animation success-animation-${variant}`} role="status" aria-live="polite">
      <div className="success-animation-panel">
        <div className="success-animation-grid" aria-hidden="true" />
        <div className="success-animation-scan" aria-hidden="true"><ScanLine size={150} /></div>
        <div className="success-animation-particles" aria-hidden="true">
          {Array.from({ length: 8 }, (_, index) => <span key={index} style={{ '--particle-index': index }} />)}
        </div>
        <button type="button" className="success-animation-close" onClick={onClose} aria-label="Dismiss success message">
          <X size={15} />
        </button>
        <div className="success-animation-icon"><Icon size={34} strokeWidth={1.8} /></div>
        <div className="success-animation-check"><CheckCircle2 size={18} /></div>
        <div className="success-animation-copy">
          <strong>{title}</strong>
          <span>{message}</span>
          {reference ? <small>{reference}</small> : null}
        </div>
        <Sparkles className="success-animation-sparkle" size={18} aria-hidden="true" />
      </div>
    </div>
  )
}
