<?php
// app/Controllers/AdminBackupController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\BackupService;

/** Área do controlador → Backups (/admin/backups): lista, gera agora e baixa os arquivos criptografados. */
final class AdminBackupController extends Controller
{
    public function index(): Response
    {
        return $this->view('admin/backups', [
            'title'     => 'Backups',
            'backups'   => BackupService::list(),
            'configured'=> strlen((string) Config::get('backup.key', '')) >= 32,
            'retention' => (int) Config::get('backup.retention_days', 30),
        ]);
    }

    public function create(): Response
    {
        try {
            $path = BackupService::create();
            AuditService::log('backup.created', 'backup', 0, null, ['file' => basename($path)]);
            $this->flash('success', 'Backup gerado: ' . basename($path));
        } catch (\Throwable $e) {
            $this->flash('danger', 'Falha ao gerar o backup: ' . $e->getMessage());
        }
        return $this->redirectRoute('admin.backups');
    }

    public function download(string $name): Response
    {
        if (!preg_match('/^nosso-cofre-\d{4}-\d{2}-\d{2}-\d{4}\.sql\.gz\.enc$/', $name)) {
            throw new HttpException(404);
        }
        $path = (string) Config::get('paths.backups') . '/' . $name;
        AuditService::log('backup.downloaded', 'backup', 0, null, ['file' => $name]);
        return Response::file($path, $name, 'application/octet-stream');
    }
}
