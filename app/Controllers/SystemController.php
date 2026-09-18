<?php
// app/Controllers/SystemController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;

/**
 * Telas de sistema: saúde da instalação, gerador de chaves para o .env, página offline da PWA e cron por URL.
 */
final class SystemController extends Controller
{
    /** /saude — verificações da instalação sem expor segredos. Detalhes técnicos só com APP_DEBUG. */
    public function health(): Response
    {
        $checks = [];
        $checks['php'] = [
            'label' => 'PHP 8.2 ou superior',
            'ok'    => version_compare(PHP_VERSION, '8.2.0', '>='),
            'info'  => 'Versão ' . PHP_VERSION,
        ];
        foreach (['pdo_mysql' => 'PDO MySQL', 'openssl' => 'OpenSSL', 'mbstring' => 'mbstring', 'json' => 'JSON', 'fileinfo' => 'fileinfo (uploads)', 'gd' => 'GD (imagens)', 'zip' => 'Zip (exportações)', 'curl' => 'cURL (Web Push)'] as $ext => $label) {
            $checks['ext_' . $ext] = ['label' => 'Extensão ' . $label, 'ok' => extension_loaded($ext), 'info' => ''];
        }
        $checks['openssl_ec'] = [
            'label' => 'OpenSSL com curva P-256 (Web Push/VAPID)',
            'ok'    => function_exists('openssl_pkey_derive') && function_exists('hash_hkdf') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true),
            'info'  => 'Padrão no PHP 8 do cPanel; sem isso o push não funciona (o e-mail continua)',
        ];
        $checks['vapid'] = [
            'label' => 'Chaves VAPID configuradas (Web Push)',
            'ok'    => (string) Config::get('vapid.public', '') !== '' && (string) Config::get('vapid.private', '') !== '',
            'info'  => 'Gere em /sistema/vapid?token=SEU_CRON_TOKEN e cole no .env',
        ];
        $checks['argon2'] = [
            'label' => 'Argon2id disponível',
            'ok'    => defined('PASSWORD_ARGON2ID'),
            'info'  => defined('PASSWORD_ARGON2ID') ? '' : 'Sem Argon2id o sistema usa bcrypt (custo 12), que também é seguro',
        ];
        $checks['env'] = [
            'label' => 'Arquivo .env presente',
            'ok'    => is_file(APP_ROOT . '/.env'),
            'info'  => '',
        ];
        $checks['app_key'] = [
            'label' => 'APP_KEY configurado (32 bytes em base64)',
            'ok'    => $this->keyIsValid((string) Config::get('app.key', '')),
            'info'  => 'Gere em /instalar',
        ];
        $checks['app_url'] = [
            'label' => 'APP_URL configurado com https',
            'ok'    => str_starts_with((string) Config::get('app.url', ''), 'https://'),
            'info'  => '',
        ];
        $expectedUrl = ($this->request->isSecure() ? 'https://' : 'http://') . (string) $this->request->header('Host', '')
            . Request::basePathFromServer($this->request->serverAll());
        $checks['app_url_match'] = [
            'label' => 'APP_URL corresponde à URL por onde o app é acessado',
            'ok'    => !$this->request->appUrlMismatch(),
            'info'  => 'Pelo acesso atual, o valor esperado é APP_URL=' . $expectedUrl,
        ];
        $checks['db'] = [
            'label' => 'Conexão com o banco de dados',
            'ok'    => Database::isConfigured() && Database::ping(),
            'info'  => Database::isConfigured() && Database::ping() ? 'Servidor ' . Database::serverVersion() : 'Confira DB_NAME, DB_USER e DB_PASS',
        ];
        $schemaOk = false;
        if ($checks['db']['ok']) {
            try {
                $schemaOk = Database::scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'settings'") > 0;
            } catch (\Throwable) {
                $schemaOk = false;
            }
        }
        $checks['schema'] = [
            'label' => 'Tabelas criadas (sql/schema.sql importado)',
            'ok'    => $schemaOk,
            'info'  => $schemaOk ? '' : 'Importe sql/schema.sql no phpMyAdmin',
        ];
        if ($schemaOk) {
            $current = Migrator::currentVersion();
            $target = Migrator::targetVersion();
            $checks['schema_version'] = [
                'label' => "Banco na versão {$target} do esquema",
                'ok'    => $current >= $target,
                'info'  => $current >= $target ? '' : "Banco na versão {$current}: aplique as migrações em /sistema/migrar?token=SEU_CRON_TOKEN",
            ];
        }
        foreach (['logs', 'cache', 'uploads', 'exports', 'backups', 'sessions'] as $dir) {
            $path = (string) Config::get('paths.' . $dir);
            $checks['dir_' . $dir] = [
                'label' => "Pasta storage/{$dir} gravável",
                'ok'    => is_dir($path) && is_writable($path),
                'info'  => '',
            ];
        }
        $checks['storage_protected'] = [
            'label' => 'storage/ protegida por .htaccess',
            'ok'    => is_file(APP_ROOT . '/storage/.htaccess'),
            'info'  => '',
        ];
        $checks['mail'] = [
            'label' => 'SMTP configurado',
            'ok'    => (string) Config::get('mail.user', '') !== '' && (string) Config::get('mail.pass', '') !== '',
            'info'  => 'Usado a partir da fase 2 (confirmação de e-mail)',
        ];
        $checks['cron_token'] = [
            'label' => 'CRON_TOKEN configurado',
            'ok'    => strlen((string) Config::get('cron.token', '')) >= 32,
            'info'  => 'Gere em /instalar',
        ];
        $checks['backup_key'] = [
            'label' => 'BACKUP_KEY configurado',
            'ok'    => $this->keyIsValid((string) Config::get('backup.key', '')),
            'info'  => 'Gere em /instalar (usado pelo backup criptografado)',
        ];

        $allOk = array_reduce($checks, static fn(bool $carry, array $c): bool => $carry && $c['ok'], true);

        if ($this->request->wantsJson()) {
            return $this->json($allOk, array_map(static fn(array $c): bool => $c['ok'], $checks), $allOk ? 'Tudo certo.' : 'Há itens pendentes.', $allOk ? 200 : 503);
        }
        return $this->view('system/health', [
            'title'  => 'Saúde da instalação',
            'checks' => $checks,
            'allOk'  => $allOk,
            'debug'  => (bool) Config::get('app.debug') || \App\Core\Middleware\AdminMiddleware::isAdmin()
                || (strlen((string) Config::get('cron.token', '')) >= 32 && hash_equals((string) Config::get('cron.token', ''), (string) $this->request->query('token', ''))),
        ], 'layouts/base', $allOk ? 200 : 503);
    }

    /**
     * /instalar — gera valores aleatórios para o .env (sem terminal).
     * Só funciona enquanto APP_KEY estiver vazio: depois disso a tela some (404) para não gerar chaves à toa.
     */
    public function install(): Response
    {
        if ($this->keyIsValid((string) Config::get('app.key', ''))) {
            throw new HttpException(404);
        }
        Logger::info('Tela /instalar acessada (APP_KEY ainda vazio)', ['ip' => $this->request->ip()]);
        return $this->view('system/install', [
            'title'     => 'Instalação — chaves do .env',
            'appKey'    => Crypto::generateKey(),
            'backupKey' => Crypto::generateKey(),
            'cronToken' => Crypto::randomToken(32),
        ]);
    }

    /** /sistema/vapid?token=... — gera um par de chaves VAPID para colar no .env (sem terminal). */
    public function vapid(): Response
    {
        $configured = (string) Config::get('vapid.public', '') !== '' && (string) Config::get('vapid.private', '') !== '';
        $keys = \App\Core\WebPush::generateVapidKeys();
        Logger::info('Tela /sistema/vapid acessada', ['ip' => $this->request->ip(), 'configured' => $configured]);
        return $this->view('system/vapid', [
            'title'      => 'Chaves VAPID (Web Push)',
            'keys'       => $keys,
            'configured' => $configured,
            'current'    => (string) Config::get('vapid.public', ''),
            'subject'    => (string) Config::get('vapid.subject', '') ?: 'mailto:' . Config::get('mail.from_address', 'cofre@exemplo.com'),
        ]);
    }

    /** /sistema/migrar?token=... — lista e aplica migrações pendentes (sem terminal). */
    public function migrate(): Response
    {
        $pending = Migrator::pending();
        if ($this->request->isMethod('POST')) {
            $applied = Migrator::migrate();
            return $this->view('system/migrate', [
                'title'   => 'Atualização do banco',
                'pending' => Migrator::pending(),
                'applied' => $applied,
                'current' => Migrator::currentVersion(),
                'target'  => Migrator::targetVersion(),
                'token'   => (string) $this->request->query('token', ''),
            ]);
        }
        return $this->view('system/migrate', [
            'title'   => 'Atualização do banco',
            'pending' => $pending,
            'applied' => null,
            'current' => Migrator::currentVersion(),
            'target'  => Migrator::targetVersion(),
            'token'   => (string) $this->request->query('token', ''),
        ]);
    }

    /** Página mostrada pelo service worker quando não há conexão. */
    public function offline(): Response
    {
        // O service worker guarda esta página em cache: ela não pode carregar nome, avisos ou token CSRF do usuário
        return $this->view('system/offline', ['title' => 'Sem conexão', 'guestLayout' => true], 'layouts/base');
    }

    /** /cron/run?token=... — mesmo runner do cron/run.php, para o cron do cPanel por URL. */
    public function cron(): Response
    {
        $runner = APP_ROOT . '/cron/run.php';
        if (!is_file($runner)) {
            throw new HttpException(503, 'Runner do cron não encontrado.');
        }
        ob_start();
        $result = (static function () use ($runner) {
            return require $runner;
        })();
        $output = (string) ob_get_clean();
        return Response::text($output !== '' ? $output : (is_string($result) ? $result : 'ok'));
    }

    private function keyIsValid(string $encoded): bool
    {
        if ($encoded === '') {
            return false;
        }
        $raw = base64_decode($encoded, true);
        return $raw !== false && strlen($raw) === 32;
    }
}
