#!/bin/bash
cd /home/josee/projects/nvcloud

echo "=== favicon files ==="
ls -la favicon.* 2>/dev/null || echo "No favicon files in root"
ls -la assets/favicon* 2>/dev/null || echo "No favicons in assets/"

echo ""
echo "=== Fix: aggressive white removal from favicon.png ==="
if [ -f favicon.png ]; then
  # Use background flatten approach: composite over bright color to expose white, then remove
  convert favicon.png \
    -background white -alpha remove -alpha off \
    /tmp/flat.png
  echo "favicon.png has these colors at corners:"
  convert favicon.png txt:- 2>/dev/null | head -3
  
  # More aggressive: trim white by converting to alpha using white as the matte color
  convert favicon.png \
    -fuzz 20% -transparent white \
    -fuzz 15% -transparent "#f0f0f0" \
    -fuzz 15% -transparent "#f5f5f5" \
    favicon.png
  echo "favicon.png processed"
fi

echo ""
echo "=== Also fix stockvision-sf/icons aggressively ==="
cd /home/josee/projects/nvcloud/stockvision-sf/icons
for f in logo-16.png logo-32.png logo-48.png logo-128.png logo-180.png; do
  echo "Processing $f with flatten method..."
  # Get the logo on transparent bg by using white-to-alpha
  convert "$f" \
    -fuzz 20% -transparent white \
    -fuzz 15% -transparent "#f5f5f5" \
    -fuzz 15% -transparent "#eeeeee" \
    "$f"
  echo "$f done"
done

echo "All done!"
