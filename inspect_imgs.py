from PIL import Image
import os

files = [
    'assets/logo-stockvision-app.png',
    'stockvisionAI.png',
    'assets/stock.vision.png',
    'stockvision-sf/icons/icon-512.png',
    'assets/newvision-logo.png',
    'assets/logo-stockvision-app.png',
]

for f in files:
    if not os.path.exists(f):
        print(f'{f}: NAO EXISTE')
        continue
    img = Image.open(f).convert('RGB')
    w, h = img.size
    center = img.getpixel((w//2, h//2))
    topleft = img.getpixel((min(50,w-1), min(50,h-1)))
    # contar pixels com tom dourado (R>150, G>100, B<100)
    pixels = list(img.getdata())
    golden = sum(1 for p in pixels if p[0]>150 and p[1]>100 and p[2]<120)
    white  = sum(1 for p in pixels if p[0]>230 and p[1]>230 and p[2]>230)
    total  = len(pixels)
    print(f'{f}:')
    print(f'  Size: {w}x{h}  Center: {center}  TopLeft: {topleft}')
    print(f'  Golden pixels: {golden/total*100:.1f}%  White pixels: {white/total*100:.1f}%')
