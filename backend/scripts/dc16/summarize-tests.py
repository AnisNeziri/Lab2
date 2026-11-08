import json,xml.etree.ElementTree as ET
from pathlib import Path
from collections import Counter,defaultdict
root=Path('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence')
latest={}
for p in sorted(root.glob('*.xml'),key=lambda x:x.stat().st_mtime):
 try:tree=ET.parse(p)
 except ET.ParseError:continue
 for t in tree.iter('testcase'):
  cls=t.attrib.get('class',t.attrib.get('classname',''));name=t.attrib.get('name','')
  if not cls:continue
  status='FAIL' if t.find('failure') is not None else 'ERROR' if t.find('error') is not None else 'SKIP' if t.find('skipped') is not None else 'PASS'
  detail=t.find('failure') if status=='FAIL' else t.find('error') if status=='ERROR' else None
  message='\n'.join((detail.text or '').splitlines()[:5]) if detail is not None else None
  latest[cls+'::'+name]={'class':cls,'name':name,'status':status,'assertions':int(t.attrib.get('assertions',0)),'evidence':p.name,'detail':message}
counts=Counter(t['status'] for t in latest.values());groups=defaultdict(list)
for id,t in latest.items():
 if t['status'] in ['FAIL','ERROR']:groups[t['class']].append({'id':id,**t})
r={'unique_cases':len(latest),'counts':dict(counts),'assertions_latest':sum(t['assertions'] for t in latest.values()),'failure_groups':groups,'method':'Latest preserved JUnit observation per class/name. Rerun results supersede earlier attempts; skipped cases never pass.','execution_progress':json.loads((root/'remaining-test-progress.json').read_text())}
(root/'all-tests-summary.json').write_text(json.dumps(r,indent=2),encoding='utf-8')
print(json.dumps({'unique_cases':r['unique_cases'],'counts':dict(counts),'failure_groups':{k:len(v) for k,v in groups.items()}}))
