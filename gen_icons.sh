#!/bin/bash
SRC="/mnt/c/Users/josee/OneDrive/Ambiente de Trabalho/12.º ANO/A.I/stockvision-icon-source.png"
ICONS="/home/josee/projects/nvcloud/stockvision-sf/icons"
DEST="/home/josee/projects/nvcloud"

echo "=== Source file ==="
identify -format "Size: %wx%h, Type: %[type], Alpha: %A\n" "$SRC" 2>&1

echo ""
echo "=== Copying original as icon-512 (for PWA - keep rounded corners) ==="
convert "$SRC" -resize 512x512 "$ICONS/icon-512.png"
convert "$SRC" -resize 512x512 "$ICONS/icon-512-cropped.png"
convert "$SRC" -resize 192x192 "$ICONS/icon-192.png"
convert "$SRC" -resize 180x180 "$ICONS/logo-180.png"
convert "$SRC" -resize 128x128 "$ICONS/logo-128.png"
convert "$SRC" -resize 48x48  "$ICONS/logo-48.png"

echo ""
echo "=== Creating SQUARE favicon (no rounded corners) ==="
# Get the golden background color from the image center
GOLDEN=$(convert "$SRC" -gravity center -crop 100x100+0+0 +repage -format "%[pixel:u.p{50,50}]" info: 2>/dev/null)
echo "Golden color detected: $GOLDEN"

# Create square versions: fill the rounded corner gaps with golden background
convert "$SRC" \
  -background "$GOLDEN" \
  -alpha remove \
  -resize 32x32 \
  "$DEST/favicon.png"

convert "$SRC" \
  -background "$GOLDEN" \
  -alpha remove \
  -resize 32x32 \
  "$DEST/assets/favicon.png"

convert "$SRC" \
  -background "$GOLDEN" \
  -alpha remove \
  -resize 32x32 \
  "$DEST/assets/favicon-new.png"

# Also square versions for logos
convert "$SRC" -background "$GOLDEN" -alpha remove -resize 32x32 "$ICONS/logo-32.png"
convert "$SRC" -background "$GOLDEN" -alpha remove -resize 16x16 "$ICONS/logo-16.png"

# ICO
convert \
  \( "$SRC" -background "$GOLDEN" -alpha remove -resize 16x16 \) \
  \( "$SRC" -background "$GOLDEN" -alpha remove -resize 32x32 \) \
  \( "$SRC" -background "$GOLDEN" -alpha remove -resize 48x48 \) \
  "$DEST/favicon.ico"

echo ""
echo "=== Done ==="
ls -la "$DEST/favicon.ico" "$DEST/favicon.png"
