import json,time,urllib.request,threading,subprocess
from pathlib import Path
r=Path('C:/Users/anisi/.codex/recovery/dc18-1-20261104/evidence')
def post(url,body,timeout=90):
 t=time.monotonic();data=json.load(urllib.request.urlopen(urllib.request.Request(url,json.dumps(body).encode(),{'Content-Type':'application/json'}),timeout=timeout));return {'seconds':time.monotonic()-t,'response':data}
creds=json.loads(Path('C:/Users/anisi/.codex/recovery/dc16-20261102/credentials.json').read_text());accounts=json.loads(Path('C:/Users/anisi/.codex/recovery/dc16-20261102/accounts.json').read_text())
login=post('http://127.0.0.1:8026/api/login',{'email':accounts['primary']['email'],'password':creds['owner_password']})['response'];token=login.get('access_token',login.get('token'))
inference={}
def generate():
 try:inference.update(post('http://127.0.0.1:11438/api/chat',{'model':'qwen3-vl:4b-instruct','stream':False,'messages':[{'role':'user','content':'Explain physical, reserved and available warehouse stock briefly.'}],'options':{'num_predict':150,'num_ctx':4096,'temperature':0}}))
 except Exception as e:inference['error']=type(e).__name__
t=threading.Thread(target=generate);t.start();latencies=[]
for path in ['intelligence-assistant/status','products?reference_only=1&per_page=1']:
 start=time.monotonic();response=urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8026/api/'+path,headers={'Authorization':'Bearer '+token,'Accept':'application/json'}),timeout=10);response.read();latencies.append({'path':path,'http':response.status,'seconds':time.monotonic()-start})
t.join();(r/'erp-during-inference.json').write_text(json.dumps({'inference_seconds':inference.get('seconds'),'erp':latencies,'scope':'Existing isolated ERP worker 8026 while separate optional model performs inference. Single-worker assistant requests on 8028 still occupy that worker.'},indent=2))
cpu=post('http://127.0.0.1:11438/api/chat',{'model':'qwen3-vl:4b-instruct','stream':False,'messages':[{'role':'user','content':'Explain inventory in one short sentence.'}],'options':{'num_gpu':0,'num_predict':30,'num_ctx':1024,'temperature':0}})
cpu['ps']=json.load(urllib.request.urlopen('http://127.0.0.1:11438/api/ps'));(r/'cpu-fallback.json').write_text(json.dumps(cpu,indent=2))
print(json.dumps({'erp_seconds':[x['seconds'] for x in latencies],'cpu_seconds':cpu['seconds'],'cpu_vram':[m.get('size_vram') for m in cpu['ps']['models']]}))
