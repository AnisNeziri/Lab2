"""Build contact sheets from local browser-review screenshots (diagnostics only)."""
from pathlib import Path
import sys
from PIL import Image, ImageDraw

folder = Path(sys.argv[1])
files = sorted(p for p in folder.glob('*.png') if not p.name.startswith('contact-'))
for offset in range(0, len(files), 6):
    sheet = Image.new('RGB', (2049, 832), '#cbd5e1')
    draw = ImageDraw.Draw(sheet)
    for n, file in enumerate(files[offset:offset + 6]):
        x, y = (n % 3) * 683, (n // 3) * 416
        draw.text((x + 8, y + 6), file.stem, fill='#0f172a')
        with Image.open(file) as shot:
            shot.thumbnail((683, 384))
            sheet.paste(shot, (x, y + 28))
    sheet.save(folder / f'contact-{offset // 6 + 1:02}.jpg')
