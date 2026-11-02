import { useTranslation } from '../hooks/useTranslation'
import { businessStatus } from '../utils/businessStatus'
import { CheckCircle2, TriangleAlert, CircleX, CircleDot, Circle } from 'lucide-react'

const tones = {
  success: ['healthy', 'completed', 'reconciled', 'posted', 'paid', 'approved', 'received', 'delivered', 'fiscalized', 'ready', 'valid'],
  danger: ['blocked', 'critical', 'failed', 'error', 'revoked', 'expired', 'rejected'],
  warning: ['attention', 'warning', 'overdue', 'needs_review', 'unknown_outcome', 'grace', 'not_ready', 'unmanaged', 'legacy_unmanaged', 'partial'],
  info: ['active', 'enabled', 'open', 'in_progress', 'processing', 'in_transit', 'calculated', 'review'],
}
export default function StatusBadge({ value, label, tone, className = '' }) {
  const { language } = useTranslation()
  const semantic = tone || Object.entries(tones).find(([, states]) => states.includes(String(value).toLowerCase()))?.[0] || 'neutral'
  const Icon={success:CheckCircle2,danger:CircleX,warning:TriangleAlert,info:CircleDot,neutral:Circle}[semantic]||Circle
  return <span className={`workspace-status ${className}`} data-tone={semantic}><Icon size={13} strokeWidth={1.8} aria-hidden="true"/>{label || businessStatus(value, language)}</span>
}
