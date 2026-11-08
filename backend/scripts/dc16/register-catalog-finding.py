import json
from pathlib import Path
p=Path('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence/defects.json')
r=json.loads(p.read_text(encoding='utf-8'))
d={'id':'DC16-SEC-001','module':'Catalog read authorization and cost confidentiality','severity':'P1','status':'OPEN','reproduction':['Sign in as company2 HR with no inventory.view permission','GET /api/products?per_page=1','Inspect purchase_price, weighted_average_cost, inventory_value and inventory snapshot'],'expected':'Apply the configured inventory/cost viewing boundary, or return an explicitly authorized redacted reference catalog.','actual':'HTTP200 exposes purchase cost, valuation, supplier catalogue and detailed stock to HR lacking inventory.view.','role':'HR','records':'Same-company catalog sample in catalog-permission-boundary.json','evidence':['catalog-permission-boundary.json','role-api-matrix.json'],'proven_cause':'products apiResource index/show have no read permission middleware and index returns the full product/snapshot response.','suspected_cause':'Broad shared-reference access lacks redaction/permission distinction; intended reference policy needs review.','business_impact':'Confidential cost/stock data is visible beyond configured inventory-view role. No cross-company disclosure or mutation was observed.','recommended_fix_area':'DC17 existing catalog authorization/redaction with necessary sales/reference use cases preserved.','regression':'HR without inventory/cost permissions cannot fetch cost/stock data; authorized catalog consumers and tenant isolation continue to work.'}
d['severity']='P0'
d['severity_basis']='User DC16 severity policy classifies a security breach as P0. Confirmed same-company cost/stock disclosure beyond the configured role; no cross-tenant disclosure or active-company exposure observed.'
prior=next((x for x in r['defects'] if x['id']==d['id']),None)
if prior is None:r['defects'].append(d)
else:prior.update(d)
p.write_text(json.dumps(r,indent=2,ensure_ascii=False),encoding='utf-8')
