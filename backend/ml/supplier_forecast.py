"""Local lead-time challenger. Completed PO labels only, decision-time holdouts."""
import json, math, sys, os
from datetime import date
from statistics import median
sys.path.insert(0, os.path.dirname(__file__))
from forecast import solve

VERSION='supplier-lead-v1'
def features(row):
    month=date.fromisoformat(row['ordered_at']).month
    return [float(row['promised_days']),float(row['line_count']),math.sin(month*math.pi/6),math.cos(month*math.pi/6)]
def fit(rows):
    xs=[features(r) for r in rows];ys=[r['lead_days'] for r in rows]
    centers=[sum(x[i] for x in xs)/len(xs) for i in range(4)]
    scales=[max(1e-6,math.sqrt(sum((x[i]-centers[i])**2 for x in xs)/len(xs))) for i in range(4)]
    z=[[1]+[(v-c)/s for v,c,s in zip(x,centers,scales)] for x in xs]
    gram=[[sum(x[i]*x[j] for x in z)+(2 if i==j and i else 0) for j in range(5)] for i in range(5)]
    weights=solve(gram,[sum(x[i]*y for x,y in zip(z,ys)) for i in range(5)])
    return {'version':VERSION,'algorithm':'supplier_ridge','weights':weights,'centers':centers,'scales':scales,'cap':max(1,max(ys)*3)}
def predict(artifact,row):
    if artifact.get('version')!=VERSION: raise ValueError('Unsupported artifact')
    for key,length in [('weights',5),('centers',4),('scales',4)]:
        if len(artifact[key])!=length or not all(math.isfinite(x) for x in artifact[key]): raise ValueError('Invalid artifact')
    if min(artifact['scales'])<=0: raise ValueError('Invalid scales')
    x=[1]+[(v-c)/s for v,c,s in zip(features(row),artifact['centers'],artifact['scales'])]
    return round(max(0,min(artifact['cap'],sum(a*b for a,b in zip(x,artifact['weights'])))),3)
def run(payload):
    if payload.get('mode')=='predict':
        if 'orders' in payload:return {'predictions':{str(r['order_id']):predict(payload['artifact'],r) for r in payload['orders'][:500]}}
        return {'prediction':predict(payload['artifact'],payload['order'])}
    rows=sorted([r for r in payload.get('rows',[]) if r.get('complete') and r.get('ml_eligible') and r.get('lead_days') is not None and r.get('promised_days') is not None],key=lambda r:(r['ordered_at'],r['order_id']))
    if len(rows)>500:raise ValueError('Maximum 500 orders')
    for r in rows:
        if r['label_known_at']>payload['cutoff'] or r['label_known_at']<r['completion_date'] or r['completion_date']<r['ordered_at'] or not all(math.isfinite(float(r[k])) and r[k]>=0 for k in ['lead_days','promised_days','line_count']):raise ValueError('Invalid temporal row')
    if len(rows)<payload.get('minimum',40):return {'status':'insufficient_data','samples':len(rows)}
    split=max(28,int(len(rows)*.65));validation=rows[split:]
    incumbent=payload.get('incumbent');cutoff=incumbent.get('training_cutoff','') if incumbent else ''
    if incumbent:validation=[r for r in validation if r['ordered_at']>cutoff]
    if not validation:return {'status':'insufficient_data','samples':len(rows)}
    training=[r for r in rows if r['label_known_at']<validation[0]['ordered_at']]
    if len(training)<28:return {'status':'insufficient_data','samples':len(rows)}
    challenger=fit(training);pairs=[]
    for r in validation:
        known=[x['lead_days'] for x in rows if x['label_known_at']<r['ordered_at'] and x['order_id']!=r['order_id']]
        if len(known)<5:continue
        pairs.append({'order_id':r['order_id'],'ordered_at':r['ordered_at'],'actual':r['lead_days'],'baseline':median(known),'challenger':predict(challenger,r),'incumbent':predict(incumbent,r) if incumbent else None})
    def score(key):
        eligible=[p for p in pairs if p[key] is not None]
        return None if not eligible else {'mae':sum(abs(p[key]-p['actual']) for p in eligible)/len(eligible),'bias':sum(p[key]-p['actual'] for p in eligible)/len(eligible),'samples':len(eligible)}
    artifact=fit(rows);artifact['training_cutoff']=payload['cutoff']
    return {'status':'ready','samples':len(rows),'artifact':artifact,'comparison':{k:score(k) for k in ['baseline','challenger','incumbent']},'windows':pairs,'validation_training_order_ids':[r['order_id'] for r in training]}
if __name__=='__main__':
    try:
        raw=sys.stdin.read(8_000_001)
        if len(raw)>8_000_000:raise ValueError('Input too large')
        print(json.dumps(run(json.loads(raw)),allow_nan=False))
    except Exception as e:
        print(json.dumps({'error':str(e)}));sys.exit(1)
