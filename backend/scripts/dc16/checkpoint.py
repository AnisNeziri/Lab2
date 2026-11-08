"""Compact resumable evidence index; never changes ERP data."""
import json
from pathlib import Path
from collections import Counter
from datetime import datetime,timezone
root=Path('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence')
def read(name, default=None):
 p=root/(name+'.json')
 return json.loads(p.read_text(encoding='utf-8-sig')) if p.exists() else default
integrity=read('integrity-day-365',{})
coverage=read('feature-coverage',{})
defs=read('defects',{}).get('defects',[])
checkpoint={'schema':1,'updated_at':datetime.now(timezone.utc).isoformat(),'head':'89e0c29c295166a4610b61b6af45a0cfc6c12634','database':'127.0.0.1:3320/aims_dc16_20261102','companies':{'primary':2,'control':3},'stages':{'1_setup':'PASS','2_history':'PASS' if integrity.get('completed_days')==365 else 'PARTIAL','3_features':'IN PROGRESS','4_ai':'EXECUTED — limitations recorded','5_report':'IN PROGRESS'},'dataset_counts':integrity.get('stats',{}),'integrity':{'days':integrity.get('completed_days'),'journals':integrity.get('posted_journals_checked'),'unbalanced':len(integrity.get('unbalanced_posted_journals',[])),'inventory_issues':len(integrity.get('inventory_issues',[])),'declared_constraints':integrity.get('declared_constraints_checked'),'reference_violations':len(integrity.get('reference_violations',[])),'payroll_formula_errors':len(integrity.get('independent_payroll_formula_errors',[]))},'feature_coverage':coverage.get('summary',{}),'failures':[{k:d.get(k) for k in ['id','severity','status','module']} for d in defs],'evidence':{n:str(root/(n+'.json')) for n in ['integrity-day-365','installation-restore','control-concurrency','tenant-isolation','role-api-matrix','ask-review','temporal-backtests','performance','feature-coverage','defects']},'next_unfinished_step':'Finish current quotation browser conversion; bounded module, print, runtime and role/UI checks; finalize coverage and report.','restrictions':['No active company writes or migrations','No source history correction','No commit/push/deployment/cutover','No DC17']}
(root/'checkpoint.json').write_text(json.dumps(checkpoint,indent=2),encoding='utf-8')
progress=read('remaining-test-progress',{})
if (root/'REPORT.md').exists() and progress.get('finished'):
 checkpoint['stages']={'1_setup':'COMPLETE — PASS','2_history':'COMPLETE — PASS','3_features':'COMPLETE — explicit partial, blocked, failed and untested scopes retained','4_ai':'COMPLETE — evaluation executed; forecast/outcome limits retained','5_report':'COMPLETE'}
 checkpoint['next_unfinished_step']='No further DC16 execution scheduled. Review REPORT.md and defects.json; DC17 requires a separate user instruction.'
 checkpoint['test_summary']={k:read('all-tests-summary',{}).get(k) for k in ['unique_cases','counts']}
 checkpoint['runtime_evidence']='runtime-checks.json'
 checkpoint['final_integrity_evidence']='integrity-post-ui.json'
 checkpoint['report']=str(root/'REPORT.md')
 checkpoint['readiness']={'supervised_real_company_pilot':False,'commercial_deployment':False}
 checkpoint['toolchain']={'php':'C:/Users/anisi/Lab2/desktop/resources/php/php.exe','php_ini':'C:/Users/anisi/Lab2/output/dc8-runtime-php.ini','python':'C:/Users/anisi/AppData/Local/Programs/Python/Python312/python.exe','node':'C:/Users/anisi/Lab2/tmp/pr1-toolchain/node-v22.14.0-win-x64/node.exe'}
 (root/'checkpoint.json').write_text(json.dumps(checkpoint,indent=2),encoding='utf-8')
print('Compact checkpoint updated; completed history reused.')
