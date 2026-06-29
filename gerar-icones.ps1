param(
    [Parameter(Mandatory=$true)]
    [string]$SourceImage,

    [Parameter(Mandatory=$false)]
    [string]$OutputIcons = "$PSScriptRoot\stockvision-sf\icons",

    [Parameter(Mandatory=$false)]
    [string]$OutputRoot = $PSScriptRoot,

    [Parameter(Mandatory=$false)]
    [int]$BgThreshold = 240
)

if (-not (Test-Path -LiteralPath $SourceImage)) {
    Write-Error "Ficheiro não encontrado: $SourceImage"
    exit 1
}

Add-Type -AssemblyName System.Drawing

New-Item -ItemType Directory -Path $OutputIcons -Force | Out-Null

$srcImg = [System.Drawing.Image]::FromFile((Resolve-Path -LiteralPath $SourceImage).Path)
$srcBmp = New-Object System.Drawing.Bitmap($srcImg)
$w = $srcBmp.Width
$h = $srcBmp.Height

Write-Host "Imagem original: ${w}x${h}" -ForegroundColor Cyan

# ── Step 1: find content bounds (exclude near-white) ──
Write-Host "[1/3] A analisar imagem..." -ForegroundColor Cyan

$minX = $w; $maxX = 0; $minY = $h; $maxY = 0
for ($y = 0; $y -lt $h; $y++) {
    for ($x = 0; $x -lt $w; $x++) {
        $px = $srcBmp.GetPixel($x, $y)
        $isNearWhite = ($px.R -ge $BgThreshold -and $px.G -ge $BgThreshold -and $px.B -ge $BgThreshold -and $px.A -ge 200)
        if (-not $isNearWhite) {
            if ($x -lt $minX) { $minX = $x }
            if ($x -gt $maxX) { $maxX = $x }
            if ($y -lt $minY) { $minY = $y }
            if ($y -gt $maxY) { $maxY = $y }
        }
    }
}

if ($maxX -eq 0 -or $maxY -eq 0) {
    Write-Host "  Aviso: conteúdo não detectado, a usar imagem completa" -ForegroundColor Yellow
    $minX = 0; $minY = 0; $maxX = $w - 1; $maxY = $h - 1
}

$cropW = $maxX - $minX + 1
$cropH = $maxY - $minY + 1
Write-Host "  Conteúdo real: ${cropW}x${cropH} (margem $minX,$minY)" -ForegroundColor Gray

# ── Step 2: crop (keep white bg for crisp rendering at small sizes) ──
Write-Host "[2/3] A cortar..." -ForegroundColor Cyan

$cropBmp = New-Object System.Drawing.Bitmap($cropW, $cropH)
$gCrop = [System.Drawing.Graphics]::FromImage($cropBmp)
$gCrop.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$gCrop.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
$gCrop.DrawImage($srcBmp, 0, 0, [System.Drawing.Rectangle]::new($minX, $minY, $cropW, $cropH), [System.Drawing.GraphicsUnit]::Pixel)
$gCrop.Dispose()
$srcBmp.Dispose()
$srcImg.Dispose()

# ── Step 3: resize ──
Write-Host "[3/3] A redimensionar..." -ForegroundColor Cyan

$sizes = @(
    @{Size=16;  Name="logo-16.png";     Purpose="favicon barra favoritos Chrome"}
    @{Size=32;  Name="logo-32.png";     Purpose="ícone extensão Windows / favicon HD"}
    @{Size=48;  Name="logo-48.png";     Purpose="gestão extensões Chrome"}
    @{Size=128; Name="logo-128.png";    Purpose="instalação extensão / Chrome Web Store"}
    @{Size=180; Name="logo-180.png";    Purpose="Apple Touch Icon"}
    @{Size=192; Name="icon-192.png";    Purpose="PWA Android / Chrome (maskable)"}
    @{Size=512; Name="icon-512.png";    Purpose="PWA manifesto / Google Play (maskable)"}
)

foreach ($s in $sizes) {
    $sz = $s.Size
    $name = $s.Name
    $path = Join-Path -Path $OutputIcons -ChildPath $name

    Write-Host "  [$sz`x$sz] $name" -ForegroundColor Gray

    $ratio = [Math]::Min($sz / $cropW, $sz / $cropH)
    $drawW = [Math]::Max(1, [int][Math]::Round($cropW * $ratio))
    $drawH = [Math]::Max(1, [int][Math]::Round($cropH * $ratio))
    $offX = [int]($sz - $drawW) / 2
    $offY = [int]($sz - $drawH) / 2

    $resized = New-Object System.Drawing.Bitmap($sz, $sz)
    $g = [System.Drawing.Graphics]::FromImage($resized)
    $g.Clear([System.Drawing.Color]::White)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality

    $g.DrawImage($cropBmp, $offX, $offY, $drawW, $drawH)
    $g.Dispose()

    # For PWA icons (192, 512) add maskable purpose — fill the whole area
    if ($sz -eq 192 -or $sz -eq 512) {
        # Fill to square: draw directly without centering (crop to fill)
        $fillBmp = New-Object System.Drawing.Bitmap($sz, $sz)
        $gFill = [System.Drawing.Graphics]::FromImage($fillBmp)
        $gFill.Clear([System.Drawing.Color]::White)
        $gFill.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
        $gFill.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
        $gFill.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality

        # Fill: cover the whole canvas
        $fillRatio = [Math]::Max($sz / $cropW, $sz / $cropH)
        $fillW = [int][Math]::Round($cropW * $fillRatio)
        $fillH = [int][Math]::Round($cropH * $fillRatio)
        $fillOffX = [int]($sz - $fillW) / 2
        $fillOffY = [int]($sz - $fillH) / 2
        $gFill.DrawImage($cropBmp, $fillOffX, $fillOffY, $fillW, $fillH)
        $gFill.Dispose()
        $fillBmp.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
        $fillBmp.Dispose()
    } else {
        $resized.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    }

    $resized.Dispose()
    Write-Host "    -> OK ($((Get-Item -LiteralPath $path).Length / 1KB -as [int]) KB)" -ForegroundColor Green
}

$cropBmp.Dispose()

# ── Favicon files from 32x32 (crisp composite onto white) ──
Write-Host "[4/4] A gerar favicon.ico e favicon.png..." -ForegroundColor Cyan

# Reuse logo-32.png (already on white bg) as favicon.png
Copy-Item (Join-Path -Path $OutputIcons -ChildPath "logo-32.png") -Destination (Join-Path -Path $OutputRoot -ChildPath "favicon.png") -Force

# Build .ico from favicon.png
$icoSrc = [System.Drawing.Image]::FromFile((Join-Path -Path $OutputRoot -ChildPath "favicon.png"))
$icoBmp = New-Object System.Drawing.Bitmap($icoSrc)
$ms = New-Object System.IO.MemoryStream
$icoBmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Bmp)

$bmpBytes = $ms.ToArray()
$ms.Close()
$ms.Dispose()
$icoBmp.Dispose()
$icoSrc.Dispose()

$icoSize = 32
$dibHeaderSize = 40
$pixelDataOffset = 14 + $dibHeaderSize
$icoImageData = $bmpBytes[$pixelDataOffset..($bmpBytes.Length - 1)]
$dibOnly = $bmpBytes[14..($pixelDataOffset - 1)] + $icoImageData

$icoHeader = New-Object System.Byte[] 6
$icoHeader[0] = 0; $icoHeader[1] = 0
$icoHeader[2] = 1; $icoHeader[3] = 0
$icoHeader[4] = 1; $icoHeader[5] = 0

$entry = New-Object System.Byte[] 16
$entry[0] = $icoSize; $entry[1] = $icoSize
$entry[2] = 0; $entry[3] = 0
$entry[4] = 1; $entry[5] = 0
$entry[6] = 32; $entry[7] = 0
$imageSize = $dibOnly.Length
$entry[8] = $imageSize -band 0xFF
$entry[9] = ($imageSize -shr 8) -band 0xFF
$entry[10] = ($imageSize -shr 16) -band 0xFF
$entry[11] = ($imageSize -shr 24) -band 0xFF
$imageOffset = 22
$entry[12] = $imageOffset -band 0xFF
$entry[13] = ($imageOffset -shr 8) -band 0xFF
$entry[14] = ($imageOffset -shr 16) -band 0xFF
$entry[15] = ($imageOffset -shr 24) -band 0xFF

[System.IO.File]::WriteAllBytes((Join-Path -Path $OutputRoot -ChildPath "favicon.ico"), ($icoHeader + $entry + $dibOnly))
Write-Host "  favicon.png + favicon.ico OK" -ForegroundColor Green

# ── Summary ──
Write-Host ""
Write-Host "====== Todos gerados! ======" -ForegroundColor Yellow
Write-Host "Corte: ${cropW}x${cropH} (margens removidas)" -ForegroundColor White
Write-Host ""
foreach ($s in $sizes) {
    $path = Join-Path -Path $OutputIcons -ChildPath $s.Name
    if (Test-Path -LiteralPath $path) {
        $f = Get-Item -LiteralPath $path
        $sizeStr = "$($s.Size)"; Write-Host "  $($s.Name.PadRight(20)) $($sizeStr.PadLeft(3))x$sizeStr  $([int]($f.Length/1KB)) KB" -ForegroundColor Gray
    }
}
