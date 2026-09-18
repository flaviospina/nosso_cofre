<?php
// app/Core/DatabaseSessionHandler.php
declare(strict_types=1);

namespace App\Core;

use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

/**
 * Sessões na tabela `sessions`: permite a tela "Sessões ativas" (aparelho, IP, última atividade)
 * e o botão "encerrar todas as outras". O conteúdo (payload) é o mesmo serializado do PHP.
 */
final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $row = Database::selectOne('SELECT payload FROM sessions WHERE id = ?', [$id]);
        if ($row === null || $row['payload'] === null) {
            return '';
        }
        return (string) $row['payload'];
    }

    public function write(string $id, string $data): bool
    {
        $request = App::request();
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $now = gmdate('Y-m-d H:i:s');
        Database::execute(
            'INSERT INTO sessions (id, user_id, payload, ip, user_agent, device_label, last_activity, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), payload = VALUES(payload), ip = VALUES(ip),
                                     user_agent = VALUES(user_agent), device_label = VALUES(device_label), last_activity = VALUES(last_activity)',
            [
                $id,
                $userId,
                $data,
                $request?->ip() ?? '0.0.0.0',
                $request?->userAgent() ?? '',
                $request?->deviceLabel() ?? '',
                $now,
                $now,
            ]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::execute('DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Database::execute('DELETE FROM sessions WHERE last_activity < ?', [gmdate('Y-m-d H:i:s', time() - $max_lifetime)]);
    }

    public function validateId(string $id): bool
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM sessions WHERE id = ?', [$id]) > 0;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        Database::execute('UPDATE sessions SET last_activity = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $id]);
        return true;
    }

    // --- Utilidades para a tela de sessões ---

    /** @return array<int,array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::select('SELECT id, ip, user_agent, device_label, last_activity, created_at FROM sessions WHERE user_id = ? ORDER BY last_activity DESC', [$userId]);
    }

    public static function destroyOthers(int $userId, string $currentId): int
    {
        return Database::execute('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$userId, $currentId]);
    }

    public static function destroyAllForUser(int $userId): int
    {
        return Database::execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }
}
