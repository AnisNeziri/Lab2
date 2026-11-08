import base64, io, json, sys, importlib.util
from pathlib import Path
from PIL import Image, ImageDraw, ImageEnhance
root=Path('C:/Users/anisi/.codex/recovery/dc18-20261104/evidence')
spec=importlib.util.spec_from_file_location('image_descriptor',Path(__file__).resolve().parents[2]/'ml/assistant_image.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
images=[]
for n,colour in enumerate([(30,60,100),(180,130,70),(70,140,80),(170,45,50),(100,90,160),(35,65,105)]):
    im=Image.new('RGB',(180,180),colour);draw=ImageDraw.Draw(im)
    for i in range(0,180,6+n):draw.line((i,0,i,180),fill=tuple(min(255,c+25) for c in colour))
    images.append(im)
def encode(im):
    b=io.BytesIO();im.save(b,'PNG');return base64.b64encode(b.getvalue()).decode()
descriptors=[module.describe(encode(im)) for im in images]
scores=[]
for n,im in enumerate(images):
    for kind,query in [('exact',im),('lighting',ImageEnhance.Brightness(im).enhance(1.15)),('rotation',im.rotate(10))]:
        d=module.describe(encode(query))['vector']
        ranked=sorted(range(len(images)),key=lambda j:sum(abs(a-b) for a,b in zip(d,descriptors[j]['vector'])))
        scores.append({'source':n,'variation':kind,'top1':ranked[0]==n,'top5':n in ranked[:5]})
reject=[]
for raw in [b'not an image',b'<svg xmlns="http://www.w3.org/2000/svg"/>']:
    try:module.describe(base64.b64encode(raw).decode());reject.append(False)
    except Exception:reject.append(True)
(root/'synthetic-fabric.png').write_bytes(base64.b64decode(encode(images[0])))
before=json.loads((root/'before-ask40.json').read_text());after=json.loads((root/'ask-evaluation.json').read_text())
def stable(x):
    if isinstance(x,dict):return {k:stable(v) for k,v in x.items() if k not in ['id','conversation_id','read_at','as_of','duration_ms','response_id','ms','telemetry']}
    if isinstance(x,list):return [stable(v) for v in x]
    return x
comparison=[{'case':a['case']['id'],'same_response':stable(a['response'])==stable(b['response']),'http_before':a['http'],'http_after':b['http']} for a,b in zip(before['results'],after['results'])]
result={'ask40_comparison':comparison,'business_unchanged':after['business_unchanged'],'visual':{'method':'colour_texture_not_learned_embedding','synthetic_cases':scores,'top1':sum(x['top1'] for x in scores),'top5':sum(x['top5'] for x in scores),'total':len(scores),'invalid_rejected':reject,'phone_fabric_evaluation':'NOT TESTED','out_of_catalogue_rejection':'NOT IMPLEMENTED; all candidates explicitly unconfirmed','exact_identity_claims':False}}
(root/'evaluation-summary.json').write_text(json.dumps(result,indent=2))
print(json.dumps({'ask_identical':sum(x['same_response'] for x in comparison),'ask_changed':[x['case'] for x in comparison if not x['same_response']],'visual_top1':result['visual']['top1'],'visual_top5':result['visual']['top5'],'visual_cases':len(scores)}))
