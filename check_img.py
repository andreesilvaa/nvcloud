from PIL import Image

img = Image.open('/home/josee/projects/nvcloud/assets/logo-stockvision-app.png').convert('RGB')
w, h = img.size
print(f'Size: {w}x{h}')

# Verificar cantos e bordas — se são brancos/claros
corners = [
    img.getpixel((0, 0)),
    img.getpixel((w-1, 0)),
    img.getpixel((0, h-1)),
    img.getpixel((w-1, h-1)),
    img.getpixel((w//2, 0)),    # topo centro
    img.getpixel((0, h//2)),    # esquerda centro
    img.getpixel((w//2, h-1)),  # base centro
    img.getpixel((w-1, h//2)),  # direita centro
]
labels = ['top-left','top-right','bot-left','bot-right','top-mid','left-mid','bot-mid','right-mid']
for label, px in zip(labels, corners):
    print(f'  {label}: RGB{px}')

# Contar pixels brancos/muito claros (R>230, G>230, B>230)
pixels = list(img.getdata())
white_pixels = sum(1 for p in pixels if p[0]>230 and p[1]>230 and p[2]>230)
print(f'Very light pixels (>230,>230,>230): {white_pixels} / {len(pixels)} = {white_pixels/len(pixels)*100:.1f}%')

# Cor media do centro
cx, cy = w//2, h//2
sample = [img.getpixel((cx+dx, cy+dy)) for dx in range(-5,6) for dy in range(-5,6)]
avg = tuple(sum(p[i] for p in sample)//len(sample) for i in range(3))
print(f'Center color (avg): RGB{avg}')
