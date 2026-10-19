import { Link } from 'react-router-dom'

// Presentation only. Callers supply states/actions from existing permissions and services.
export function BusinessFacts({ facts, label }) {
  return <dl className="business-facts" aria-label={label}>{facts.map(fact => <div key={fact.label}>
    <dt>{fact.label}</dt><dd>{fact.to ? <Link to={fact.to}>{fact.value ?? '—'}</Link> : fact.value ?? '—'}</dd>
    {fact.note && <small>{fact.note}</small>}
  </div>)}</dl>
}

export function NextStep({ title, children, action, tone = 'active' }) {
  return <section className={'next-step next-step-' + tone} aria-label={title}>
    <div><strong>{title}</strong>{children && <p>{children}</p>}</div>{action && <div className="next-step-action">{action}</div>}
  </section>
}

export function WorkflowSteps({ steps, current, label }) {
  return <ol className="workflow-steps" aria-label={label}>{steps.map((step, index) => <li key={step} aria-current={index === current ? 'step' : undefined} data-complete={current > index}>
    <span aria-hidden="true">{current > index ? '✓' : index + 1}</span>{step}
  </li>)}</ol>
}
