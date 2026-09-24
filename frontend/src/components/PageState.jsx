import { Link } from 'react-router-dom'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'

export default function PageState({ forbidden = false }) {
  const { t } = useTranslation()
  const authenticated = useAuthStore((state) => state.isAuthenticated)
  return <section className="page-state" role="status">
    <h1>{t(forbidden ? 'page.forbidden' : 'page.notFound')}</h1>
    <p>{t(forbidden ? 'page.forbiddenHelp' : 'page.notFoundHelp')}</p>
    <Link to={authenticated ? '/dashboard' : '/login'}>{t(authenticated ? 'nav.dashboard' : 'page.login')}</Link>
  </section>
}
