import {DashboardGlyph,TasksGlyph} from './icons/AimsGlyph'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, X } from 'lucide-react'
import StatusBadge from './StatusBadge'
import { attentionPreview, attentionTitle, taskTitle } from '../lib/actionCenterPresentation'
import { businessDate } from '../utils/businessFormat'

export default function OperatingBrief({ brief, t, language, priority, issueDescription, onTask, onClose }) {
  const [allTasks, setAllTasks] = useState(false), [allIssues, setAllIssues] = useState(false)
  const tasks = brief.tasks || [], issues = attentionPreview(brief.issues)
  return <section className="auto-panel operating-brief" aria-label={t('Today in AIMS','Sot në AIMS')}>
    <header className="operating-brief-heading">
      <div><h2><DashboardGlyph size={20}/>{t('Today in AIMS','Sot në AIMS')}</h2><p>{t('A snapshot of work across your permitted teams, ordered by urgency. Task filters below apply to your task list.','Përmbledhje e punëve të ekipeve ku ke qasje, sipas urgjencës. Filtrat më poshtë vlejnë për listën e detyrave.')}</p></div>
      <button type="button" className="operating-brief-close" aria-label={t('Close daily brief','Mbyll përmbledhjen ditore')} onClick={onClose}><X size={18}/></button>
    </header>
    <div className="operating-brief-columns">
      <section aria-label={t('Tasks to review','Detyra për shqyrtim')}><h3><TasksGlyph size={16}/>{t('Tasks to review','Detyra për shqyrtim')} <span>{tasks.length}</span></h3>
        <ul className="operating-brief-list">{(allTasks?tasks:tasks.slice(0,4)).map(task=><li key={task.id}>
          <button type="button" className="operating-brief-task" onClick={()=>onTask(task.id)}><span>{taskTitle(task,language)}</span><ArrowRight size={16}/></button>
          <div className="operating-brief-meta"><StatusBadge value={task.priority} label={priority(task.priority)} tone={['high','urgent'].includes(task.priority)?'warning':'neutral'}/>{task.due_at&&<span>{t('Due','Afati')}: {businessDate(task.due_at,language)}</span>}</div>
        </li>)}</ul>
        {!tasks.length&&<p className="auto-hint">{t('No tasks in this brief.','Nuk ka detyra në këtë përmbledhje.')}</p>}
        {tasks.length>4&&<button type="button" className="secondary" onClick={()=>setAllTasks(v=>!v)}>{allTasks?t('Show fewer tasks','Shfaq më pak detyra'):t('Show all tasks in brief','Shfaq të gjitha detyrat e përmbledhjes')}</button>}
      </section>
      <section aria-label={t('Issues at the source','Probleme në burim')}><h3>{t('Issues at the source','Probleme në burim')} <span>{issues.length}</span></h3>
        <ul className="operating-brief-list">{(allIssues?issues:issues.slice(0,4)).map(issue=><li key={issue.kind+':'+issue.id}><Link to={issue.url} className="operating-brief-link"><span>{attentionTitle(issue,language)}</span><ArrowRight size={16}/></Link><p>{issueDescription(issue)}</p></li>)}</ul>
        {!issues.length&&<p className="auto-hint">{t('No issues in this brief.','Nuk ka probleme në këtë përmbledhje.')}</p>}
        {issues.length>4&&<button type="button" className="secondary" onClick={()=>setAllIssues(v=>!v)}>{allIssues?t('Show fewer issues','Shfaq më pak probleme'):t('Show all issues in brief','Shfaq të gjitha problemet e përmbledhjes')}</button>}
      </section>
    </div>
    <p className="operating-brief-footnote">{t('Snapshot','Gjendja më')}: {businessDate(brief.as_of,language,true)} · {t('Open the source to review all records.','Hap burimin për të shqyrtuar të gjitha regjistrimet.')}</p>
  </section>
}
