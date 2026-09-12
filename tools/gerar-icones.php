<?php
// tools/gerar-icones.php — gera os PNGs da PWA (192, 512, maskable, apple-touch, favicon-32, badge-96) com GD.
// Uso (desenvolvimento): php tools/gerar-icones.php  → grava em public/assets/img/. Os PNGs já vão prontos no repositório.
declare(strict_types=1);

$out = dirname(__DIR__) . '/public/assets/img';
if (!is_dir($out)) {
    mkdir($out, 0755, true);
}

function drawIcon(int $size, bool $maskable = false, bool $monochrome = false): GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imagesavealpha($img, true);
    imagealphablending($img, false);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);
    imageantialias($img, true);

    $brand = imagecolorallocate($img, 15, 118, 110);
    $white = imagecolorallocate($img, 255, 255, 255);

    // Fundo: quadrado arredondado (ou quadrado cheio para maskable)
    $radius = $maskable ? 0 : (int) round($size * 0.22);
    roundedRect($img, 0, 0, $size - 1, $size - 1, $radius, $monochrome ? $white : $brand);

    // Zona segura maskable = 80% central
    $scale = $maskable ? 0.72 : 1.0;
    $u = $size / 64 * $scale;
    $o = ($size - 64 * $u) / 2;
    $x = static fn(float $v): int => (int) round($o + $v * $u);
    $w = static fn(float $v): int => (int) round($v * $u);

    $fg = $monochrome ? $brand : $white;
    $bg = $monochrome ? $white : $brand;

    roundedRect($img, $x(12), $x(12), $x(52), $x(52), $w(6), $fg);
    roundedRect($img, $x(17), $x(17), $x(47), $x(47), $w(4), $bg);
    imagesetthickness($img, max(1, $w(3)));
    imagearc($img, $x(32), $x(32), $w(16), $w(16), 0, 360, $fg);
    imagefilledellipse($img, $x(32), $x(32), $w(5), $w(5), $fg);
    foreach ([[31, 20, 2, 5], [31, 39, 2, 5], [20, 31, 5, 2], [39, 31, 5, 2], [14, 52, 6, 4], [44, 52, 6, 4]] as [$rx, $ry, $rw, $rh]) {
        imagefilledrectangle($img, $x($rx), $x($ry), $x($rx + $rw), $x($ry + $rh), $fg);
    }
    return $img;
}

function roundedRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
{
    if ($r <= 0) {
        imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
        return;
    }
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $color);
    }
}

$files = [
    'icon-192.png'          => drawIcon(192),
    'icon-512.png'          => drawIcon(512),
    'icon-maskable-512.png' => drawIcon(512, true),
    'apple-touch-icon.png'  => drawIcon(180),
    'favicon-32.png'        => drawIcon(32),
    'badge-96.png'          => drawIcon(96, false, true),
];
foreach ($files as $name => $img) {
    imagepng($img, $out . '/' . $name, 9);
    imagedestroy($img);
    echo "gerado: {$name}\n";
}
