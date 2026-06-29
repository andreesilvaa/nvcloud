#!/bin/bash
cd /home/josee/projects/nvcloud/stockvision-sf/icons

echo "=== Pixel check: corner pixels of logo-32.png ==="
# Check top-left corner pixel
convert logo-32.png -format "%[fx:p{0,0}.a]" info: 2>/dev/null && echo " (alpha of top-left pixel)"
convert logo-32.png txt:- 2>/dev/null | head -5

echo ""
echo "=== Checking if icons are truly transparent ==="
for f in logo-16.png logo-32.png logo-48.png; do
  # Count non-transparent pixels that are near-white
  white_px=$(convert "$f" -fuzz 20% -fill none -opaque white -alpha extract -threshold 50% -format "%[fx:w*h - mean*w*h]" info: 2>/dev/null)
  echo "$f: approx $white_px pixels still white-ish"
done

echo ""
echo "=== Source of favicons in app.php ==="
grep -n "rel=\"icon\"" /home/josee/projects/nvcloud/app.php | head -10
