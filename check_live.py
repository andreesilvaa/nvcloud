import urllib.request
from PIL import Image
import io

# Testar o favicon live do servidor
url = 'https://stockvision.pt/favicon.png'
try:
    req = urllib.request.urlopen(url, timeout=5)
    data = req.read()
    img = Image.open(io.BytesIO(data)).convert('RGBA')
    print(f'Live favicon.png: {img.size}')
    print(f'  corner (0,0): RGBA={img.getpixel((0,0))}')
    print(f'  center: RGBA={img.getpixel((img.width//2, img.height//2))}')
except Exception as e:
    print(f'Erro: {e}')

# Comparar com o ficheiro local
local = Image.open('/home/josee/projects/nvcloud/favicon.png').convert('RGBA')
print(f'\nLocal favicon.png: {local.size}')
print(f'  corner (0,0): RGBA={local.getpixel((0,0))}')
print(f'  center: RGBA={local.getpixel((local.width//2, local.height//2))}')
