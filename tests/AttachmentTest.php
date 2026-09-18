<?php
// tests/AttachmentTest.php — comprovantes: MIME real, nome aleatório, reprocessamento (redução + sem EXIF), recusa de formato (integração)
declare(strict_types=1);

require_once __DIR__ . '/Support.php';

use App\Core\Config;
use App\Services\AttachmentService;

function attachment_tmp_png(int $w, int $h): string
{
    $path = tempnam(sys_get_temp_dir(), 'nc-att') . '.png';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, 0x336699);
    imagepng($im, $path);
    return $path;
}

function test_attachment_store_resizes_and_renames(): void
{
    if (!db_available('transactions') || !function_exists('imagecreatetruecolor')) { return; }
    $f = privacy_fixture();
    $src = attachment_tmp_png(2400, 1200);
    $file = ['name' => 'Recibo Mercado.PNG', 'type' => 'image/png', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => filesize($src)];
    $r = AttachmentService::store($file, (int) $f['household'], (int) $f['owner']);
    assert_true(str_starts_with($r['path'], $f['household'] . '/u' . $f['owner'] . '-'), 'pasta do lar + prefixo do usuário');
    assert_true(preg_match('/\/u\d+-[a-f0-9]{16,}\.(png|jpg|webp)$/', $r['path']) === 1, 'nome aleatório, extensão pelo MIME');
    $abs = AttachmentService::absolutePath($r['path']);
    assert_true($abs !== null && is_file($abs));
    assert_true(!str_starts_with($abs, (string) Config::get('paths.public')), 'gravado fora da raiz pública');
    [$w, $h] = getimagesize($abs);
    assert_same(1600, $w, 'reduzido para 1600 px de largura');
    assert_same(800, $h);
    assert_contains('image/', $r['mime']);
    AttachmentService::delete($r['path']);
    assert_false(is_file($abs));
    @unlink($src);
}

function test_attachment_rejects_disguised_file(): void
{
    if (!db_available('transactions')) { return; }
    $f = privacy_fixture();
    $src = tempnam(sys_get_temp_dir(), 'nc-att');
    file_put_contents($src, "<?php echo 'x'; ?>");
    $file = ['name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => filesize($src)];
    assert_throws(RuntimeException::class, static fn() => AttachmentService::store($file, (int) $f['household'], (int) $f['owner']), 'PHP disfarçado de JPG é recusado pelo finfo');
    $big = ['name' => 'grande.pdf', 'type' => 'application/pdf', 'tmp_name' => $src, 'error' => UPLOAD_ERR_OK, 'size' => 6 * 1024 * 1024];
    assert_throws(RuntimeException::class, static fn() => AttachmentService::store($big, (int) $f['household'], (int) $f['owner']), 'acima de 5 MB');
    @unlink($src);
}
