"""Offline learned image encoder. Model installation is a separate operator action."""
import json, os, sys, base64, io
from pathlib import Path
from assistant_image import describe
from PIL import Image, ImageOps
import torch
from transformers import AutoModel, AutoProcessor

try:
    payload=sys.stdin.buffer.read(5_500_001)
    if len(payload)>5_500_000:raise ValueError('payload_too_large')
    encoded=json.loads(payload)['image']
    result=describe(encoded)  # validates content/pixels and strips preview metadata
    folder=os.environ['AIMS_ASSISTANT_ENCODER_PATH']
    torch.set_num_threads(2)
    processor=AutoProcessor.from_pretrained(folder,local_files_only=True)
    model=AutoModel.from_pretrained(folder,local_files_only=True).eval()
    with Image.open(io.BytesIO(base64.b64decode(encoded,validate=True))) as image:
        image=ImageOps.exif_transpose(image).convert('RGB')
        inputs=processor(images=image,return_tensors='pt')
        with torch.inference_mode():
            vector=model.get_image_features(**inputs)
            vector=vector/vector.norm(dim=-1,keepdim=True)
    result['vector']=vector[0].tolist();result['method']='siglip2-base-patch16-224'
    print(json.dumps(result))
except Exception as exc:
    print(json.dumps({'error':type(exc).__name__}));sys.exit(2)
