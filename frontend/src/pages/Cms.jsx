import { useTranslation } from '../hooks/useTranslation'
import { useEffect, useState } from 'react'
import { getCmsPages, updateCmsPage } from '../api/cms'

const SECTION_LABELS = {
  'landing-hero-title': 'Landing headline',
  'landing-hero-subtitle': 'Landing subtitle',
  'feature-realtime': 'Feature: Real-time tracking',
  'feature-analytics': 'Feature: Analytics',
  'feature-integration': 'Feature: Integration',
  'about-section': 'About section text',
}

export default function Cms() {
  const { language } = useTranslation(), text = (en, sq) => language === 'sq' ? sq : en
  const [pages, setPages] = useState([])
  const [error, setError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [savingId, setSavingId] = useState(null)

  const loadPages = async () => {
    setLoading(true)
    try {
      const data = await getCmsPages()
      setPages(data)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadPages()
  }, [])

  const handleSave = async (page) => {
    setSavingId(page.id)
    setError('')
    setSuccessMessage('')

    try {
      await updateCmsPage(page.id, {
        title: page.title,
        content: page.content,
        is_published: page.is_published,
      })
      setSuccessMessage('Landing page content updated.')
      await loadPages()
    } catch (err) {
      setError(err.message || 'Could not save content.')
    } finally {
      setSavingId(null)
    }
  }

  const updateLocal = (id, field, value) => {
    setPages((current) =>
      current.map((page) => (page.id === id ? { ...page, [field]: value } : page))
    )
  }

  if (loading) {
    return <p className="page-message">{text("Loading site content...", "Duke ngarkuar përmbajtjen…")}</p>
  }

  return (
    <main className="cms-page page-stack">
      <section className="card">
        <h1>{text("Site Content", "Përmbajtja e faqes")}</h1>
        <p className="page-intro">
          {text('Edit the public landing page text. These changes do not affect your inventory data.','Ndryshoni tekstin e faqes publike. Këto ndryshime nuk ndikojnë në të dhënat e inventarit.')}
        </p>

        {error && <div className="form-error-banner">{error}</div>}
        {successMessage && <div className="success-banner">{successMessage}</div>}
      </section>

      {pages.map((page) => (
        <section key={page.id} className="card cms-block">
          <h3>{SECTION_LABELS[page.slug] || page.slug}</h3>

          <div className="form-grid">
            <label>
              {text("Display title", "Titulli i shfaqur")}
              <input
                value={page.title}
                onChange={(e) => updateLocal(page.id, 'title', e.target.value)}
              />
            </label>

            <label>
              {text("Content", "Përmbajtja")}
              <textarea
                rows={4}
                value={page.content}
                onChange={(e) => updateLocal(page.id, 'content', e.target.value)}
              />
            </label>

            <label className="filter-checkbox">
              <input
                type="checkbox"
                checked={page.is_published}
                onChange={(e) => updateLocal(page.id, 'is_published', e.target.checked)}
              />
              {text("Published on landing page", "Publikuar në faqen kryesore")}
            </label>

            <div className="form-actions">
              <button type="button" onClick={() => handleSave(page)} disabled={savingId === page.id}>
                {savingId === page.id ? text('Saving…','Duke ruajtur…') : text('Save section','Ruaj pjesën')}
              </button>
            </div>
          </div>
        </section>
      ))}
    </main>
  )
}
