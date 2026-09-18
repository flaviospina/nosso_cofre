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

    /** @param array<string,mixed> $tx */
    public static function isPrivateForMe(array $tx): bool
    {
        return (int) ($tx['is_private'] ?? 0) === 1 && (int) $tx['created_by'] !== Auth::id();
    }

    /** Mascara campos de lançamentos privados de outros membros. */
    /** @param array<string,mixed> $tx
     *  @return array<string,mixed> */
    public static function mask(array $tx): array
    {
        if (!self::isPrivateForMe($tx)) {
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
