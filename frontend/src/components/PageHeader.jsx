export default function PageHeader({ title, description, eyebrow, actions }) {
  return <header className="page-header workspace-page-header">
    <div>{eyebrow && <p className="workspace-eyebrow">{eyebrow}</p>}<h1>{title}</h1>{description && <p>{description}</p>}</div>
    {actions && <div className="workspace-header-actions">{actions}</div>}
  </header>
}
