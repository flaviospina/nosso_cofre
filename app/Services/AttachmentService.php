<?php
// app/Services/AttachmentService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use RuntimeException;

/**
 * Comprovantes: MIME real via finfo, limite de tamanho, nome aleatório, gravação fora da pasta pública,
 * imagens reprocessadas pelo GD (o que remove EXIF/GPS) e reduzidas a 1600 px.
 */
final class AttachmentService
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    /**
     * @param array<string,mixed> $file item de $_FILES
     * @return array{path:string,name:string,mime:string} caminho relativo a storage/uploads
     */
    public static function store(array $file, int $householdId, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(match ((int) ($file['error'] ?? 0)) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo é maior que o permitido.',
                default => 'Falha no envio do arquivo.',
            });
        }
        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('O comprovante deve ter no máximo 5 MB.');
        }
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp) && !is_file($tmp)) {
            throw new RuntimeException('Arquivo inválido.');
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw new RuntimeException('Formato não aceito. Envie JPG, PNG, WEBP ou PDF.');
        }
        $dir = (string) Config::get('paths.uploads') . '/' . $householdId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível gravar o comprovante.');
        }
        $ext = self::ALLOWED[$mime];
        $name = 'u' . $userId . '-' . Crypto::randomToken(16) . '.' . $ext;
        $dest = $dir . '/' . $name;

        if ($mime === 'application/pdf') {
            if (!copy($tmp, $dest)) {
                throw new RuntimeException('Não foi possível gravar o comprovante.');
            }
        } else {
            self::reencodeImage($tmp, $dest, $mime);
        }
        @chmod($dest, 0640);
        return ['path' => $householdId . '/' . $name, 'name' => mb_substr((string) ($file['name'] ?? 'comprovante'), 0, 190), 'mime' => $mime];
    }

    /** Recria a imagem pixel a pixel (sem metadados) e limita a 1600 px no maior lado. */
    private static function reencodeImage(string $src, string $dest, string $mime): void
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/webp' => @imagecreatefromwebp($src),
            default      => false,
        };
        if ($image === false) {
            throw new RuntimeException('A imagem está corrompida ou não pôde ser lida.');
        }
        // Orientação EXIF: aplica a rotação antes de descartar os metadados
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($src);
            $orientation = (int) ($exif['Orientation'] ?? 1);
            $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($angle !== 0) {
                $rotated = imagerotate($image, $angle, 0);
                if ($rotated !== false) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $max = 1600;
        if ($w > $max || $h > $max) {
            $scale = $max / max($w, $h);
            $resized = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            if ($mime !== 'image/jpeg') {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, (int) round($w * $scale), (int) round($h * $scale), $w, $h);
            imagedestroy($image);
            $image = $resized;
        }
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, $dest, 85),
            'image/png'  => imagepng($image, $dest, 6),
            'image/webp' => imagewebp($image, $dest, 85),
            default      => false,
        };
        imagedestroy($image);
        if (!$ok) {
            throw new RuntimeException('Não foi possível gravar a imagem.');
        }
    }

    public static function delete(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '' || str_contains($relativePath, '..')) {
            return;
        }
        $file = (string) Config::get('paths.uploads') . '/' . $relativePath;
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function absolutePath(string $relativePath): ?string
    {
        if (str_contains($relativePath, '..')) {
            return null;
        }
        $file = (string) Config::get('paths.uploads') . '/' . $relativePath;
        return is_file($file) ? $file : null;
    }
}
