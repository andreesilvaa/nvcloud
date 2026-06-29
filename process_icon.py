from PIL import Image

src = Image.open('/home/josee/projects/nvcloud/stockvision-logo-app.png').convert('RGBA')
print(f'Source: {src.size}')

# Find non-white bounding box without numpy
w, h = src.size
pixels = src.load()

top = h
bottom = 0
left = w
right = 0

for y in range(h):
    for x in range(w):
        r, g, b, a = pixels[x, y]
        if not (r > 225 and g > 225 and b > 225):
            if y < top: top = y
            if y > bottom: bottom = y
            if x < left: left = x
            if x > right: right = x

print(f'Bounds: t={top} b={bottom} l={left} r={right}, size={right-left+1}x{bottom-top+1}')

pad = 2
crop_l = max(0, left - pad)
crop_t = max(0, top - pad)
crop_r = min(w, right + pad + 1)
crop_b = min(h, bottom + pad + 1)

cropped = src.crop((crop_l, crop_t, crop_r, crop_b))
cw, ch = cropped.size
side = max(cw, ch)
square = Image.new('RGBA', (side, side), (0, 0, 0, 0))
square.paste(cropped, ((side - cw) // 2, (side - ch) // 2))
print(f'Square: {square.size}')

base = '/home/josee/projects/nvcloud/stockvision-sf/icons/'
for name, size in [('icon-512.png',512),('icon-192.png',192),('logo-128.png',128),('logo-180.png',180),('logo-48.png',48),('logo-32.png',32),('logo-16.png',16)]:
    square.resize((size, size), Image.LANCZOS).save(base + name)
    print(f'Saved {name}')

root = '/home/josee/projects/nvcloud/'
i32 = square.resize((32, 32), Image.LANCZOS)
i32.save(root + 'favicon.png')
i32.save(root + 'favicon-new.png')
i16 = square.resize((16, 16), Image.LANCZOS)
i48 = square.resize((48, 48), Image.LANCZOS)
i16.save(root + 'favicon.ico', format='ICO', sizes=[(16,16),(32,32),(48,48)])
i16.save(root + 'favicon-new.ico', format='ICO', sizes=[(16,16),(32,32),(48,48)])
print('All icons saved successfully!')
