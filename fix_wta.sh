#!/bin/bash
# White-to-alpha technique: removes white background preserving anti-aliasing
# Based on GIMP's "Color to Alpha" algorithm

SRC=/home/josee/projects/nvcloud/stockvision-sf/icons/icon-512.png
DEST=/home/josee/projects/nvcloud

echo "=== Applying white-to-alpha on icon-512.png ==="

# Check if icon-512.png already has the logo on transparent or white bg
identify -format "Size: %wx%h, Alpha: %A\n" "$SRC"
convert "$SRC" txt:- 2>/dev/null | head -3

echo ""
echo "=== Generating clean transparent versions ==="

# White-to-alpha: use the channel operations to make white transparent
# This is equivalent to GIMP's Colors > Color to Alpha (white)
python3 << 'PYEOF'
from PIL import Image
import numpy as np
import os

src = "/home/josee/projects/nvcloud/stockvision-sf/icons/icon-512.png"
img = Image.open(src).convert("RGBA")
data = np.array(img, dtype=float)

r, g, b, a = data[:,:,0], data[:,:,1], data[:,:,2], data[:,:,3]

# Color-to-alpha algorithm (white = 255,255,255)
# For each pixel, compute new alpha based on how far it is from white
max_val = np.maximum(np.maximum(r, g), b)

# New alpha: how non-white the pixel is (0=white, 255=fully colored)
new_alpha = (255 - max_val)
# But also consider existing alpha
new_alpha = np.minimum(new_alpha * (a / 255.0), 255)

# For pixels where max_val < 255, we need to "remove" the white contribution
# new_color = (original - white*(1-new_alpha/255)) / (new_alpha/255)
mask = new_alpha > 0
result = data.copy()

# Keep RGB but boost it (remove white mixing)
alpha_norm = np.where(mask, new_alpha / 255.0, 1.0)
# Inverse: if pixel = color * alpha + white * (1-alpha)
# Then color = (pixel - white*(1-alpha)) / alpha
for ch in range(3):
    result[:,:,ch] = np.where(
        mask,
        np.clip((data[:,:,ch] - 255 * (1 - alpha_norm)) / np.where(alpha_norm > 0, alpha_norm, 1), 0, 255),
        data[:,:,ch]
    )

result[:,:,3] = np.clip(new_alpha, 0, 255)
result = result.astype(np.uint8)

out = Image.fromarray(result, 'RGBA')

dest = "/home/josee/projects/nvcloud"
icons_dir = "/home/josee/projects/nvcloud/stockvision-sf/icons"

# Save at various sizes
for size, fname in [
    (512, f"{icons_dir}/icon-512.png"),
    (192, f"{icons_dir}/icon-192.png"),
    (180, f"{icons_dir}/logo-180.png"),
    (128, f"{icons_dir}/logo-128.png"),
    (48,  f"{icons_dir}/logo-48.png"),
    (32,  f"{icons_dir}/logo-32.png"),
    (32,  f"{dest}/favicon.png"),
    (32,  f"{dest}/assets/favicon.png"),
    (32,  f"{dest}/assets/favicon-new.png"),
    (16,  f"{icons_dir}/logo-16.png"),
]:
    resized = out.resize((size, size), Image.LANCZOS)
    resized.save(fname, "PNG")
    print(f"Saved {size}x{size}: {fname}")

print("All done!")
PYEOF

echo ""
echo "=== Regenerating favicon.ico ==="
convert \
  /home/josee/projects/nvcloud/stockvision-sf/icons/logo-16.png \
  /home/josee/projects/nvcloud/stockvision-sf/icons/logo-32.png \
  /home/josee/projects/nvcloud/stockvision-sf/icons/logo-48.png \
  /home/josee/projects/nvcloud/favicon.ico

echo "favicon.ico done"
ls -la /home/josee/projects/nvcloud/favicon.ico /home/josee/projects/nvcloud/favicon.png
