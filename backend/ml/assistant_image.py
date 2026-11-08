"""Bounded local colour/texture baseline. No model, OCR, network or file writes."""
import base64, io, json, sys, warnings
from PIL import Image, ImageOps, ImageStat
Image.MAX_IMAGE_PIXELS = 8_000_000
warnings.simplefilter('error', Image.DecompressionBombWarning)

def describe(encoded, preview_size=240):
    raw = base64.b64decode(encoded, validate=True)
    if len(raw) > 4_000_000:
        raise ValueError('image_too_large')
    with Image.open(io.BytesIO(raw)) as im:
        if im.format not in ('JPEG', 'PNG', 'WEBP') or im.width * im.height > 8_000_000 or getattr(im, 'n_frames', 1) != 1:
            raise ValueError('unsupported_image')
        im.load()
        im = ImageOps.exif_transpose(im).convert('RGB')
        small = im.resize((8, 8))
        pixels = list(small.getdata())
        vector = [v / 255 for rgb in pixels for v in rgb]
        texture = ImageStat.Stat(im.convert('L').resize((64, 64))).stddev[0] / 128
        preview = io.BytesIO()
        im.thumbnail((preview_size, preview_size))
        im.save(preview, 'JPEG', quality=75)  # No EXIF/metadata copied.
        return {'vector': vector + [texture], 'preview': base64.b64encode(preview.getvalue()).decode()}

if __name__ == '__main__':
    try:
        payload = sys.stdin.buffer.read(5_500_001)
        if len(payload) > 5_500_000:
            raise ValueError('payload_too_large')
        data=json.loads(payload)
        print(json.dumps(describe(data['image'],1280 if data.get('screenshot') else 240)))
    except Exception:
        print(json.dumps({'error': 'invalid_or_unsupported_image'}))
        sys.exit(2)
