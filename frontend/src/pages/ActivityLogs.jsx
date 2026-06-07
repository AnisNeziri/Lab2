import { useEffect, useState } from 'react'
import { getActivityLogs } from '../api/activityLogs'
import { History, ArrowLeft, ArrowRight } from 'lucide-react'

function ActivityLogs() {
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
        <p className="page-intro">Loading activity logs...</p>
      </main>
    )
  }

  return (
    <main className="activity-logs-page page-stack">
      <section className="card">
        <h2>Activity logs</h2>
        <p className="page-intro">System audit trail of modifications on products and resources.</p>
      </section>

      {error && (
        <div className="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl">
          {error}
        </div>
      )}

      <div className="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-slate-200">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Timestamp</th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Action</th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Operator</th>
                <th className="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Description</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-slate-100">
              {logs.length === 0 ? (
                <tr>
                  <td colSpan="4" className="px-6 py-10 text-center text-slate-400">
                    No activity logs recorded yet.
                  </td>
                </tr>
              ) : (
                logs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-50/50 transition-colors">
                    <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                      {new Date(log.created_at).toLocaleString()}
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
          <div className="bg-slate-50 px-6 py-4 border-t border-slate-200 flex items-center justify-between">
            <button
              onClick={() => setCurrentPage(prev => Math.max(prev - 1, 1))}
              disabled={currentPage === 1}
              className="inline-flex items-center gap-1 bg-white border border-slate-200 text-slate-600 text-sm px-3 py-1.5 rounded-lg font-medium hover:bg-slate-50 disabled:opacity-50 transition-colors"
            >
              <ArrowLeft className="w-4 h-4" />
              Previous
            </button>
            <span className="text-slate-500 text-sm">
              Page <span className="font-semibold text-slate-800">{currentPage}</span> of <span className="font-semibold text-slate-800">{totalPages}</span>
            </span>
            <button
              onClick={() => setCurrentPage(prev => Math.min(prev + 1, totalPages))}
              disabled={currentPage === totalPages}
              className="inline-flex items-center gap-1 bg-white border border-slate-200 text-slate-600 text-sm px-3 py-1.5 rounded-lg font-medium hover:bg-slate-50 disabled:opacity-50 transition-colors"
            >
              Next
              <ArrowRight className="w-4 h-4" />
            </button>
          </div>
        )}
      </div>
    </main>
  )
}

export default ActivityLogs
