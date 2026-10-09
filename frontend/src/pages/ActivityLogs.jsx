import { useTranslation } from '../hooks/useTranslation'
import { useEffect, useState } from 'react'
import { getActivityLogs } from '../api/activityLogs'
import { History, ArrowLeft, ArrowRight } from 'lucide-react'

function ActivityLogs() {
  const { language } = useTranslation(), text = (en, sq) => language === 'sq' ? sq : en
  const [logs, setLogs] = useState([])
  const [currentPage, setCurrentPage] = useState(1)
  const [totalPages, setTotalPages] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    async function loadLogs() {
      try {
        setLoading(true)
        setError('')
        const data = await getActivityLogs(currentPage)
        setLogs(data.data)
        setTotalPages(data.last_page)
      } catch (err) {
        setError('Failed to load activity logs.')
      } finally {
        setLoading(false)
      }
    }
    loadLogs()
  }, [currentPage])

  if (loading) {
    return (
      <main className="activity-logs-page page-stack">
        <p className="page-intro">{text("Loading activity logs...", "Duke ngarkuar aktivitetet…")}</p>
      </main>
    )
  }

  return (
    <main className="activity-logs-page page-stack">
      <section className="card">
        <h1>{text("Activity logs", "Regjistri i aktiviteteve")}</h1>
        <p className="page-intro">{text("System audit trail of modifications on products and resources.", "Historiku i ndryshimeve në produkte dhe burime.")}</p>
      </section>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl">
          {error}
        </div>
      )}

      <div className="activity-log-table-card rounded-2xl shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="activity-log-table min-w-full">
            <thead>
              <tr>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">{text("Timestamp", "Data dhe ora")}</th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">{text("Action", "Veprimi")}</th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">{text("Operator", "Përdoruesi")}</th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">{text("Description", "Përshkrimi")}</th>
              </tr>
            </thead>
            <tbody>
              {logs.length === 0 ? (
                <tr>
                  <td colSpan="4" className="px-6 py-10 text-center text-slate-400">
                    {text("No activity logs recorded yet.", "Ende nuk ka aktivitete të regjistruara.")}
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id}>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                      {new Date(log.created_at).toLocaleString(language === 'sq' ? 'sq-AL' : 'en-GB')}
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800">
                      <span className={`inline-flex px-2 py-0.5 rounded-full text-xs font-semibold ${
                        log.action.includes('created') ? 'bg-emerald-50 text-emerald-700' :
                        log.action.includes('deleted') ? 'bg-red-50 text-red-700' :
                        'bg-blue-50 text-blue-700'
                      }`}>
                        {log.action}
                      </span>
                    </td>
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-medium">
                      {log.user?.name || 'System / Database Seeder'}
                    </td>
                    <td className="px-6 py-4 text-sm text-slate-600">
                      {log.description}
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {/* Pagination Controls */}
        {totalPages > 1 && (
          <div className="activity-log-pagination px-6 py-4 flex items-center justify-between">
            <button
              onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
              disabled={currentPage === 1}
              className="inline-flex items-center gap-1 bg-white border border-slate-200 text-slate-600 text-sm px-3 py-1.5 rounded-lg font-medium hover:bg-slate-50 disabled:opacity-50 transition-colors"
            >
              <ArrowLeft className="w-4 h-4" />
              {text("Previous", "Para")}
            </button>
            <span className="text-slate-500 text-sm">
              {text('Page','Faqja')} <span className="font-semibold text-slate-800">{currentPage}</span> / <span className="font-semibold text-slate-800">{totalPages}</span>
            </span>
            <button
              onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
              disabled={currentPage === totalPages}
              className="inline-flex items-center gap-1 bg-white border border-slate-200 text-slate-600 text-sm px-3 py-1.5 rounded-lg font-medium hover:bg-slate-50 disabled:opacity-50 transition-colors"
            >
              {text("Next", "Tjetër")}
              <ArrowRight className="w-4 h-4" />
            </button>
          </div>
        )}
      </div>
    </main>
  )
}

export default ActivityLogs
