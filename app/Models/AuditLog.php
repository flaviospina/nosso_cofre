<?php
// app/Models/AuditLog.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class AuditLog extends Model
{
    protected string $table = 'audit_logs';
    protected bool $householdScoped = false;
    protected bool $timestamps = false;
    protected array $json = ['before_data', 'after_data'];

    /** "Minha atividade": ações do próprio usuário, mais recentes primeiro. */
    /** @return array<int,array<string,mixed>> */
    public function forUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $rows = Database::select(
            'SELECT * FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            [$userId]
        );
        return array_map(fn(array $r): array => $this->castRow($r), $rows);
    }

    public function countForUser(int $userId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?', [$userId]);
    }
}
