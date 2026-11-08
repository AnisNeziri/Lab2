"""Run previously unexecuted discovered PHPUnit cases only, on private SQLite runtime."""
import os,re,json,subprocess,xml.etree.ElementTree as ET
from pathlib import Path
base=Path('C:/Users/anisi/Lab2'); root=Path('C:/Users/anisi/.codex/recovery/dc16-20261102/storage/evidence')
inventory=ET.parse(root/'all-test-inventory.xml'); ns={'p':'https://xml.phpunit.de/testSuite'}
all_ids=[x.attrib['id'] for x in inventory.findall('.//p:testMethod',ns)]
executed=set()
for f in root.glob('*.xml'):
 try:
  for t in ET.parse(f).iter('testcase'):
   cls=t.attrib.get('class',t.attrib.get('classname',''));name=t.attrib.get('name','').split(' with data set')[0]
   if cls:executed.add(cls+'::'+name)
 except ET.ParseError:pass
remaining=[x for x in all_ids if x.split(' with data set')[0] not in executed]
plan={'discovered':len(all_ids),'already_executed':len(all_ids)-len(remaining),'remaining':remaining}
(root/'remaining-test-plan.json').write_text(json.dumps(plan,indent=2))
runtime=root/'remaining-test-runtime'
for d in ['framework/views','framework/cache/data','framework/sessions','logs','app/private','app/public','app/documents-private']: (runtime/d).mkdir(parents=True,exist_ok=True)
env=os.environ.copy();env.update({'APP_ENV':'testing','DB_CONNECTION':'sqlite','DB_DATABASE':':memory:','DB_URL':'','MAIL_MAILER':'array','QUEUE_CONNECTION':'sync','CACHE_STORE':'array','SESSION_DRIVER':'array','LARAVEL_STORAGE_PATH':str(runtime),'AIMS_DOCUMENT_ROOT':str(runtime/'app/documents-private'),'PHPRC':str(base/'output/dc8-runtime-php.ini'),'PHP_INI_SCAN_DIR':'','OPENSSL_CONF':'C:/xampp/apache/conf/openssl.cnf'})
php=str(base/'desktop/resources/php/php.exe');results=[]
for i in range(0,len(remaining),60):
 batch=remaining[i:i+60]; number=i//60+1; xml=root/f'remaining-batch-{number}.xml';log=root/f'remaining-batch-{number}.log'
 pattern='/^(?:'+'|'.join(re.escape(x) for x in batch)+r')(?: with data set.*)?$/'
 with log.open('w',encoding='utf-8') as out:
  result=subprocess.run([php,'vendor/phpunit/phpunit/phpunit','--filter',pattern,'--log-junit',str(xml),'--colors=never'],cwd=base/'backend',env=env,stdout=out,stderr=subprocess.STDOUT)
 results.append({'batch':number,'cases_requested':len(batch),'exit_code':result.returncode,'xml':str(xml),'log':str(log)})
 (root/'remaining-test-progress.json').write_text(json.dumps({'plan':{k:v for k,v in plan.items() if k!='remaining'},'batches':results,'finished':i+60>=len(remaining)},indent=2))
 print(f'Batch {number}: {len(batch)} requested; exit {result.returncode}',flush=True)
print(f'{len(remaining)} previously unexecuted test identifiers processed.',flush=True)
