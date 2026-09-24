import { useEffect, useRef, useState } from 'react'

// Loaded only for PDF previews. Worker and document bytes stay local to AIMS.
export default function DocumentPdfPreview({ url, t }) {
  const host = useRef(null)
  const canvas = useRef(null)
  const [pdf, setPdf] = useState(null)
  const [pageNumber, setPageNumber] = useState(1)
  const [error, setError] = useState('')
  const [rendering, setRendering] = useState(true)
  const [text, setText] = useState('')

  useEffect(() => {
    let active = true, task
    setError(''); setPdf(null); setPageNumber(1)
    import('pdfjs-dist/legacy/build/pdf.mjs').then(library => {
      if (!active) return
      library.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/legacy/build/pdf.worker.min.mjs', import.meta.url).href
      task = library.getDocument({ url, isEvalSupported: false, enableXfa: false, maxImageSize: 16_777_216 })
      return task.promise.then(document => { if (active) setPdf(document) })
    }).catch(e => { if (active) { setError(e.message); setRendering(false) } })
    return () => { active = false; task?.destroy().catch(() => {}) }
  }, [url])

  useEffect(() => {
    if (!pdf) return
    let active = true, task
    setRendering(true); setText(''); setError('')
    pdf.getPage(pageNumber).then(async page => {
      if (!active) return
      const natural = page.getViewport({ scale: 1 })
      const width = Math.max(200, Math.min(1100, host.current.clientWidth - 24))
      const scale = Math.min(2, width / natural.width, Math.sqrt(2_000_000 / (natural.width * natural.height)))
      const viewport = page.getViewport({ scale })
      const outputScale = Math.min(window.devicePixelRatio || 1, 2)
      const target = canvas.current
      target.width = Math.ceil(viewport.width * outputScale)
      target.height = Math.ceil(viewport.height * outputScale)
      target.style.width = `${viewport.width}px`
      target.style.height = `${viewport.height}px`
      task = page.render({ canvasContext: target.getContext('2d'), viewport, transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0] })
      await task.promise
      if (!active) return
      setRendering(false)
      const content = await page.getTextContent()
      if (active) setText(content.items.map(item => item.str || '').join(' '))
    }).catch(e => { if (active && e.name !== 'RenderingCancelledException') { setError(e.message); setRendering(false) } })
    return () => { active = false; task?.cancel() }
  }, [pdf, pageNumber])

  return <section className="document-pdf" ref={host} aria-label={t('PDF preview','Pamja PDF')}>
    <nav aria-label={t('PDF pages','Faqet PDF')}>
      <button disabled={!pdf || rendering || pageNumber <= 1} onClick={() => setPageNumber(n => n - 1)}>{t('Previous','Prapa')}</button>
      <span>{t('Page','Faqja')} {pageNumber} / {pdf?.numPages || '—'}</span>
      <button disabled={!pdf || rendering || pageNumber >= pdf.numPages} onClick={() => setPageNumber(n => n + 1)}>{t('Next','Tjetra')}</button>
    </nav>
    {error && <p role="alert">{t('Preview unavailable. You can close this view and download the original.','Pamja nuk është e disponueshme. Mbyll pamjen dhe shkarko origjinalin.')} {error}</p>}
    {rendering && !error && <p role="status">{t('Rendering PDF…','Duke përgatitur PDF…')}</p>}
    <div className="document-pdf-sheet"><canvas ref={canvas} role="img" aria-label={t('PDF page','Faqja PDF')} aria-busy={rendering}/></div>
    <p className="document-pdf-text">{text}</p>
  </section>
}
