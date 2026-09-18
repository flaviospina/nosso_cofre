<?php
// app/Models/Invitation.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Invitation extends Model
{
    protected string $table = 'invitations';
    protected array $fillable = ['email', 'token_hash', 'role', 'invited_by', 'expires_at', 'sent_count', 'accepted_at', 'accepted_user_id', 'revoked_at'];

    public const ROLES = ['admin', 'member', 'viewer'];

    /** Convites pendentes (não aceitos, não revogados, não expirados) do lar ativo. */
    /** @return array<int,array<string,mixed>> */
    public function pending(): array
    {
        return Database::select(
            'SELECT i.*, u.name AS invited_by_name FROM invitations i JOIN users u ON u.id = i.invited_by
              WHERE i.household_id = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?
              ORDER BY i.created_at DESC',
            [$this->householdId(), gmdate('Y-m-d H:i:s')]
        );
    }

    /** Busca um convite válido pelo token (sem escopo de lar: o convidado ainda não tem lar). */
    /** @return array<string,mixed>|null */
    public static function findValidByToken(string $token): ?array
    {
        return Database::selectOne(
            'SELECT i.*, h.name AS household_name, h.type AS household_type, u.name AS invited_by_name
               FROM invitations i
               JOIN households h ON h.id = i.household_id AND h.deleted_at IS NULL
               JOIN users u ON u.id = i.invited_by
              WHERE i.token_hash = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?',
            [hash('sha256', $token), gmdate('Y-m-d H:i:s')]
        );
    }

    /** Convites válidos endereçados a um e-mail (usados ao confirmar o e-mail de um novo usuário). */
    /** @return array<int,array<string,mixed>> */
    public static function pendingForEmail(string $email): array
    {
        return Database::select(
            'SELECT i.* FROM invitations i JOIN households h ON h.id = i.household_id AND h.deleted_at IS NULL
              WHERE i.email = ? AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ?',
            [mb_strtolower($email), gmdate('Y-m-d H:i:s')]
        );
    }
}
