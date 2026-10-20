import {useState} from 'react'
import {useTranslation} from '../hooks/useTranslation'
import {apiRequest} from '../api/client'
import WorkspaceModal from './WorkspaceModal'

export default function ProcurementClosureControls({request,rfq,onChanged,busy:parentBusy}) {
  const {language}=useTranslation(),sq=language==='sq',t=(en,al)=>sq?al:en
  const [action,setAction]=useState(null),[reason,setReason]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('')
  const unused=request?.status==='draft'&&!request.approval_request_id&&!request.rfqs?.length
  const withdraw=['submitted','approved','rejected','sourcing'].includes(request?.status)&&!(request.rfqs||[]).some(r=>!['closed','cancelled'].includes(r.status))
  const close=rfq&&!['closed','cancelled','awarded','partially_awarded'].includes(rfq.status)&&!rfq.awards?.length
  const title=action==='delete'?t('Delete unused draft','Fshi draftin e papërdorur'):action==='withdraw'?t('Withdraw request','Tërhiq kërkesën'):action==='cancelled'?t('Cancel RFQ','Anulo kërkesën për oferta'):t('Close RFQ','Mbyll kërkesën për oferta')
  const submit=async e=>{e.preventDefault();setBusy(true);setError('');try{
    const path=action==='delete'?`/purchase-requests/${request.id}`:action==='withdraw'?`/purchase-requests/${request.id}/withdraw`:`/rfqs/${rfq.id}/close`
    await apiRequest(path,{method:action==='delete'?'DELETE':'POST',...(action==='delete'?{}:{body:JSON.stringify({reason,status:action})})})
    await onChanged(action==='delete');setAction(null);setReason('')
  }catch(cause){setError(cause.message)}finally{setBusy(false)}}
  if(!unused&&!withdraw&&!close)return null
  return <section className="procurement-card"><div className="procurement-actions">
    {unused&&<button disabled={parentBusy} onClick={()=>setAction('delete')}>{t('Delete unused draft','Fshi draftin e papërdorur')}</button>}
    {withdraw&&<button disabled={parentBusy} onClick={()=>setAction('withdraw')}>{t('Withdraw request','Tërhiq kërkesën')}</button>}
    {close&&<><button disabled={parentBusy} onClick={()=>setAction('closed')}>{t('Close RFQ','Mbyll kërkesën për oferta')}</button><button disabled={parentBusy} onClick={()=>setAction('cancelled')}>{t('Cancel RFQ','Anulo kërkesën për oferta')}</button></>}
  </div>{action&&<WorkspaceModal title={title} busy={busy} onClose={()=>setAction(null)} warnOnUnsaved={Boolean(reason)}><form className="opening-form" onSubmit={submit}><p>{t('Approval, quote and purchase order history is preserved. Downstream awards prevent destructive closure.','Historia e miratimeve, ofertave dhe porosive ruhet. Dhëniet e mëvonshme ndalojnë mbylljen shkatërruese.')}</p>{action!=='delete'&&<label>{t('Reason','Arsyeja')}<textarea required minLength={5} maxLength={1000} value={reason} onChange={e=>setReason(e.target.value)}/></label>}{error&&<p role="alert">{error}</p>}<button disabled={busy} type="submit">{title}</button></form></WorkspaceModal>}</section>
}
