import concurrent.futures, json, sys, time, urllib.request
from pathlib import Path
root=Path('C:/Users/anisi/.codex/recovery/dc18-3-20261105/evidence')
fixture=Path('C:/Users/anisi/.codex/recovery/dc16-20261102')
account=json.loads((fixture/'accounts.json').read_text(encoding='utf-8'))['primary']
secrets=json.loads((fixture/'credentials.json').read_text(encoding='utf-8'))
label=sys.argv[1] if len(sys.argv)>1 else 'before'
base='http://127.0.0.1:8028' if label=='before' else 'http://127.0.0.1:4188'
def request(path,payload=None,token=None):
    headers={'Accept':'application/json','Content-Type':'application/json'}
    if token:headers['Authorization']='Bearer '+token
    start=time.perf_counter()
    req=urllib.request.Request(base+'/api/'+path,data=json.dumps(payload).encode() if payload is not None else None,headers=headers)
    with urllib.request.urlopen(req,timeout=90) as response:data=json.load(response);status=response.status
    return {'http':status,'ms':round((time.perf_counter()-start)*1000,2),'body':data}
login=request('login',{'email':account['email'],'password':account.get('password') or secrets['owner_password']})
token=login['body'].get('access_token') or login['body']['token']
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
    ask=pool.submit(request,'intelligence-assistant/ask',{'question':'Which customers bought the most last month?','language':'en'},token)
    time.sleep(.3)
    ordinary=request('me',token=token)
    result=ask.result()
(root/('contention-'+label+'.json')).write_text(json.dumps({'route':base,'ask':{'http':result['http'],'ms':result['ms'],'provider':result['body'].get('provider')},'ordinary':{'http':ordinary['http'],'ms':ordinary['ms']},'overlap_delay_ms':300},indent=2))
print(json.dumps({'label':label,'ask_ms':result['ms'],'ordinary_ms':ordinary['ms']}))
