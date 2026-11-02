import WorkspaceModal from './WorkspaceModal'
import { useTranslation } from '../hooks/useTranslation'

export default function ConfirmDialog({ title, children, confirmLabel, onConfirm, onClose, busy = false }) {
  const { language } = useTranslation()
  return <WorkspaceModal title={title} onClose={onClose} busy={busy} className="workspace-confirm-dialog">
    <div className="workspace-dialog-content">{children}</div>
    <footer className="workspace-actions"><button type="button" disabled={busy} onClick={onClose}>{language === 'sq' ? 'Anulo' : 'Cancel'}</button><button type="button" className="workspace-danger" disabled={busy} onClick={onConfirm}>{confirmLabel}</button></footer>
  </WorkspaceModal>
}
