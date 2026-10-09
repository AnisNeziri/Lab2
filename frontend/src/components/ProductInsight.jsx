import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { apiRequest } from '../api/client'
import { useAuthStore } from '../store/authStore'
import { useTranslation } from '../hooks/useTranslation'
import { formatOrderDate } from './orderPresentation'

/** Read saved forecast evidence only; never train a model from the product dialog. */
export default function ProductInsight({ productId, onOpen }) {
  const allowed = useAuthStore(s => s.permissions.includes('analytics.view'))
  const { language } = useTranslation(), t = (en,sq) => language === 'sq' ? sq : en
  const [data,setData] = useState(null)
  useEffect(() => {
    if (!allowed) return
    let live = true
    setData(null)
    apiRequest(`/analytics/intelligence/products/${productId}?horizon=30`)
      .then(value => { if(live) setData(value) }).catch(() => {})
    return () => { live = false }
  }, [productId,allowed])
  if (!allowed || !data) return null
  const current = data.current, supported = data.prediction && current?.coverage_supported && !data.stale
  const message = !supported ? t('More current evidence is needed before recommending a purchase.','Nevojiten të dhëna më të plota dhe aktuale para rekomandimit të blerjes.')
    : current.stockout_date ? `${t('Possible stock shortage from','Mungesë e mundshme stoku nga')} ${formatOrderDate(current.stockout_date,language)}.`
    : t('No stock shortage is projected within the supported forecast period.','Nuk parashikohet mungesë stoku brenda periudhës së mbështetur të parashikimit.')
  return <aside className="product-context-insight"><div><strong>{t('AIMS stock insight','Pasqyra e stokut AIMS')}</strong><p>{message}</p><small>{t('Advisory forecast, not a guarantee. Review the evidence before ordering.','Parashikim këshillues, jo garanci. Shqyrtoni të dhënat para porositjes.')}</small></div><Link to={`/inventory-intelligence?product=${productId}`} onClick={onOpen}>{t('View evidence','Shiko të dhënat')} →</Link></aside>
}
