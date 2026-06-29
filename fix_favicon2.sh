#!/bin/bash
cd /home/josee/projects/nvcloud

echo "=== Remaining white pixels check ==="
convert favicon.png txt:- 2>/dev/null | head -5

echo ""
echo "=== Regenerate favicon.ico from clean favicon.png ==="
# Create a proper ICO with transparent background
convert favicon.png \
  -fuzz 20% -transparent white \
  \( -clone 0 -resize 16x16 \) \
  \( -clone 0 -resize 32x32 \) \
  \( -clone 0 -resize 48x48 \) \
  -delete 0 \
  favicon.ico

echo "favicon.ico regenerated"
ls -la favicon.ico favicon.png

echo ""
echo "=== Also regenerate from icon-512.png (higher quality source) ==="
SRC=/home/josee/projects/nvcloud/stockvision-sf/icons/icon-512.png
DEST=/home/josee/projects/nvcloud

# Create clean versions from 512 source
convert "$SRC" -fuzz 20% -transparent white -resize 32x32 "$DEST/favicon.png"
convert "$SRC" -fuzz 20% -transparent white \
  \( -clone 0 -resize 16x16 \) \
  \( -clone 0 -resize 32x32 \) \
  \( -clone 0 -resize 48x48 \) \
  -delete 0 \
  "$DEST/favicon.ico"

# Also update assets/
cp "$DEST/favicon.png" "$DEST/assets/favicon.png"
cp "$DEST/favicon.png" "$DEST/assets/favicon-new.png"

echo "All favicon files regenerated from icon-512.png"
ls -la "$DEST/favicon.ico" "$DEST/favicon.png" "$DEST/assets/favicon.png"
