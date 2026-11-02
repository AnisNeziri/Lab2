import { useLocation } from 'react-router-dom'
import { navigationContext } from '../config/navigation'
import { workspaceIcons } from './workspaceIcons'

export default function PageHeader({ title, description, eyebrow, actions, status, icon, className='' }) {
  const location=useLocation(),Icon=icon||((typeof title==='string')&&workspaceIcons[navigationContext(location.pathname+location.search)?.entry?.icon])
  return <header className={'page-header workspace-page-header '+className}>
    <div className="workspace-heading-group">{Icon&&<span className="workspace-heading-symbol" aria-hidden="true"><Icon size={22} strokeWidth={1.75}/></span>}<div>{eyebrow && <p className="workspace-eyebrow">{eyebrow}</p>}<div className="workspace-title"><h1>{title}</h1>{status}</div>{description && <p>{description}</p>}</div></div>
    {actions && <div className="workspace-header-actions">{actions}</div>}
  </header>
}
