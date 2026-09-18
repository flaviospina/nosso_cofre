<?php
// app/Services/InvitationService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Mailer;
use App\Models\Household;
use App\Models\Invitation;

/**
 * Convites para o lar familiar: link com token (7 dias), aceite por usuário existente ou novo.
 */
final class InvitationService
{
    public const TTL_DAYS = 7;

    /**
     * Cria (ou renova) o convite e envia o e-mail. Devolve mensagem de erro em pt-BR ou null se ok.
     */
    public static function invite(int $householdId, string $email, string $role, int $invitedBy): ?string
    {
        $email = mb_strtolower(trim($email));
        if (!in_array($role, Invitation::ROLES, true)) {
            return 'Papel inválido.';
        }
        $household = Household::findById($householdId);
        if ($household === null) {
            return 'Lar não encontrado.';
        }
        $already = Database::selectOne(
            'SELECT m.id FROM household_members m JOIN users u ON u.id = m.user_id WHERE m.household_id = ? AND u.email = ? AND m.left_at IS NULL',
            [$householdId, $email]
        );
        if ($already !== null) {
            return 'Esse e-mail já pertence a um membro do lar.';
        }
        $now = gmdate('Y-m-d H:i:s');
        // Revoga convites anteriores pendentes para o mesmo e-mail
        Database::execute('UPDATE invitations SET revoked_at = ? WHERE household_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$now, $householdId, $email]);

        $token = Crypto::randomUrlToken(32);
        $invitationId = Database::insert('invitations', [
            'household_id' => $householdId,
            'email'        => $email,
            'token_hash'   => Crypto::hashToken($token),
            'role'         => $role,
            'invited_by'   => $invitedBy,
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400),
            'sent_count'   => 1,
            'created_at'   => $now,
        ]);
        $inviter = Database::selectOne('SELECT name FROM users WHERE id = ?', [$invitedBy]);
        Mailer::send($email, null, 'Convite para o lar "' . $household['name'] . '" no Nosso Cofre', 'invitation', [
            'householdName' => $household['name'],
            'inviterName'   => $inviter['name'] ?? 'Um membro',
            'role'          => \App\Core\Auth::ROLE_LABELS[$role] ?? $role,
            'url'           => absolute_url('/convite/' . $token),
            'days'          => self::TTL_DAYS,
        ], 'transactional', null);
        AuditService::log('member.invited', 'invitation', $invitationId, null, ['email' => $email, 'role' => $role], $invitedBy, $householdId);
        return null;
    }

    /** Reenvia com token novo (o antigo deixa de valer). */
    public static function resend(int $householdId, int $invitationId, int $byUserId): ?string
    {
        $invitation = Database::selectOne('SELECT * FROM invitations WHERE id = ? AND household_id = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$invitationId, $householdId]);
        if ($invitation === null) {
            return 'Convite não encontrado.';
        }
        if ((int) $invitation['sent_count'] >= 5) {
            return 'Este convite já foi reenviado o máximo de vezes. Cancele e crie um novo.';
        }
        $error = self::invite($householdId, (string) $invitation['email'], (string) $invitation['role'], $byUserId);
        if ($error === null) {
            Database::execute('UPDATE invitations SET sent_count = ? WHERE household_id = ? AND email = ? AND accepted_at IS NULL AND revoked_at IS NULL', [(int) $invitation['sent_count'] + 1, $householdId, $invitation['email']]);
            AuditService::log('member.invite_resent', 'invitation', $invitationId, null, ['email' => $invitation['email']], $byUserId, $householdId);
        }
        return $error;
    }

    public static function revoke(int $householdId, int $invitationId, int $byUserId): bool
    {
        $changed = Database::execute('UPDATE invitations SET revoked_at = ? WHERE id = ? AND household_id = ? AND accepted_at IS NULL AND revoked_at IS NULL', [gmdate('Y-m-d H:i:s'), $invitationId, $householdId]);
        if ($changed > 0) {
            AuditService::log('member.invite_revoked', 'invitation', $invitationId, null, null, $byUserId, $householdId);
        }
        return $changed > 0;
    }

    /**
     * Aceita o convite para o usuário informado: cria (ou reativa) a associação ao lar.
     * @param array<string,mixed> $invitation linha de invitations
     */
    public static function accept(array $invitation, int $userId): bool
    {
        $householdId = (int) $invitation['household_id'];
        $now = gmdate('Y-m-d H:i:s');
        Database::transaction(static function () use ($invitation, $userId, $householdId, $now): void {
            $existing = Database::selectOne('SELECT * FROM household_members WHERE household_id = ? AND user_id = ?', [$householdId, $userId]);
            if ($existing === null) {
                Database::insert('household_members', [
                    'household_id' => $householdId,
                    'user_id'      => $userId,
                    'role'         => (string) $invitation['role'],
                    'invited_by'   => (int) $invitation['invited_by'],
                    'joined_at'    => $now,
                    'created_at'   => $now,
                ]);
            } elseif ($existing['left_at'] !== null) {
                Database::execute('UPDATE household_members SET role = ?, invited_by = ?, joined_at = ?, left_at = NULL, updated_at = ? WHERE id = ?', [
                    (string) $invitation['role'], (int) $invitation['invited_by'], $now, $now, (int) $existing['id'],
                ]);
            }
            Database::execute('UPDATE invitations SET accepted_at = ?, accepted_user_id = ? WHERE id = ?', [$now, $userId, (int) $invitation['id']]);
        });
        AuditService::log('member.joined', 'household_member', null, null, ['user_id' => $userId, 'role' => $invitation['role']], $userId, $householdId);
        return true;
    }
}
