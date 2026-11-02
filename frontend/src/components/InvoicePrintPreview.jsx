import { useEffect, useRef, useState } from 'react'
import { Printer, Download, RefreshCw, FileText, AlertCircle } from 'lucide-react'
import WorkspaceModal from './WorkspaceModal'
import { getInvoicePrint } from '../api/invoices'
import { useTranslation } from '../hooks/useTranslation'
import { useAuthStore } from '../store/authStore'
import './InvoicePrintPreview.css'

export default function InvoicePrintPreview(props) {
  const user=useAuthStore(s=>s.user),permissions=useAuthStore(s=>s.permissions)
  if(!user||!permissions.includes('invoices.manage')||(props.invoice.company_id&&Number(props.invoice.company_id)!==Number(user.company_id)))return null
  return <PrintPreview key={`${user.company_id}:${user.id}`} {...props}/>
}
function PrintPreview({invoice,locale='en',onClose,onPdf,pdfBusy=false,pdfError=''}) {
  const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en
  const [printLocale,setPrintLocale]=useState(locale),[html,setHtml]=useState(''),[error,setError]=useState(''),[loading,setLoading]=useState(true),[ready,setReady]=useState(false),[requested,setRequested]=useState(false),[retry,setRetry]=useState(0)
  const frame=useRef(null),sequence=useRef(0)
  useEffect(()=>{
    const ticket=++sequence.current,controller=new AbortController();setLoading(true);setReady(false);setError('');setHtml('');setRequested(false)
    getInvoicePrint(invoice.id,printLocale,controller.signal).then(value=>{if(ticket===sequence.current)setHtml(value)}).catch(e=>{if(ticket===sequence.current&&!controller.signal.aborted)setError(e.message)}).finally(()=>{if(ticket===sequence.current)setLoading(false)})
    return()=>{sequence.current++;controller.abort()}
  },[invoice.id,printLocale,retry])
  const loaded=async()=>{
    const ticket=sequence.current,doc=frame.current?.contentDocument
    if(!doc||!html)return
    try {await doc.fonts?.ready;if(ticket===sequence.current)setReady(true)}catch {if(ticket===sequence.current)setError(t('The document could not finish loading. Retry or download its PDF.','Dokumenti nuk u ngarkua plotësisht. Provo përsëri ose shkarko PDF-në.'))}
  }
  const print=()=>{
    if(!ready)return
    try {frame.current.contentWindow.focus();frame.current.contentWindow.print();setRequested(true)}catch {setError(t('Printing is unavailable in this browser. Download the PDF and print it from your PDF viewer.','Printimi nuk është në dispozicion në këtë shfletues. Shkarko PDF-në dhe printoje nga lexuesi i PDF-ve.'))}
  }
  return <WorkspaceModal title={t('Print invoice','Printo faturën')+' · '+invoice.invoice_number} onClose={onClose} className="invoice-print-preview" closeOnBackdrop>
    <div className="invoice-print-toolbar"><label>{t('Document language','Gjuha e dokumentit')}<select value={printLocale} onChange={e=>setPrintLocale(e.target.value)}><option value="en">English</option><option value="sq">Shqip</option><option value="bilingual">Shqip / English</option></select></label><div className="workspace-actions"><button type="button" className="secondary" disabled={pdfBusy} onClick={()=>onPdf(printLocale)}><Download size={16}/>{t('Download PDF','Shkarko PDF')}</button><button type="button" className="primary" disabled={!ready||loading} onClick={print}><Printer size={16}/>{t('Print','Printo')}</button></div></div>
    <p className="invoice-print-hint"><FileText size={16}/>{t('A4 portrait · issued document data · independent of your screen theme. Use the PDF download if your browser or desktop cannot open a print dialog.','A4 vertikale · të dhënat e dokumentit të lëshuar · e pavarur nga tema e ekranit. Shkarko PDF-në nëse shfletuesi ose aplikacioni nuk hap dialogun e printimit.')}</p>
    <p className="invoice-print-pan-hint">{t('The preview keeps its A4 width. Scroll inside the document to inspect all columns on a small screen.','Parapamja ruan gjerësinë A4. Në ekran të vogël, lëviz brenda dokumentit për të parë të gjitha kolonat.')}</p>
    {error&&<div className="invoice-print-error" role="alert"><AlertCircle size={18}/><p>{error}</p><button type="button" className="secondary" onClick={()=>setRetry(n=>n+1)}><RefreshCw size={16}/>{t('Try again','Provo përsëri')}</button></div>}
    {pdfError&&<div className="invoice-print-error" role="alert"><AlertCircle size={18}/><p>{pdfError}</p></div>}
    {loading&&<div className="invoice-print-loading" role="status">{t('Preparing printable document…','Duke përgatitur dokumentin për printim…')}<div className="workspace-skeleton"/></div>}
    {html&&<iframe ref={frame} className="invoice-print-frame" title={t('Printable invoice','Fatura për printim')} srcDoc={html} sandbox="allow-same-origin allow-modals" onLoad={loaded}/>}
    {requested&&<p className="invoice-print-hint" role="status">{t('Printing requested. Choose a printer or Save as PDF in the system dialog. Physical printing is handled by your device.','Printimi u kërkua. Zgjidh printerin ose Ruaj si PDF në dialogun e sistemit. Printimi fizik trajtohet nga pajisja jote.')}</p>}
  </WorkspaceModal>
}
