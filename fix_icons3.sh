#!/bin/bash
cd /home/josee/projects/nvcloud/stockvision-sf/icons

echo "=== Checking alpha channels ==="
for f in logo-16.png logo-32.png logo-48.png; do
  echo -n "$f: "
  identify -format "%[channels] alpha=%A\n" "$f" 2>/dev/null || echo "identify failed"
done

echo ""
echo "=== Removing white background more aggressively ==="
for f in logo-16.png logo-32.png logo-48.png logo-128.png logo-180.png icon-192.png icon-512.png icon-512-cropped.png; do
  echo "Processing $f..."
  convert "$f" \
    -fuzz 15% -transparent white \
    -fuzz 10% -transparent "#ffffff" \
    -fuzz 10% -transparent "#fefefe" \
    -fuzz 10% -transparent "#f0f0f0" \
    "$f"
done

echo "Done!"
