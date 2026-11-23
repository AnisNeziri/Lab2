import { useState } from 'react'
import { apiRequest } from '../../api/client'
import { useFinance } from './FinanceShared'

export default function ExchangeRateLookup() {
  const { currency, t } = useFinance()
  const [quote, setQuote] = useState('USD')
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  async function lookup(event) {
    event.preventDefault()
    setLoading(true)
    setResult(null)
    setError('')
    try {
      const params = new URLSearchParams({ base: currency, quote })
      setResult(await apiRequest(`/accounting/reference-rate?${params}`))
    } catch {
      setError(t('The reference rate is unavailable. Check the currency code or try again later.', 'Kursi referues nuk është i disponueshëm. Kontrollo kodin ose provo përsëri më vonë.'))
    } finally {
      setLoading(false)
    }
  }

  return <form className="fc-rate-lookup" onSubmit={lookup}>
    <label htmlFor="finance-rate-currency">{t('Currency code', 'Kodi i valutës')}</label>
    <input id="finance-rate-currency" value={quote} onChange={event => setQuote(event.target.value.toUpperCase())} maxLength={3} pattern="[A-Z]{3}" required disabled={loading}/>
    <button type="submit" className="secondary" disabled={loading}>{t('Look up ECB reference rate', 'Kërko kursin referues të BQE-së')}</button>
    {loading && <small role="status">{t('Looking up the reference rate…', 'Duke kërkuar kursin referues…')}</small>}
    {error && <small role="alert">{error}</small>}
    {result && <output>1 {result.base_currency} = {result.rate} {result.quote_currency} · ECB · {result.rate_date?.slice(0, 10)}</output>}
    <small>{t('Reference only. For a posting, use the documented rate and date for that transaction.', 'Vetëm për referencë. Për postim, përdor kursin dhe datën e dokumentuar për atë transaksion.')}</small>
  </form>
}
