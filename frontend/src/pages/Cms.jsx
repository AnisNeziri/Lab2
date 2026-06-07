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
    return <p className="page-message">Loading site content...</p>
  }

  return (
    <main className="cms-page page-stack">
      <section className="card">
        <h2>Site Content</h2>
        <p className="page-intro">
          Edit the public landing page text. These changes affect what visitors see on the homepage,
          not your inventory business data.
        </p>

        {error && <div className="form-error-banner">{error}</div>}
        {successMessage && <div className="success-banner">{successMessage}</div>}
      </section>

      {pages.map((page) => (
        <section key={page.id} className="card cms-block">
          <h3>{SECTION_LABELS[page.slug] || page.slug}</h3>

          <div className="form-grid">
            <label>
              Display title
              <input
                value={page.title}
                onChange={(e) => updateLocal(page.id, 'title', e.target.value)}
              />
            </label>

            <label>
              Content
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
              Published on landing page
            </label>

            <div className="form-actions">
              <button type="button" onClick={() => handleSave(page)} disabled={savingId === page.id}>
                {savingId === page.id ? 'Saving...' : 'Save section'}
              </button>
            </div>
          </div>
        </section>
      ))}
    </main>
  )
}
