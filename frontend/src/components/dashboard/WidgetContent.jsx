import { Link } from 'react-router-dom'
import { AreaChart, Area, BarChart, Bar, PieChart, Pie, Cell, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts'
import { useTranslation } from '../../hooks/useTranslation'
import { useSettingsStore } from '../../store/settingsStore'
import { useAuthStore } from '../../store/authStore'
import { formatOrderMoney, formatOrderDate } from '../orderPresentation'
import { matchingCommands } from '../../config/commandCatalog'
import { movementActivity, categoryActivity } from '../dashboardPresentation'
import { customerSalesLabel } from '../../pages/customerSalesPresentation'
import { optimizerLabel } from '../../pages/supplyOptimizerPresentation'
import { simulationLabel } from '../../pages/strategicSimulationPresentation'
import { shipmentIntelligenceLabel as shipmentLabel } from '../shipmentIntelligencePresentation'
import { useUiText } from '../../hooks/useUiText'
import { businessStatus } from '../../utils/businessStatus'

export function widgetSource(def,settings) {
  if(def.source==='sales-chart')return `/dashboard/sales-analytics?period=${settings.period||'month'}`
  if(def.source==='supplier-scorecard')return settings.supplier_id?`/quality/suppliers/${settings.supplier_id}/scorecard`:null
  return def.source
}
const rows = value => Array.isArray(value)?value:(value?.data||value?.rows||[])
function Empty({children}){return <p className="widget-empty">{children}</p>}
function Metric({value,label}){return <div className="widget-metric"><strong>{value??'—'}</strong>{label&&<span>{label}</span>}</div>}
function Board({items}){return <dl className="widget-board">{items.map(([label,value,path])=><div key={label}><dt>{label}</dt><dd>{path?<Link to={path}>{value??'—'}</Link>:value??'—'}</dd></div>)}</dl>}
function List({items,empty}){return items.length?<ul className="widget-records">{items}</ul>:<Empty>{empty}</Empty>}
function SummaryRuns({data,path,queryKey,label,t}){return <List empty={t('No saved runs yet.','Ende nuk ka analiza të ruajtura.')} items={rows(data).slice(0,4).map(r=><li key={r.id}><Link to={`${path}?${queryKey}=${r.id}`}>{r.name||`#${r.id}`}</Link><span>{label(r.status)}</span></li>)}/>}

export default function WidgetContent({definition:def,data,settings}) {
  const {language}=useTranslation(),t=(en,sq)=>language==='sq'?sq:en,tx=useUiText()
  const currency=useSettingsStore(s=>s.base_currency),permissions=useAuthStore(s=>s.permissions),theme=useSettingsStore(s=>s.theme)
  const money=(n,c=currency)=>formatOrderMoney(n,c,language),number=n=>n==null?'—':Number(n).toLocaleString(language==='sq'?'sq-AL':'en-GB'),date=n=>formatOrderDate(n,language)
  const none=t('No recorded data for this view.','Nuk ka të dhëna të regjistruara për këtë pamje.')
  if(def.id==='quick-actions')return <div className="widget-quick-actions">{matchingCommands('',permissions,language).filter(c=>['add-product','daily-sales','hub-new','purchase-order','stock-locator','debts'].includes(c.id)).map(c=><Link key={c.id} to={c.path}>{c.label} →</Link>)}</div>
  if(def.id==='supplier-reliability'&&!settings.supplier_id)return <Empty>{t('Choose a supplier in Configure to show its recorded scorecard.','Zgjidh një furnitor te Konfiguro për të shfaqur vlerësimin e tij.')}</Empty>
  if(!data)return <Empty>{none}</Empty>
  switch(def.id) {
    case 'today-sales':return <><Metric value={money(data.day_total)} label={date(data.date)}/><small>{data.day_closed?t('Day closed','Dita e mbyllur'):t('Includes sales recorded in the open day','Përfshin shitjet e regjistruara në ditën e hapur')}</small></>
    case 'inventory-value':return <><Metric value={money(data.inventory_value)} label={t('Recorded inventory cost','Kostoja e regjistruar e inventarit')}/><small>{t('Selling value','Vlera e shitjes')}: {money(data.total_value)}</small></>
    case 'product-count':return <Metric value={number(data.total_products)} label={t('Registered products','Produkte të regjistruara')}/>
    case 'stock-turnover':return <Metric value={data.stock_turnover==null?'—':`${number(data.stock_turnover)}×`} label={t('Recorded stock turnover','Qarkullimi i regjistruar i stokut')}/>
    case 'warehouse-sections':return <Metric value={number(rows(data).length)} label={t('Warehouse sections with recorded stock','Seksione depoje me stok të regjistruar')}/>
    case 'stock-alert-count':return <Metric value={number(new Set([...(data.low_stock_products||[]),...(data.out_of_stock_products||[])].map(p=>p.id)).size)} label={t('Low or out of stock','Stok i ulët ose i shterur')}/>
    case 'receivables':return <><Metric value={money(data.total_debt)} label={t('Customer debt ledger','Regjistri i borxheve të klientëve')}/><small>{t('Customer advances','Paradhëniet e klientëve')}: {money(data.total_customer_credit)}</small></>
    case 'orders-overview':return <Board items={[
      [t('New','Të reja'),data.new,'/order-hub?view=new'],[t('Needs attention','Kërkojnë vëmendje'),data.attention,'/order-hub?view=attention'],
      [t('Ready to fulfill','Gati për përmbushje'),data.ready_to_allocate,'/order-hub?view=ready_to_allocate'],[t('Ready to dispatch','Gati për nisje'),data.packed,'/order-hub?view=packed'],[t('Late','Vonuar'),data.late,'/order-hub?view=late'],
    ]}/>
    case 'recent-orders':return <div className="widget-table-scroll"><table><thead><tr><th>{t('Order','Porosia')}</th><th>{t('Customer','Klienti')}</th><th>{t('Status','Gjendja')}</th><th>{t('Value','Vlera')}</th></tr></thead><tbody>{rows(data).slice(0,5).map(r=><tr key={r.id}><td><Link to={`/order-hub?intake=${r.id}`}>{r.order?.order_number||r.external_id||`#${r.id}`}</Link></td><td>{r.customer||'—'}</td><td>{businessStatus(r.state,language)}</td><td className="numeric">{money(r.order?.total_amount,r.order?.currency)}</td></tr>)}</tbody></table>{!rows(data).length&&<Empty>{none}</Empty>}</div>
    case 'low-stock':return <List empty={t('No products at or below their stock threshold.','Asnjë produkt nuk është në ose nën kufirin e stokut.')} items={(data.alerts||[]).slice(0,6).map(p=><li key={p.id}><Link to={`/products?product=${p.id}`}>{p.name}</Link><span>{number(p.available_quantity??p.quantity)} {p.unit}</span></li>)}/>
    case 'stock-movements':return <StockChart series={movementActivity(data.recent_movements||[],language==='sq'?'sq-AL':'en-GB')} t={t} theme={theme}/>
    case 'category-activity':return <DistributionChart series={categoryActivity(data.recent_movements||[],t('Uncategorised','Pa kategori'))} theme={theme} t={t}/>
    case 'category-stock':return <List empty={none} items={(data.category_values||[]).slice(0,6).map((c,i)=><li key={c.id||i}><span>{c.name||c.category}</span><strong>{money(c.value)}</strong></li>)}/>
    case 'warehouse-stock':return <><DistributionChart series={rows(data).slice(0,8)} theme={theme} t={t} pie/><List empty={t('No section distribution is recorded.','Nuk është regjistruar shpërndarje sipas seksionit.')} items={rows(data).slice(0,8).map((r,i)=><li key={r.id||i}><span>{r.name}</span><strong>{number(r.value)}</strong></li>)}/></>
    case 'sales-trend':return <><div className="widget-inline-metrics"><Metric value={money(data.total_sales)} label={t('Sales','Shitjet')}/>{permissions.includes('analytics.finance')&&<Metric value={money(data.gross_profit)} label={t('Recorded gross profit','Fitimi bruto i regjistruar')}/>}</div><small>{date(data.start_date)} — {date(data.end_date)}</small><SalesChart data={data} t={t} theme={theme}/>{Number(data.uncosted_revenue)>0&&<small>{t('Some sales lack cost evidence. Review details before using margin figures.','Disa shitjeve u mungojnë kostot. Shqyrto hollësitë para përdorimit të marzhit.')}</small>}</>
    case 'open-purchases':return <List empty={none} items={rows(data).slice(0,5).map(p=><li key={p.id}><Link to={`/purchase-orders?po=${p.id}`}>{p.po_number||`#${p.id}`}</Link><span>{p.supplier?.name} · {businessStatus(p.status,language)}</span></li>)}/>
    case 'purchase-requests':return <List empty={none} items={rows(data).slice(0,5).map(p=><li key={p.id}><Link to={`/procurement?request=${p.id}`}>{p.reference||p.request_number||`#${p.id}`}</Link><span>{businessStatus(p.status,language)}</span></li>)}/>
    case 'shipments':return <List empty={t('No active shipment records.','Nuk ka dërgesa aktive.')} items={rows(data).filter(s=>!['delivered','arrived','completed','cancelled'].includes(s.status)).slice(0,5).map(s=><li key={s.id}><Link to={`/shipments/my-shipments?shipment=${s.id}`}>{s.vessel_name||s.reference||s.tracking_number||s.mmsi||`#${s.id}`}</Link><span>{businessStatus(s.status,language)}</span></li>)}/>
    case 'shipment-risk':return <List empty={t('No supported shipment risk evidence yet.','Ende nuk ka të dhëna të mbështetura për rrezikun e dërgesave.')} items={rows(data).slice(0,5).map(r=><li key={r.id||r.shipment_id}><Link to={r.url||`/shipments/my-shipments?view=intelligence&shipment=${r.shipment_id}`}>{r.evidence?.reference||`#${r.shipment_id}`}</Link><span>{shipmentLabel(r.risk,language)}</span></li>)}/>
    case 'action-center':return <Board items={[[t('My tasks','Detyrat e mia'),data.summary?.tasks,'/action-center?view=mine'],[t('Approvals waiting','Miratimet në pritje'),data.summary?.approvals,'/action-center'],[t('Priority tasks shown','Detyrat me përparësi të shfaqura'),data.summary?.issues,'/action-center']]}/>
    case 'automation-status':return <List empty={none} items={rows(data).slice(0,5).map(r=><li key={r.id}><Link to={`/automation-studio?automation=${r.id}`}>{r.name}</Link><span>{r.enabled?t('Enabled','Aktiv'):t('Paused','Në pauzë')}</span></li>)}/>
    case 'customer-opportunities':return data.state!=='ready'?<Empty>{t('No frozen customer analysis yet. Open details to review evidence.','Ende nuk ka analizë të klientëve. Hap hollësitë për të shqyrtuar të dhënat.')}</Empty>:<><small>{t('Advisory signals','Sinjale këshilluese')} · {date(data.as_of)}</small><List empty={none} items={(data.evidence?.opportunities||[]).slice(0,4).map(r=><li key={r.key}><Link to={`/customer-sales-intelligence?view=opportunities`}>{r.customer} · {r.product}</Link><span>{customerSalesLabel(r.kind,language)}</span></li>)}/></>
    case 'supplier-reliability':return <><Metric value={data.overall_score==null?'—':`${number(data.overall_score)}/100`} label={data.supplier_name}/><p>{data.overall_score==null?t('Insufficient recorded delivery/quality evidence.','Të dhëna të pamjaftueshme për dërgesat dhe cilësinë.'):t('Based on the existing supplier scorecard.','Bazuar në vlerësimin ekzistues të furnitorit.')}</p></>
    case 'cash-outlook':return data.state!=='ready'?<Empty>{t('No saved cash forecast yet. Open details to calculate one.','Ende nuk ka parashikim të ruajtur të parasë. Hap hollësitë për ta llogaritur.')}</Empty>:<>{data.stale&&<p className="widget-warning">{t('Evidence needs refreshing.','Të dhënat duhen përditësuar.')}</p>}{Object.entries(data.forecast?.currencies||{}).map(([c,f])=><Metric key={c} value={money(f.expected_closing_cash,c)} label={`${t('Expected closing cash','Paraja e pritshme në fund')} · ${c}`}/>)}<small>{t('30-day forecast, not a recorded account balance.','Parashikim 30-ditor, jo gjendje e regjistruar e llogarisë.')} · {date(data.as_of)}</small></>
    case 'supply-optimizer':return <SummaryRuns data={data} path="/supply-optimizer" queryKey="plan" label={key=>optimizerLabel(key,language)} t={t}/>
    case 'strategic-simulation':return <SummaryRuns data={data} path="/strategic-simulation" queryKey="run" label={key=>simulationLabel(key,language)} t={t}/>
    case 'forecast-health':return <><p>{t('Saved forecast evidence','Të dhënat e ruajtura të parashikimeve')}</p><List empty={t('No recorded products yet.','Ende nuk ka produkte të regjistruara.')} items={rows(data).slice(0,4).map(r=><li key={r.id}><Link to={r.url||`/inventory-intelligence?product=${r.id}`}>{r.name}</Link><span>{!r.prediction?t('No forecast','Pa parashikim'):(new Date(r.prediction.valid_until)<=new Date()||r.prediction.value?.unit!==r.unit)?t('Needs refresh','Duhet përditësuar'):t('Forecast available','Parashikimi në dispozicion')}</span></li>)}/></>
    case 'activity':return <List empty={none} items={(data.feed||[]).slice(0,6).map((r,i)=><li key={i}><span>{r.action||r.description}</span><small>{r.user} · {date(r.ts||r.created_at)}</small></li>)}/>
    default:return <Empty>{none}</Empty>
  }
}
function chartProps(theme){return {stroke:theme==='dark'?'#334155':'#e2e8f0',tick:{fontSize:11,fill:theme==='dark'?'#94a3b8':'#64748b'}}}
function DistributionChart({series,theme,t,pie=false}) {
  if(!series.length)return <Empty>{t('No recorded activity yet.','Ende nuk ka aktivitet të regjistruar.')}</Empty>
  const p=chartProps(theme),colors=['#6366f1','#f59e0b','#10b981','#8b5cf6','#06b6d4']
  const tip=<Tooltip contentStyle={{background:'var(--card-bg)',color:'var(--text-color)',borderColor:'var(--border-color)'}}/>
  return <div className="widget-chart"><ResponsiveContainer width="100%" height={220}>{pie?<PieChart>{tip}<Pie data={series} dataKey="value" nameKey="name" innerRadius={48} outerRadius={78} isAnimationActive={false}>{series.map((s,i)=><Cell key={s.name||i} fill={colors[i%colors.length]}/>)}</Pie></PieChart>:<BarChart data={series} margin={{left:0,right:12,bottom:16}}><CartesianGrid stroke={p.stroke} vertical={false}/><XAxis dataKey="name" tick={p.tick} interval={0}/><YAxis tick={p.tick} allowDecimals={false} width={35}/>{tip}<Bar dataKey="value" name={t('Outbound movements','Lëvizjet dalëse')} fill="#6366f1" radius={[4,4,0,0]} isAnimationActive={false}/></BarChart>}</ResponsiveContainer></div>
}
function SalesChart({data,t,theme}) {
  const p=chartProps(theme)
  return <div className="widget-chart"><ResponsiveContainer width="100%" height={220}><AreaChart data={data.series||[]} margin={{left:0,right:16,top:16,bottom:0}}><CartesianGrid stroke={p.stroke} vertical={false}/><XAxis dataKey="label" tick={p.tick} minTickGap={28}/><YAxis tick={p.tick} width={48}/><Tooltip contentStyle={{background:'var(--card-bg)',borderColor:'var(--border-color)',color:'var(--text-color)'}}/><Area isAnimationActive={false} dataKey="sales" name={t('Sales','Shitjet')} stroke="#3b82f6" fill="#3b82f61a" strokeWidth={2}/></AreaChart></ResponsiveContainer></div>
}
function StockChart({series,t,theme}) {
  const p=chartProps(theme)
  return series.length?<div className="widget-chart"><ResponsiveContainer width="100%" height={220}><AreaChart data={series}><CartesianGrid stroke={p.stroke} vertical={false}/><XAxis dataKey="date" tick={p.tick} minTickGap={28}/><YAxis tick={p.tick} width={30}/><Tooltip contentStyle={{background:'var(--card-bg)',color:'var(--text-color)',borderColor:'var(--border-color)'}}/><Area isAnimationActive={false} dataKey="In" name={t('Inbound movements','Hyrjet')} stroke="#10b981" fill="#10b98115"/><Area isAnimationActive={false} dataKey="Out" name={t('Outbound movements','Daljet')} stroke="#f59e0b" fill="#f59e0b15"/></AreaChart></ResponsiveContainer></div>:<Empty>{t('No recent stock movements.','Nuk ka lëvizje të fundit të stokut.')}</Empty>
}
