"""AIMS demand-v1: dependency-free ridge regression and honest baseline selection.
JSON stdin/stdout only. No pickle, executable artifacts, network or external services.
"""
import sys
import json
import math
from datetime import date, timedelta

VERSION = 'demand-v1'
NAMES = ['mean7', 'mean28', 'trend', 'weekday_sin', 'weekday_cos', 'stockout28']

def mean(values):
    return sum(values) / len(values) if values else 0.0

def features(history, day):
    known = [r['demand'] for r in history[-28:] if r['demand'] is not None]
    recent = [r['demand'] for r in history[-7:] if r['demand'] is not None]
    if len(known) < 14 or len(recent) < 4:
        return None
    a, b = mean(recent), mean(known)
    angle = date.fromisoformat(day).weekday() * math.pi * 2 / 7
    return [a, b, a-b, math.sin(angle), math.cos(angle), sum(r.get('censored', False) for r in history[-28:]) / 28]

def baseline(history, day):
    weekday = date.fromisoformat(day).weekday()
    peers = [r['demand'] for r in history[-28:] if r['demand'] is not None and date.fromisoformat(r['date']).weekday() == weekday]
    return mean(peers) if len(peers) >= 3 else mean([r['demand'] for r in history[-28:] if r['demand'] is not None])

def solve(a, b):
    # Small regularized normal system (7 coefficients), partial pivoting.
    n = len(b)
    m = [list(a[i]) + [b[i]] for i in range(n)]
    for i in range(n):
        pivot = max(range(i, n), key=lambda k: abs(m[k][i]))
        m[i], m[pivot] = m[pivot], m[i]
        if abs(m[i][i]) < 1e-12:
            raise ValueError('Singular training matrix')
        scale = m[i][i]
        m[i] = [v / scale for v in m[i]]
        for j in range(n):
            if j != i:
                scale = m[j][i]
                m[j] = [x-scale*y for x, y in zip(m[j], m[i])]
    return [r[-1] for r in m]

def fit(series):
    samples = [(features(series[:i], r['date']), r['demand']) for i, r in enumerate(series) if i >= 28 and r['demand'] is not None]
    samples = [(x, y) for x, y in samples if x is not None]
    if len(samples) < 28:
        return None
    centers = [mean([x[i] for x, _ in samples]) for i in range(6)]
    scales = [max(1e-6, math.sqrt(mean([(x[i]-centers[i])**2 for x, _ in samples]))) for i in range(6)]
    matrix = [[1] + [(v-c)/s for v,c,s in zip(x,centers,scales)] for x,_ in samples]
    gram = [[sum(x[i]*x[j] for x in matrix)+(2.0 if i == j and i else 0) for j in range(7)] for i in range(7)]
    rhs = [sum(x[i]*sample[1] for x,sample in zip(matrix,samples)) for i in range(7)]
    return {'weights': solve(gram,rhs), 'centers': centers, 'scales': scales, 'alpha': 2.0}

def validate_artifact(artifact):
    if artifact.get('version') != VERSION or artifact.get('algorithm') not in ('seasonal_mean', 'ridge'):
        raise ValueError('Unsupported artifact')
    if artifact['algorithm'] == 'ridge':
        for key,n in [('weights',7),('centers',6),('scales',6)]:
            values = artifact.get('parameters',{}).get(key,[])
            if len(values) != n or not all(isinstance(v,(int,float)) and math.isfinite(v) for v in values):
                raise ValueError('Invalid numerical artifact')
        if min(artifact['parameters']['scales']) <= 0:
            raise ValueError('Invalid scale')

def forecast(series, horizon, artifact):
    validate_artifact(artifact)
    history = [dict(r) for r in series]
    output = []
    if not history:
        return output
    start = date.fromisoformat(history[-1]['date'])
    # Cap only physically implausible extrapolation; report the policy in artifact.
    ceiling = max(1.0, max([r['demand'] or 0 for r in series[-90:]]) * 3)
    for offset in range(1,horizon+1):
        day = (start+timedelta(days=offset)).isoformat()
        x = features(history,day)
        value = baseline(history,day)
        if artifact['algorithm'] == 'ridge' and x is not None:
            p = artifact['parameters']
            z = [1] + [(v-c)/s for v,c,s in zip(x,p['centers'],p['scales'])]
            value = sum(a*b for a,b in zip(z,p['weights']))
        value = max(0.0,min(ceiling,value))
        output.append({'date':day,'quantity':round(value,6)})
        history.append({'date':day,'demand':value,'censored':False})
    return output

def metrics(pairs):
    if not pairs:
        return None
    errors = [p-y for p,y in pairs]
    denominator = sum(y for _,y in pairs)
    return {'mae':mean([abs(e) for e in errors]), 'wape':100*sum(abs(e) for e in errors)/denominator if denominator else None,
            'bias':mean(errors), 'observations':len(pairs), 'actual_total':denominator}

def evaluate(series, horizon, artifact, split):
    pairs, block_errors, windows = [], [], []
    # Non-overlapping, genuinely multi-step rolling-origin validation. No target
    # from the evaluation window is supplied to the recursive prediction.
    for start in range(split,len(series)-horizon+1,horizon):
        target = series[start:start+horizon]
        if any(r['demand'] is None for r in target):
            continue
        predictions = forecast(series[:start],horizon,artifact)
        pairs.extend((p['quantity'],r['demand']) for p,r in zip(predictions,target))
        block_errors.append(abs(sum(p['quantity'] for p in predictions)-sum(r['demand'] for r in target)))
        windows.append({'from':target[0]['date'],'to':target[-1]['date']})
    result = metrics(pairs)
    if result:
        result.update({'windows':windows,'horizon_mae':mean(block_errors),'blocks':len(block_errors)})
    return result

def run(payload):
    series = payload.get('series',[])
    if len(series)>730:
        raise ValueError('History exceeds 730 days')
    for i,r in enumerate(series):
        d = date.fromisoformat(r['date'])
        if i and d != date.fromisoformat(series[i-1]['date'])+timedelta(days=1):
            raise ValueError('History must be consecutive and time ordered')
        value = r.get('demand')
        if value is not None and (not isinstance(value,(float,int)) or not math.isfinite(value) or value<0):
            raise ValueError('Demand must be nonnegative or unknown')
        if r.get('censored') and value is not None:
            raise ValueError('Censored demand must not be a training target')
    valid = sum(r['demand'] is not None for r in series)
    base = {'version':VERSION,'algorithm':'seasonal_mean','parameters':{},'features':NAMES,'cap':'3x maximum observed daily demand in trailing 90 days'}
    sufficiency = {'observed_days':valid,'calendar_days':len(series),'censored_days':sum(bool(r.get('censored')) for r in series),
                   'unknown_days':sum(r['demand'] is None and not r.get('censored',False) for r in series),
                   'recent_observed_days':sum(r['demand'] is not None for r in series[-90:]),'recent_calendar_days':min(90,len(series))}
    if valid < 14 or sum(r['demand'] is not None for r in series[-28:])<14:
        return {'status':'insufficient_data','quality':sufficiency,'results':[]}
    if payload.get('mode') == 'predict':
        results=[]
        for h,artifact in (payload.get('models') or {}).items():
            horizon=int(h)
            if horizon not in (7,30,90): raise ValueError('Invalid horizon')
            if artifact.get('training_cutoff','9999')>series[-1]['date']: raise ValueError('Model trained after prediction origin')
            daily=forecast(series,horizon,artifact)
            results.append({'horizon':horizon,'daily':daily,'baseline_daily':forecast(series,horizon,base),
                            'total':round(sum(d['quantity'] for d in daily),3),'status':'production',
                            'range_reason':'No calibrated interval; sampled availability may censor fulfilled-sales observations.'})
        return {'status':'ready','quality':sufficiency,'results':results}
    split = max(56, int(len(series)*0.65))
    results = []
    for horizon in (7,30,90):
        if valid < max(14,horizon):
            continue
        incumbent = (payload.get('incumbent') or {}).get(str(horizon))
        local_split = split
        if incumbent:
            validate_artifact(incumbent)
            local_split = max(split, sum(r['date'] <= incumbent.get('training_cutoff','9999') for r in series))
        fitted = fit(series[:local_split]) if len(series)>local_split else None
        candidate = dict(base,algorithm='ridge',parameters=fitted) if fitted else None
        scores = {'seasonal_mean':evaluate(series,horizon,base,local_split)}
        if candidate:
            scores['ridge'] = evaluate(series,horizon,candidate,local_split)
        selected = base
        b,c = scores.get('seasonal_mean'),scores.get('ridge')
        if b and c and c['blocks']>=2 and c['mae']<b['mae']*0.98 and c['horizon_mae']<=b['horizon_mae']:
            selected = candidate
        if incumbent:
            previous = evaluate(series,horizon,incumbent,local_split)
            scores['incumbent'] = previous
            chosen = scores[selected['algorithm']]
            if payload.get('mode')!='candidate' and (not previous or not chosen or previous['mae']<=chosen['mae'] or previous['horizon_mae']<chosen['horizon_mae']):
                selected = incumbent
        chosen_score = scores.get('incumbent') if selected is incumbent else scores[selected['algorithm']]
        # Refit only after honest holdout selection. An unmeasured candidate
        # never replaces a measured incumbent in the backend promotion policy.
        artifact = dict(selected)
        if selected is not incumbent and selected['algorithm']=='ridge':
            artifact['parameters'] = fit(series)
        artifact['training_cutoff'] = series[-1]['date'] if selected is not incumbent else selected['training_cutoff']
        daily = forecast(series,horizon,artifact)
        results.append({'horizon':horizon,'algorithm':artifact['algorithm'],'artifact':artifact,'evaluation':chosen_score,'comparison':scores,'retained_incumbent':selected is incumbent,
                        'daily':daily,'total':round(sum(p['quantity'] for p in daily),3),
                        'range':None,'range_reason':'No calibrated prediction interval; validation error is descriptive, not a probability.',
                        'status':'evaluated' if chosen_score else 'unvalidated_baseline'})
    return {'status':'ready','quality':sufficiency,'results':results,'validation':'time_ordered_recursive_holdout'}

if __name__ == '__main__':
    try:
        raw=sys.stdin.read(8_000_001)
        if len(raw)>8_000_000: raise ValueError('Input too large')
        print(json.dumps(run(json.loads(raw)),allow_nan=False,separators=(',',':')))
    except Exception as e:
        print(json.dumps({'error':str(e)}))
        sys.exit(1)
