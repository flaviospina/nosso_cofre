<?php
// app/Services/TransactionPolicy.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

/**
 * Quem pode ver/editar o quê dentro do lar (§2.2):
 *  owner/admin: tudo; member: cria e edita os próprios (ou os de todos, se o lar permitir); viewer: só lê.
 *  Lançamento privado ("visível só para mim"): entra nos totais, mas descrição/categoria/observações ficam ocultas para os outros.
 */
final class TransactionPolicy
{
    public static function canCreate(): bool
    {
        return Auth::canWrite();
    }

    /** @param array<string,mixed> $tx */
    public static function canEdit(array $tx): bool
    {
        if (!Auth::canWrite() || self::isPrivateForMe($tx)) {
            return false;   // privado de outro membro: nem o responsável do lar edita (a descrição ficaria exposta)
        }
        if (Auth::canManage()) {
            return true;
        }
        $me = Auth::id();
        if ((int) $tx['created_by'] === $me || (int) ($tx['responsible_user_id'] ?? 0) === $me) {
            return true;
        }
        $household = Auth::household();
        return !empty($household['settings']['members_can_edit_others']);
    }

    /** @var array<int,list<int>> cache por requisição: lar → membros que desligaram "compartilhar com o lar" */
    private static array $nonSharing = [];

    /**
     * Membros do lar cujo consentimento "share_with_household" está revogado: todos os lançamentos deles
     * são tratados como "visível só para mim" pelos demais (LGPD, consentimento granular).
     * @return list<int>
     */
    public static function nonSharingUserIds(int $householdId): array
    {
        if (!isset(self::$nonSharing[$householdId])) {
            self::$nonSharing[$householdId] = array_map('intval', array_column(\App\Core\Database::select(
                "SELECT m.user_id FROM household_members m
                  WHERE m.household_id = ? AND m.left_at IS NULL AND EXISTS (
                        SELECT 1 FROM consents c WHERE c.user_id = m.user_id AND c.kind = 'share_with_household' AND c.granted = 0
                           AND c.id = (SELECT MAX(c2.id) FROM consents c2 WHERE c2.user_id = m.user_id AND c2.kind = 'share_with_household'))",
                [$householdId]
            ), 'user_id'));
        }
        return self::$nonSharing[$householdId];
    }

    /** Lançamento privado (ou de membro que não compartilha) de outra pessoa? @param array<string,mixed> $tx */
    public static function isPrivateForMe(array $tx, ?int $viewerId = null): bool
    {
        $viewer = $viewerId ?? Auth::id();
        if ((int) $tx['created_by'] === $viewer) {
            return false;
        }
        if ((int) ($tx['is_private'] ?? 0) === 1) {
            return true;
        }
        $householdId = (int) ($tx['household_id'] ?? Auth::householdId() ?? 0);
        return $householdId > 0 && in_array((int) $tx['created_by'], self::nonSharingUserIds($householdId), true);
    }

    /**
     * Condição SQL "visível para o usuário" (exclui privados e não compartilhados de outros membros).
     * @return array{0:string,1:list<mixed>} [sql, params]
     */
    public static function visibleSql(string $alias, int $viewerId, int $householdId): array
    {
        [$sql, $params] = self::hiddenSql($alias, $viewerId, $householdId);
        return ['NOT ' . $sql, $params];
    }

    /**
     * Condição SQL "oculto para o usuário" (privado ou de membro que não compartilha, criado por outra pessoa).
     * @return array{0:string,1:list<mixed>} [sql, params]
     */
    public static function hiddenSql(string $alias, int $viewerId, int $householdId): array
    {
        $ids = self::nonSharingUserIds($householdId);
        $sql = "({$alias}.created_by <> ? AND ({$alias}.is_private = 1" . ($ids !== [] ? " OR {$alias}.created_by IN (" . implode(',', $ids) . ')' : '') . '))';
        return [$sql, [$viewerId]];
    }

    /** Usuário "visualizador" atual: o logado ou, fora de uma sessão (cron), ninguém (0 = só vê o que não é privado). */
    public static function viewer(?int $viewerId = null): int
    {
        return $viewerId ?? (int) (Auth::id() ?? 0);
    }

    /** Mascara campos de lançamentos privados de outros membros. */
    /** @param array<string,mixed> $tx
     *  @return array<string,mixed> */
    public static function mask(array $tx, ?int $viewerId = null): array
    {
        if (!self::isPrivateForMe($tx, $viewerId)) {
            return $tx;
        }
        $tx['description'] = 'Lançamento privado';
        $tx['category_id'] = null;
        $tx['notes'] = null;
        $tx['tags'] = null;
        $tx['attachment_path'] = null;
        $tx['attachment_name'] = null;
        $tx['masked'] = true;
        return $tx;
    }
}
