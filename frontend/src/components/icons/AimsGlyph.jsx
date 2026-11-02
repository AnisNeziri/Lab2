import { forwardRef, useId } from 'react'
import { CircleHelp } from 'lucide-react'
import { glyphPaths } from './glyphPaths'
import './AimsGlyph.css'

/** Semantic, decorative by default. Label an icon only when it stands alone. */
export const AimsGlyph = forwardRef(function AimsGlyph({ name, size=20, strokeWidth, label, title, active=false, disabled=false, accent=false, motion, className='', ...props }, ref) {
  const id=useId(), definition=glyphPaths[name], text=label||props['aria-label']||title
  const common={...props,ref,width:size,height:size,focusable:false,'aria-hidden':props['aria-hidden']??(text?undefined:true),'aria-label':text||undefined,role:text?'img':undefined,'aria-disabled':disabled||undefined,className:`aims-glyph ${className}`.trim(),'data-glyph':name,'data-active':active||undefined,'data-disabled':disabled||undefined,'data-accent':accent||undefined,'data-motion':motion==='working'?'working':undefined}
  if(!definition)return <CircleHelp {...common} size={size} strokeWidth={strokeWidth||1.7} data-fallback="lucide"/>
  return <svg {...common} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={strokeWidth||(Number(size)<=16?1.6:1.7)} strokeLinecap="round" strokeLinejoin="round" aria-describedby={[props['aria-describedby'], title&&`${id}-title`].filter(Boolean).join(' ')||undefined}>
    {title&&<title id={`${id}-title`}>{title}</title>}
    {(Number(size)<=16&&definition.small||definition.paths).map((d,i)=><path key={i} d={d} className={i===1?'aims-glyph-detail':undefined}/>)}
  </svg>
})

const glyph=name=>forwardRef(function Glyph(props,ref){return <AimsGlyph {...props} name={name} ref={ref}/>})
export const DashboardGlyph=glyph('dashboard')
export const ProductsGlyph=glyph('products')
export const InventoryGlyph=glyph('inventory')
export const WarehouseGlyph=glyph('warehouse')
export const OrdersGlyph=glyph('orders')
export const SalesGlyph=glyph('sales')
export const CustomersGlyph=glyph('customers')
export const SuppliersGlyph=glyph('suppliers')
export const PurchasingGlyph=glyph('purchasing')
export const ShipmentsGlyph=glyph('shipments')
export const AccountingGlyph=glyph('accounting')
export const PaymentsGlyph=glyph('payments')
export const DocumentsGlyph=glyph('documents')
export const ReportsGlyph=glyph('reports')
export const ReceivingGlyph=glyph('receiving')
export const PickingGlyph=glyph('picking')
export const DispatchGlyph=glyph('dispatch')
export const TransfersGlyph=glyph('transfers')
export const StockCountGlyph=glyph('stock-count')
export const ReturnsGlyph=glyph('returns')
export const AutomationGlyph=glyph('automation')
export const TasksGlyph=glyph('tasks')
export const ApprovalsGlyph=glyph('approvals')
export const AskAimsGlyph=glyph('ask-aims')
export const IntelligenceGlyph=glyph('intelligence')
export const SearchGlyph=glyph('search')
export const SecurityGlyph=glyph('security')
export const EmployeesGlyph=glyph('employees')
export const PayrollGlyph=glyph('payroll')
export const IntegrationsGlyph=glyph('integrations')
export const SettingsGlyph=glyph('settings')
export const SystemHealthGlyph=glyph('system-health')
export const NotificationsGlyph=glyph('notifications')
export const QuotationsGlyph=glyph('quotations')
export const LocationsGlyph=glyph('locations')
export const ScanGlyph=glyph('scan')

export const aimsGlyphs={dashboard:DashboardGlyph,products:ProductsGlyph,inventory:InventoryGlyph,warehouse:WarehouseGlyph,orders:OrdersGlyph,sales:SalesGlyph,customers:CustomersGlyph,suppliers:SuppliersGlyph,purchasing:PurchasingGlyph,shipments:ShipmentsGlyph,accounting:AccountingGlyph,payments:PaymentsGlyph,documents:DocumentsGlyph,reports:ReportsGlyph,receiving:ReceivingGlyph,picking:PickingGlyph,dispatch:DispatchGlyph,transfers:TransfersGlyph,'stock-count':StockCountGlyph,returns:ReturnsGlyph,automation:AutomationGlyph,tasks:TasksGlyph,approvals:ApprovalsGlyph,'ask-aims':AskAimsGlyph,intelligence:IntelligenceGlyph,search:SearchGlyph,security:SecurityGlyph,employees:EmployeesGlyph,payroll:PayrollGlyph,integrations:IntegrationsGlyph,settings:SettingsGlyph,'system-health':SystemHealthGlyph,notifications:NotificationsGlyph,quotations:QuotationsGlyph,locations:LocationsGlyph,scan:ScanGlyph}
