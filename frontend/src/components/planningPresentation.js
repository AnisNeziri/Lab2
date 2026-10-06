export function scenarioPayload(form, scope = {}) {
 const numeric = new Set(['supplier_id','warehouse_id','source_warehouse_id','transfer_days','transfer_id','base_quantity','budget','delay_days','demand_multiplier','service_level'])
 const defined = Object.fromEntries(Object.entries(form).filter(([,v])=>v!==''&&v!==null&&v!==undefined))
 return Object.fromEntries(Object.entries({...scope,...defined}).filter(([,v])=>v!==''&&v!==null&&v!==undefined).map(([k,v])=>[k,numeric.has(k)?Number(v):v]))
}
export function purchasableItems(review) {
 return (review?.groups||[]).flatMap(g=>g.items).filter(r=>r.base_quantity>0&&r.feasible&&r.coverage_supported&&r.unit_price!==null)
}
export const planningLabels = {
 healthy:['Healthy','I shëndetshëm'],reorder_soon:['Reorder soon','Riporosit së shpejti'],reorder_now:['Reorder now','Riporosit tani'],critical:['Critical','Kritik'],potential_stockout:['Potential stockout','Mungesë e mundshme'],excess_stock:['Excess stock','Stok i tepërt'],slow_moving:['Slow moving','Qarkullim i ngadaltë'],overstock:['Overstock','Mbi kufirin e stokut'],
 low_confidence:['Low confidence — collecting observations','Besueshmëri e ulët — po mblidhen vëzhgime'],qualified_observations:['Qualified observations','Vëzhgime të kualifikuara'],limited_observations:['Limited observations','Vëzhgime të kufizuara'],
 safety_stock:['Safety stock','Stoku i sigurisë'],reorder_point:['Reorder point','Pika e riporositjes'],expected_lead_demand:['Lead-time demand','Kërkesa gjatë afatit'],lead_days:['Lead time','Afati i furnizimit'],desired_base_quantity:['Purchase target','Objektivi i blerjes'],optimization_state:['Inventory state','Gjendja e inventarit'],
 base_unit_price:['Lower recorded price','Çmim i regjistruar më i ulët'],lead_days_advantage:['Shorter recorded lead time','Afat i regjistruar më i shkurtër'],late_delivery_percent:['Lower historical delay rate','Normë historike vonese më e ulët'],
 accepted:['Accepted for review','Pranuar për rishikim'],quantity_changed:['Quantity changed','Sasia u ndryshua'],supplier_changed:['Supplier changed','Furnitori u ndryshua'],postponed:['Postponed','Shtyrë'],dismissed:['Dismissed','Refuzuar'],transferred:['Transferred instead','U transferua në vend të blerjes'],created_pr:['PR drafted','Kërkesa u përgatit'],
 open:['Open for review','I hapur për rishikim'],viewed:['Reviewed','I rishikuar'],adjusted:['Adjusted PR drafted','Kërkesa e ndryshuar u përgatit'],
 high:['Urgent','Urgjent'],watch:['Review','Rishiko'],normal:['Normal','Normal'],insufficient_data:['Insufficient history','Historik i pamjaftueshëm'],
 configured_floor:['Configured safety floor','Minimumi i konfiguruar i sigurisë'],independent_demand_lead_variance:['Demand + lead-time variability','Ndryshueshmëria e kërkesës + afatit'],empirical_lead_window:['Empirical intermittent demand','Kërkesa e ndërprerë empirike'],
 company:['Company planning','Planifikim i kompanisë'],qualified_warehouse:['Measured warehouse demand','Kërkesë e matur e depos'],declared_company_allocation:['Declared company-demand allocation','Ndarje e deklaruar e kërkesës së kompanisë'],warehouse_demand_unavailable:['Warehouse demand unavailable','Kërkesa e depos nuk është e disponueshme'],
 explicit_budget:['Explicit budget','Buxhet i përcaktuar'],unknown_base_price:['Base-currency price unknown','Çmimi në monedhën bazë i panjohur'],below_moq:['Budget/capacity cannot meet MOQ','Buxheti/kufiri nuk plotëson MOQ'],declared_stock_ceiling:['Declared stock ceiling','Kufiri i deklaruar i stokut'],
}
