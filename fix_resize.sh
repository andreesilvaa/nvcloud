#!/bin/bash
# The icon-512.png already has transparent background.
# Just resize properly with transparent background forced.

SRC=/home/josee/projects/nvcloud/stockvision-sf/icons/icon-512.png
ICONS=/home/josee/projects/nvcloud/stockvision-sf/icons
DEST=/home/josee/projects/nvcloud

echo "=== Resizing from transparent icon-512.png ==="

for size in 512 192 180 128 48 32 16; do
  convert "$SRC" \
    -background none \
    -resize ${size}x${size} \
    -depth 8 \
    "$ICONS/logo-${size}.png" 2>/dev/null || \
  convert "$SRC" \
    -background none \
    -resize ${size}x${size} \
    -depth 8 \
    "$ICONS/icon-${size}.png" 2>/dev/null
  echo "Done ${size}x${size}"
done

# Specific named files
convert "$SRC" -background none -resize 192x192 "$ICONS/icon-192.png"
convert "$SRC" -background none -resize 512x512 "$ICONS/icon-512-cropped.png"
convert "$SRC" -background none -resize 32x32  "$DEST/favicon.png"
convert "$SRC" -background none -resize 32x32  "$DEST/assets/favicon.png"
convert "$SRC" -background none -resize 32x32  "$DEST/assets/favicon-new.png"

# Regenerate ICO with multiple sizes
convert \
  \( "$SRC" -background none -resize 16x16 \) \
  \( "$SRC" -background none -resize 32x32 \) \
  \( "$SRC" -background none -resize 48x48 \) \
  "$DEST/favicon.ico"

echo ""
echo "=== Verify favicon.png corners ==="
convert "$DEST/favicon.png" txt:- | head -4

echo ""
ls -la "$DEST/favicon.ico" "$DEST/favicon.png"
