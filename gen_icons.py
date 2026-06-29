from PIL import Image
import os

src = '/home/josee/projects/nvcloud/assets/logo-stockvision-app.png'
img = Image.open(src).convert('RGBA')
w, h = img.size
print(f'Source: {w}x{h}')

# Remover fundo branco tornando-o transparente (flood fill do canto)
# Substitui pixels brancos/muito claros por transparentes
def remove_white_bg(image, threshold=240):
    img_rgba = image.copy()
    pixels = img_rgba.load()
    iw, ih = img_rgba.size

    # Flood fill a partir dos 4 cantos para encontrar fundo branco externo
    from collections import deque
    visited = set()
    queue = deque()

    # Semear dos 4 cantos
    for sx, sy in [(0,0),(iw-1,0),(0,ih-1),(iw-1,ih-1)]:
        if (sx, sy) not in visited:
            r, g, b, a = pixels[sx, sy]
            if r >= threshold and g >= threshold and b >= threshold:
                queue.append((sx, sy))
                visited.add((sx, sy))

    while queue:
        x, y = queue.popleft()
        r, g, b, a = pixels[x, y]
        if r >= threshold and g >= threshold and b >= threshold:
            pixels[x, y] = (r, g, b, 0)  # tornar transparente
            for nx, ny in [(x+1,y),(x-1,y),(x,y+1),(x,y-1)]:
                if 0 <= nx < iw and 0 <= ny < ih and (nx,ny) not in visited:
                    visited.add((nx, ny))
                    queue.append((nx, ny))

    return img_rgba

print('Removing white background...')
img_nobg = remove_white_bg(img, threshold=240)

# Auto-crop para remover bordas transparentes desnecessárias
bbox = img_nobg.getbbox()
if bbox:
    img_cropped = img_nobg.crop(bbox)
    print(f'Cropped to content: {img_cropped.size}')
else:
    img_cropped = img_nobg
    print('No crop needed')

# Tornar quadrada (padding simétrico se necessário)
cw, ch = img_cropped.size
side = max(cw, ch)
img_sq = Image.new('RGBA', (side, side), (0, 0, 0, 0))
offset_x = (side - cw) // 2
offset_y = (side - ch) // 2
img_sq.paste(img_cropped, (offset_x, offset_y))
print(f'Square canvas: {img_sq.size}')

def make_icon(size, fmt='RGBA'):
    resized = img_sq.resize((size, size), Image.LANCZOS)
    if fmt == 'RGB':
        bg = Image.new('RGB', (size, size), (255, 255, 255))
        bg.paste(resized, mask=resized.split()[3])
        return bg
    return resized

icons_dir = '/home/josee/projects/nvcloud/stockvision-sf/icons'
root_dir  = '/home/josee/projects/nvcloud'

sizes = {
    'logo-16.png':  16,
    'logo-32.png':  32,
    'logo-48.png':  48,
    'logo-128.png': 128,
    'logo-180.png': 180,
    'icon-192.png': 192,
    'icon-512.png': 512,
}

for fname, size in sizes.items():
    out = make_icon(size, 'RGBA')  # PNG com transparência
    path = os.path.join(icons_dir, fname)
    out.save(path, 'PNG', optimize=True)
    print(f'  Saved {path} ({size}x{size})')

# favicon.png — PNG com transparência
favicon32 = make_icon(32, 'RGBA')
favicon32.save(os.path.join(root_dir, 'favicon.png'), 'PNG', optimize=True)
favicon32.save(os.path.join(root_dir, 'favicon-new.png'), 'PNG', optimize=True)
favicon32.save('/home/josee/projects/nvcloud/assets/favicon.png', 'PNG', optimize=True)
favicon32.save('/home/josee/projects/nvcloud/assets/favicon-new.png', 'PNG', optimize=True)
print('  Saved favicon.png (transparent)')

# favicon.ico — ICO não suporta bem transparência, usar RGB com fundo branco
favicon_ico = make_icon(32, 'RGB')
favicon_ico.save(os.path.join(root_dir, 'favicon.ico'), format='ICO', sizes=[(16,16),(32,32)])
favicon_ico.save(os.path.join(root_dir, 'favicon-new.ico'), format='ICO', sizes=[(16,16),(32,32)])
print('  Saved favicon.ico')

print('ALL DONE')
