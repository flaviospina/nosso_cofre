<?php
// app/Services/HouseholdService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\Account;
use App\Models\Household;
use App\Models\HouseholdMember;

/**
 * Criação e configuração inicial do lar (individual ou familiar), conversão e ajustes.
 */
final class HouseholdService
{
    /** Contas/cartões sugeridos no assistente de configuração. */
    public const SUGGESTED_ACCOUNTS = [
        'checking'    => ['name' => 'Conta corrente', 'type' => 'checking'],
        'credit_card' => ['name' => 'Cartão de crédito', 'type' => 'credit_card'],
        'cash'        => ['name' => 'Dinheiro', 'type' => 'cash'],
        'savings'     => ['name' => 'Reserva de emergência', 'type' => 'savings'],
    ];

    /** Cria o lar e coloca o usuário como responsável. Devolve o id do lar. */
    public static function create(int $userId, string $type, string $name): int
    {
        $type = $type === 'family' ? 'family' : 'individual';
        $householdId = Database::transaction(static function () use ($userId, $type, $name): int {
            $id = (new Household())->create([
                'name'                   => $name,
                'type'                   => $type,
                'currency'               => 'BRL',
                'fiscal_month_start_day' => 1,
                'settings'               => Household::DEFAULT_SETTINGS,
                'owner_user_id'          => $userId,
                'status'                 => 'active',
            ]);
            Database::insert('household_members', [
                'household_id' => $id,
                'user_id'      => $userId,
                'role'         => 'owner',
                'joined_at'    => gmdate('Y-m-d H:i:s'),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ]);
            return $id;
        });
        AuditService::log('household.created', 'household', $householdId, null, ['type' => $type, 'name' => $name], $userId, $householdId);
        return $householdId;
    }

    /** Conta individual → familiar, sem perder dados. */
    public static function convertToFamily(int $householdId, string $name): void
    {
        $household = Household::findById($householdId);
        if ($household === null || $household['type'] === 'family') {
            return;
        }
        (new Household())->update($householdId, ['type' => 'family', 'name' => $name]);
        AuditService::log('household.converted', 'household', $householdId, ['type' => 'individual', 'name' => $household['name']], ['type' => 'family', 'name' => $name]);
        Auth::refresh();
    }

    /**
     * Assistente rápido (passo 2 do onboarding).
     * @param array{currency:string,fiscal_month_start_day:int,accounts:list<string>,custom_accounts:list<string>,income:string|null} $data
     */
    public static function applyQuickSetup(int $householdId, int $userId, array $data): void
    {
        Database::transaction(static function () use ($householdId, $userId, $data): void {
            (new Household())->update($householdId, [
                'currency'               => $data['currency'],
                'fiscal_month_start_day' => $data['fiscal_month_start_day'],
            ]);
            $accounts = Account::forHousehold($householdId);
            $existing = $accounts->count();
            $order = $existing;
            foreach ($data['accounts'] as $key) {
                if (!isset(self::SUGGESTED_ACCOUNTS[$key])) {
                    continue;
                }
                $suggested = self::SUGGESTED_ACCOUNTS[$key];
                $accountId = $accounts->create([
                    'name'          => $suggested['name'],
                    'type'          => $suggested['type'],
                    'owner_user_id' => $key === 'credit_card' || $key === 'checking' ? $userId : null,
                    'icon'          => Account::ICONS[$suggested['type']],
                    'closing_day'   => $key === 'credit_card' ? 25 : null,
                    'due_day'       => $key === 'credit_card' ? 5 : null,
                    'sort_order'    => ++$order,
                ]);
                AuditService::log('account.created', 'account', $accountId, null, ['name' => $suggested['name'], 'type' => $suggested['type']], $userId, $householdId);
            }
            foreach ($data['custom_accounts'] as $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $accountId = $accounts->create([
                    'name'       => mb_substr($name, 0, 80),
                    'type'       => 'checking',
                    'icon'       => Account::ICONS['checking'],
                    'sort_order' => ++$order,
                ]);
                AuditService::log('account.created', 'account', $accountId, null, ['name' => $name, 'type' => 'checking'], $userId, $householdId);
            }
            if ($data['income'] !== null) {
                Database::execute('UPDATE household_members SET estimated_income = ? WHERE household_id = ? AND user_id = ?', [$data['income'], $householdId, $userId]);
            }
        });
        AuditService::log('household.updated', 'household', $householdId, null, ['currency' => $data['currency'], 'fiscal_month_start_day' => $data['fiscal_month_start_day']], $userId, $householdId);
        Auth::refresh();
    }

    /** Preferências do lar (tela Família). */
    /** @param array<string,mixed> $settings */
    public static function updateSettings(int $householdId, string $name, array $settings): void
    {
        $household = Household::findById($householdId);
        if ($household === null) {
            return;
        }
        $merged = array_merge($household['settings'], $settings);
        (new Household())->update($householdId, ['name' => $name, 'settings' => $merged]);
        AuditService::log('household.updated', 'household', $householdId, ['name' => $household['name'], 'settings' => $household['settings']], ['name' => $name, 'settings' => $merged]);
        Auth::refresh();
    }

    public static function changeRole(int $householdId, int $memberId, string $role, int $byUserId): bool
    {
        if (!in_array($role, ['admin', 'member', 'viewer'], true)) {
            return false;
        }
        $member = Database::selectOne('SELECT * FROM household_members WHERE id = ? AND household_id = ? AND left_at IS NULL', [$memberId, $householdId]);
        if ($member === null || $member['role'] === 'owner') {
            return false;
        }
        Database::execute('UPDATE household_members SET role = ?, updated_at = ? WHERE id = ?', [$role, gmdate('Y-m-d H:i:s'), $memberId]);
        AuditService::log('member.role_changed', 'household_member', $memberId, ['role' => $member['role']], ['role' => $role, 'user_id' => $member['user_id']], $byUserId, $householdId);
        return true;
    }

    /** Remove um membro (marca left_at). O responsável nunca é removido por aqui. */
    public static function removeMember(int $householdId, int $memberId, int $byUserId, bool $selfLeave = false): bool
    {
        $member = Database::selectOne('SELECT * FROM household_members WHERE id = ? AND household_id = ? AND left_at IS NULL', [$memberId, $householdId]);
        if ($member === null || $member['role'] === 'owner') {
            return false;
        }
        Database::execute('UPDATE household_members SET left_at = ?, updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $memberId]);
        AuditService::log($selfLeave ? 'member.left' : 'member.removed', 'household_member', $memberId, ['role' => $member['role'], 'user_id' => $member['user_id']], null, $byUserId, $householdId);
        return true;
    }

    /** Lares ativos de um usuário (para o seletor, quando houver mais de um). */
    /** @return array<int,array<string,mixed>> */
    public static function householdsOf(int $userId): array
    {
        return Database::select(
            'SELECT h.id, h.name, h.type, m.role FROM household_members m JOIN households h ON h.id = m.household_id AND h.deleted_at IS NULL
              WHERE m.user_id = ? AND m.left_at IS NULL ORDER BY m.joined_at ASC',
            [$userId]
        );
    }
}
