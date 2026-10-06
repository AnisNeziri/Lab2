import {useTranslation} from '../hooks/useTranslation'
export default function AutomationMetrics({rule}){
  const {language}=useTranslation(),sq=language==='sq',m=rule.metrics
  if(!m)return null
  const statuses=m.statuses || {},completed=(Number(statuses.succeeded)||0)+(Number(statuses.failed)||0)+(Number(statuses.blocked)||0)
  return <div className="auto-result"><p>{sq?'Krijuar nga':'Created by'}: {rule.created_by_name || '—'}</p><p>{sq?'Sukses / Dështuar / Anashkaluar':'Succeeded / Failed / Skipped'}: {statuses.succeeded || 0} / {statuses.failed || 0} / {statuses.skipped || 0}</p><p>{sq?'Shkalla e suksesit':'Success rate'}: {completed?`${Math.round((Number(statuses.succeeded)||0)*100/completed)}%`:'—'}</p><p>{sq?'Detyra të krijuara / përfunduara':'Tasks created / completed'}: {m.tasks_created} / {m.tasks_completed}</p></div>
}
