import json,time,base64,io,hashlib
from pathlib import Path
import torch
from transformers import AutoModel,AutoProcessor
from PIL import Image,ImageDraw,ImageEnhance,ImageFilter
r=Path('C:/Users/anisi/.codex/recovery/dc18-1-20261104');t=time.monotonic();torch.set_num_threads(2)
model=AutoModel.from_pretrained(r/'siglip2',local_files_only=True).eval();processor=AutoProcessor.from_pretrained(r/'siglip2',local_files_only=True)
load=time.monotonic()-t;images=[]
for n,c in enumerate([(30,60,100),(180,130,70),(70,140,80),(170,45,50),(100,90,160),(35,65,105)]):
 im=Image.new('RGB',(180,180),c);d=ImageDraw.Draw(im)
 for i in range(0,180,6+n):d.line((i,0,i,180),fill=tuple(min(255,x+25) for x in c))
 images.append(im)
queries=[];labels=[]
for i,im in enumerate(images):
 for variation,q in [('exact',im),('lighting',ImageEnhance.Brightness(im).enhance(1.15)),('rotation',im.rotate(10)),('blur',im.filter(ImageFilter.GaussianBlur(3)))]:queries.append(q);labels.append((i,variation))
def encode(batch):
 with torch.inference_mode():
  v=model.get_image_features(**processor(images=batch,return_tensors='pt'));return v/v.norm(dim=-1,keepdim=True)
t=time.monotonic();catalog=encode(images);inference=[]
for (label,variation),query in zip(labels,queries):
 v=encode([query]);scores=(v@catalog.T)[0];rank=scores.argsort(descending=True).tolist();inference.append({'source':label,'variation':variation,'top1':rank[0]==label,'top5':label in rank[:5],'scores':scores.tolist()})
negative=Image.effect_noise((180,180),80).convert('RGB');negative_scores=(encode([negative])@catalog.T)[0].tolist()
out={'model':'google/siglip2-base-patch16-224','revision':'75de2d55ec2d0b4efc50b3e9ad70dba96a7b2fa2','real_inference':True,'device':'CPU','dimensions':catalog.shape[1],'load_seconds':load,'inference_seconds':time.monotonic()-t,'cases':inference,'top1':sum(x['top1'] for x in inference),'top5':sum(x['top5'] for x in inference),'negative_unrelated_scores':negative_scores,'no_match_policy':'Human none-of-these option; automatic thresholds uncalibrated.','genuine_phone_images':0,'qualification':'Generated regression only. No field recognition accuracy claim.'}
(r/'evidence/learned-image-evaluation.json').write_text(json.dumps(out,indent=2));print(json.dumps({k:out[k] for k in ['dimensions','load_seconds','inference_seconds','top1','top5']}))
