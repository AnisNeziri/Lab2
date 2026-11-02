import { LoaderCircle } from 'lucide-react'

/** Actual request progress, without a timed overlay covering the workspace. */
export default function ShipmentsPageIntro({ message, loading = false }) {
  if(!loading)return null
  return <div className="shipment-loading-inline" role="status"><LoaderCircle size={16} className="is-spinning" aria-hidden="true"/><span>{message}</span></div>
}
