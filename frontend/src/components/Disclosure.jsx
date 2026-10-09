import { ChevronDown } from 'lucide-react'

/** Native disclosure keeps keyboard behavior and form state without extra listeners. */
export default function Disclosure({ title, description, children, className = '', ...props }) {
  return <details className={`workspace-disclosure ${className}`} {...props}>
    <summary><span>{title}{description && <small>{description}</small>}</span><ChevronDown size={16} aria-hidden="true"/></summary>
    <div className="workspace-disclosure-body">{children}</div>
  </details>
}
