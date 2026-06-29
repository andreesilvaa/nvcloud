from PIL import Image

img = Image.open('/home/josee/projects/nvcloud/stockvision-sf/icons/icon-512.png').convert('RGB')
w, h = img.size
print(f'icon-512.png: {w}x{h}')

# Verificar bordas
print('Bordas (devem ser douradas, nao brancas):')
print(f'  top-left (0,0):      {img.getpixel((0,0))}')
print(f'  top-right ({w-1},0): {img.getpixel((w-1,0))}')
print(f'  bot-left (0,{h-1}):  {img.getpixel((0,h-1))}')
print(f'  bot-right:           {img.getpixel((w-1,h-1))}')
print(f'  top-mid:             {img.getpixel((w//2,0))}')
print(f'  left-mid:            {img.getpixel((0,h//2))}')

# Verificar logo-32 que vai para o favicon
img32 = Image.open('/home/josee/projects/nvcloud/stockvision-sf/icons/logo-32.png').convert('RGB')
print(f'\nlogo-32.png: {img32.size}')
print(f'  top-left:  {img32.getpixel((0,0))}')
print(f'  center:    {img32.getpixel((16,16))}')

# Verificar favicon.png
fav = Image.open('/home/josee/projects/nvcloud/favicon.png').convert('RGB')
print(f'\nfavicon.png: {fav.size}')
print(f'  top-left:  {fav.getpixel((0,0))}')
print(f'  center:    {fav.getpixel((16,16))}')
