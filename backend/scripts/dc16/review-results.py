"""Explicit reviewer dispositions of the preserved DC16 evidence; no application writes."""
import json
from pathlib import Path
from collections import Counter
root=Path('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence')
ask=json.loads((root/'ask-evaluation.json').read_text())
fail={2,3,4,7,10,15,16,20,34,39}
passed={1,5,9,14,22,26,27,28,29,30,31,32,33,37,38}
rows=[]
for result in ask['results']:
    number=int(result['case']['id'].split('-')[1])
    status='FAIL' if number in fail else 'PASS' if number in passed else 'PARTIAL'
    reason=('Intent/entity routing fails the requested aggregate, temporal or preparation task.' if number in fail else
            'Reviewed source-grounded numeric value or explicit clarification/authorization boundary; scope limited to this question.' if number in passed else
            'Response observed; usefulness, bilingual labels, role configuration or independent aggregate detail still needs review.')
    rows.append({'id':result['case']['id'],'status':status,'reason':reason,'question':result['case']['question'],'observed_text':result['response'].get('text'),'independent_value':result['independent_expected_value'],'ms':result['ms']})
review={'questions':40,'business_state_unchanged':ask['business_state_unchanged'],'counts':dict(Counter(r['status'] for r in rows)),'results':rows,'accuracy_claim':'No overall calibrated accuracy claim. PASS includes safe refusal/clarification cases, not only numerical answers. Partial cases are not passes.','invalidated_first_run':'The first all-tenant digest overlapped the control-tenant concurrency fixture. Its mismatch is not proof of an Ask mutation. Repeated alone: unchanged business digest.','provider':ask['provider']}
(root/'ask-review.json').write_text(json.dumps(review,indent=2,ensure_ascii=False),encoding='utf-8')
register=json.loads((root/'defects.json').read_text())
new=[
 {'id':'DC16-AI-001','module':'Ask AIMS intent routing','severity':'P1','status':'OPEN','reproduction':['Sign in to isolated company 2 as owner','Ask How much did we sell last month? or Cilat produkte po shiten ma shum?','Compare response with populated sales history'],'expected':'Resolve aggregate sales intent, explicit period and permitted data; return evidence or a precise limitation.','actual':'Record lookup fallback says no authorized record with that name. Ten reviewed corpus cases fail their requested intent or preparation task.','role':'owner; purchasing for ASK-039','records':'ASK-002/003/004/007/010/015/016/020/034/039','evidence':['ask-evaluation.json','ask-review.json'],'proven_cause':'Observed deterministic routing to generic record/entity lookup or availability response.','suspected_cause':'Missing aggregate/bilingual/typo patterns and contextual action routing.','business_impact':'Common business questions remain unanswered despite available data.','recommended_fix_area':'DC17 existing intent router and typed-tool dispatch; preserve read-only boundaries.','regression':'Run unchanged 40-case corpus with independent period/quantity oracles.'},
 {'id':'DC16-PERF-001','module':'Search and automation health','severity':'P2','status':'OPEN','reproduction':['Run performance.php after 365-day replay','Inspect first/repeat search?q=Milano and automation-health DC7 telemetry'],'expected':'Bounded summary work without per-record permission/query amplification.','actual':'Search: 1030 queries in each sample, 824–931 ms. Automation health: 831 queries, 450–528 ms.','role':'primary owner','records':'300-product/29833-event company 2','evidence':['performance.json'],'proven_cause':'Measured query counts; exact call-site cause not yet proven.','suspected_cause':'Repeated permission or entity lookups.','business_impact':'Database amplification threatens multi-user latency; local samples are not load certification.','recommended_fix_area':'DC17 profile existing summary/search paths, batch/cache request-scoped authorization.','regression':'Same dataset, bounded first/repeat query budgets with identical permitted results.'},
 {'id':'DC16-UI-001','module':'Executive brief localization and source classification','severity':'P2','status':'OPEN','reproduction':['Open control company dashboard in Albanian after concurrent reservation test','Inspect allocation_incomplete issue'],'expected':'Readable Albanian shortage label in warehouse/fulfillment context.','actual':'Raw allocation_incomplete title, English shortage text, and purchasing/shipment explanation on a fulfillment issue.','role':'control owner','records':'Sales order 642','evidence':['control-concurrency.json'],'proven_cause':'Rendered browser state observed; mapping implementation not yet traced.','suspected_cause':'Missing exception presentation mapping/localization.','business_impact':'User cannot reliably interpret operational exception context.','recommended_fix_area':'DC17 existing brief issue labels/domain mapping.','regression':'Albanian reservation-shortage card links to correct order with accurate explanation.'}
]
for defect in new:
    if not any(d['id']==defect['id'] for d in register['defects']):register['defects'].append(defect)
register['counts']=dict(Counter(d['severity'] for d in register['defects']))
(root/'defects.json').write_text(json.dumps(register,indent=2,ensure_ascii=False),encoding='utf-8')
print(json.dumps({'ask':review['counts'],'defects':register['counts']}))
