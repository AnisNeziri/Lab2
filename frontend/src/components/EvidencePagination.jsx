export default function EvidencePagination({ pagination = {}, page, onPage, language }) {
  const rows = Object.values(pagination)
  const lastPage = Math.max(1, ...rows.map(row => row.last_page))
  if (!rows.length || lastPage <= 1) return null
  const sq = language === 'sq'
  return <nav className="evidence-pagination" aria-label={sq ? 'Faqet e të dhënave' : 'Evidence pages'}>
    <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>{sq ? 'E mëparshme' : 'Previous'}</button>
    <span aria-live="polite">{sq ? 'Faqja' : 'Page'} {page} / {lastPage}</span>
    <button type="button" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>{sq ? 'Tjetra' : 'Next'}</button>
  </nav>
}

