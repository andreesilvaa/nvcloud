#!/bin/bash
# Use the uploaded original logo (with golden background) as the source
# It was saved to the uploads folder by the assistant

ORIG="/home/josee/projects/nvcloud/logo_original.png"
ICONS="/home/josee/projects/nvcloud/stockvision-sf/icons"
DEST="/home/josee/projects/nvcloud"

# Check if we have the original
if [ ! -f "$ORIG" ]; then
  echo "ERROR: $ORIG not found"
  # Try to find it
  find /home/josee/projects/nvcloud -name "*.png" -newer /home/josee/projects/nvcloud/app.php 2>/dev/null | head -10
  exit 1
fi

echo "Source: $(identify -format '%wx%h %[type]' $ORIG)"

# Generate all sizes - NO transparency removal, keep golden background as-is
for size in 16 32 48 128 180 192 512; do
  convert "$ORIG" -resize ${size}x${size} "$ICONS/logo-${size}.png" 2>/dev/null
  echo "Created ${size}x${size}"
done

convert "$ORIG" -resize 192x192 "$ICONS/icon-192.png"
convert "$ORIG" -resize 512x512 "$ICONS/icon-512.png"
convert "$ORIG" -resize 512x512 "$ICONS/icon-512-cropped.png"
convert "$ORIG" -resize 32x32  "$DEST/favicon.png"
convert "$ORIG" -resize 32x32  "$DEST/assets/favicon.png"
convert "$ORIG" -resize 32x32  "$DEST/assets/favicon-new.png"

# ICO with multiple sizes
convert \
  \( "$ORIG" -resize 16x16 \) \
  \( "$ORIG" -resize 32x32 \) \
  \( "$ORIG" -resize 48x48 \) \
  "$DEST/favicon.ico"

echo "All done!"
ls -la "$DEST/favicon.ico" "$DEST/favicon.png"
