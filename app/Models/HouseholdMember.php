<?php
// app/Models/HouseholdMember.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Vínculo usuário ↔ lar com papel. Escopado pelo lar ativo nas operações normais;
 * as consultas estáticas abaixo são usadas antes de existir lar ativo (login/onboarding).
 */
final class HouseholdMember extends Model
{
    protected string $table = 'household_members';
    protected array $fillable = ['user_id', 'role', 'estimated_income', 'invited_by', 'joined_at', 'left_at'];

    /** @return array<string,mixed>|null */
    public static function findActive(int $householdId, int $userId): ?array
    {
        return Database::selectOne(
            'SELECT m.*, u.name AS user_name, u.color AS user_color
               FROM household_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.household_id = ? AND m.user_id = ? AND m.left_at IS NULL',
            [$householdId, $userId]
        );
    }

    /** Primeiro lar ativo do usuário (um lar por usuário nesta versão; a estrutura já permite vários). */
    /** @return array<string,mixed>|null */
    public static function firstActiveForUser(int $userId): ?array
    {
        return Database::selectOne(
            'SELECT m.* FROM household_members m
               JOIN households h ON h.id = m.household_id AND h.deleted_at IS NULL
              WHERE m.user_id = ? AND m.left_at IS NULL
              ORDER BY m.joined_at ASC, m.id ASC LIMIT 1',
            [$userId]
        );
    }

    /** Membros ativos do lar atual, com nome e cor. */
    /** @return array<int,array<string,mixed>> */
    public function activeMembers(): array
    {
        return Database::select(
            'SELECT m.id, m.user_id, m.role, m.estimated_income, m.joined_at, u.name, u.color, u.status
               FROM household_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.household_id = ? AND m.left_at IS NULL
              ORDER BY FIELD(m.role, "owner", "admin", "member", "viewer"), u.name',
            [$this->householdId()]
        );
    }
}
