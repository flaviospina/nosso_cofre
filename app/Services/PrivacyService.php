<?php
// app/Services/PrivacyService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\DatabaseSessionHandler;
use App\Core\Logger;
use App\Core\Mailer;
use App\Models\Consent;
use App\Models\Household;
use App\Models\User;

/**
 * Direitos do titular (art. 18 da LGPD) como funcionalidade: consentimentos, acesso, anonimização,
 * exclusão de conta e de lar com carência, saída do lar.
 */
final class PrivacyService
{
    /** Consentimentos que o usuário controla na tela (rótulo, descrição, base legal, revogável). */
    public const CONSENTS = [
        'transactional_email'  => ['label' => 'E-mails necessários ao serviço', 'help' => 'Confirmação de e-mail, recuperação de senha, convites e avisos de segurança.', 'basis' => 'Execução de contrato', 'revocable' => false],
        'digest_email'         => ['label' => 'Resumos e alertas por e-mail', 'help' => 'Contas a vencer, orçamento, resumo periódico. Detalhes em Notificações.', 'basis' => 'Consentimento', 'revocable' => true],
        'push'                 => ['label' => 'Notificações push', 'help' => 'Avisos no celular/navegador, aparelho por aparelho.', 'basis' => 'Consentimento', 'revocable' => true],
        'share_with_household' => ['label' => 'Compartilhar meus lançamentos com o lar', 'help' => 'Se desligado, todos os seus lançamentos passam a ser tratados como "visível só para mim".', 'basis' => 'Consentimento', 'revocable' => true],
    ];

    /** @return array<string,bool> */
    public static function consentState(int $userId): array
    {
        $current = Consent::currentFor($userId);
        $state = [];
        foreach (self::CONSENTS as $kind => $meta) {
            if (isset($current[$kind])) {
                $state[$kind] = (int) $current[$kind]['granted'] === 1;
            } else {
                // Padrão: só o necessário ao serviço é considerado concedido; compartilhar com o lar é o comportamento base
                $state[$kind] = in_array($kind, ['transactional_email', 'share_with_household'], true);
            }
        }
        return $state;
    }

    public static function setConsent(int $userId, string $kind, bool $granted): bool
    {
        if (!isset(self::CONSENTS[$kind]) || (!self::CONSENTS[$kind]['revocable'] && !$granted)) {
            return false;
        }
        $state = self::consentState($userId);
        if ($state[$kind] === $granted) {
            return true;
        }
        Consent::record($userId, $kind, $granted, null);
        AuditService::log($granted ? 'consent.granted' : 'consent.revoked', 'consent', null, null, ['kind' => $kind], $userId);
        if ($kind === 'push' && !$granted) {
            Database::execute('UPDATE push_subscriptions SET disabled_at = ? WHERE user_id = ? AND disabled_at IS NULL', [gmdate('Y-m-d H:i:s'), $userId]);
        }
        self::syncNotificationChannels($userId);
        return true;
    }

    /** "Desligar todos os avisos" em um clique. */
    public static function revokeAllNotifications(int $userId): void
    {
        self::setConsent($userId, 'digest_email', false);
        self::setConsent($userId, 'push', false);
    }

    private static function syncNotificationChannels(int $userId): void
    {
        $state = self::consentState($userId);
        $row = Database::selectOne('SELECT settings FROM notification_settings WHERE user_id = ?', [$userId]);
        $settings = $row !== null ? (json_decode((string) $row['settings'], true) ?: []) : ['version' => 1, 'types' => []];
        $settings['channels'] = ['email' => $state['digest_email'], 'push' => $state['push']];
        Database::execute(
            'INSERT INTO notification_settings (user_id, settings, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE settings = VALUES(settings)',
            [$userId, json_encode($settings, JSON_UNESCAPED_UNICODE), gmdate('Y-m-d H:i:s')]
        );
    }

    /** Tudo que o sistema guarda sobre o usuário (direito de confirmação e acesso). */
    /** @return array<string,mixed> */
    public static function myData(int $userId): array
    {
        $user = (new User())->find($userId) ?? [];
        $profile = [
            'nome' => $user['name'] ?? '', 'email' => $user['email'] ?? '', 'email_confirmado_em' => $user['email_verified_at'] ?? null,
            'cor' => $user['color'] ?? '', 'fuso_horario' => $user['timezone'] ?? '', 'cpf' => isset($user['document']) && $user['document'] !== null ? 'informado (criptografado)' : 'não informado',
            'maioridade_confirmada_em' => $user['adult_confirmed_at'] ?? null, 'status' => $user['status'] ?? '',
            'verificacao_duas_etapas' => !empty($user['totp_enabled_at']) ? 'ativa' : 'inativa',
            'tempo_de_sessao_min' => $user['session_idle_minutes'] ?? null, 'ultimo_login_em' => $user['last_login_at'] ?? null,
            'ultimo_login_ip' => $user['last_login_ip'] ?? null, 'conta_criada_em' => $user['created_at'] ?? null,
        ];
        $households = Database::select(
            'SELECT h.id, h.name, h.type, m.role, m.estimated_income, m.joined_at, m.left_at FROM household_members m JOIN households h ON h.id = m.household_id WHERE m.user_id = ? ORDER BY m.joined_at',
            [$userId]
        );
        $counts = [];
        foreach ($households as $h) {
            $hid = (int) $h['id'];
            $counts[$hid] = [
                'lancamentos_meus' => (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ? AND (responsible_user_id = ? OR created_by = ?)', [$hid, $userId, $userId]),
                'contas_minhas'    => (int) Database::scalar('SELECT COUNT(*) FROM accounts WHERE household_id = ? AND owner_user_id = ? AND deleted_at IS NULL', [$hid, $userId]),
                'metas'            => (int) Database::scalar('SELECT COUNT(*) FROM goals WHERE household_id = ? AND user_id = ?', [$hid, $userId]),
                'orcamentos'       => (int) Database::scalar('SELECT COUNT(*) FROM budgets WHERE household_id = ? AND user_id = ?', [$hid, $userId]),
            ];
        }
        return [
            'perfil'            => $profile,
            'lares'             => $households,
            'contagens_por_lar' => $counts,
            'consentimentos'    => Database::select('SELECT kind, granted, document_version, ip, created_at FROM consents WHERE user_id = ? ORDER BY id DESC', [$userId]),
            'sessoes'           => DatabaseSessionHandler::forUser($userId),
            'lembrar_me'        => Database::select('SELECT device_label, ip, created_at, last_used_at, expires_at FROM remember_tokens WHERE user_id = ?', [$userId]),
            'acessos'           => Database::select('SELECT ip, succeeded, user_agent, created_at FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 50', [$user['email'] ?? '']),
            'push'              => Database::select('SELECT device_label, created_at, last_success_at, disabled_at FROM push_subscriptions WHERE user_id = ?', [$userId]),
            'notificacoes'      => Database::selectOne('SELECT settings FROM notification_settings WHERE user_id = ?', [$userId]),
            'auditoria_total'   => (int) Database::scalar('SELECT COUNT(*) FROM audit_logs WHERE user_id = ?', [$userId]),
            'exportacoes'       => Database::select('SELECT status, size_bytes, expires_at, downloaded_at, created_at FROM data_exports WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$userId]),
            'exclusao'          => self::pendingDeletion($userId),
        ];
    }

    // --- Exclusão de conta ---

    /** @return array<string,mixed>|null */
    public static function pendingDeletion(int $userId): ?array
    {
        return Database::selectOne("SELECT * FROM deletion_requests WHERE user_id = ? AND kind = 'account' AND cancelled_at IS NULL AND executed_at IS NULL", [$userId]);
    }

    /** @return array<string,mixed>|null */
    public static function pendingHouseholdDeletion(int $householdId): ?array
    {
        return Database::selectOne("SELECT d.*, u.name AS requested_by_name FROM deletion_requests d JOIN users u ON u.id = d.user_id WHERE d.household_id = ? AND d.kind = 'household' AND d.cancelled_at IS NULL AND d.executed_at IS NULL", [$householdId]);
    }

    public static function graceDays(): int
    {
        $v = Database::scalar("SELECT `value` FROM settings WHERE `key` = 'retention.deletion_grace_days'");
        return max(1, (int) ($v ?? 7));
    }

    /** Agenda a exclusão da conta. Devolve null se ok ou a mensagem de impedimento. */
    public static function requestAccountDeletion(int $userId, string $ip): ?string
    {
        if (self::pendingDeletion($userId) !== null) {
            return 'Já existe um pedido de exclusão em andamento.';
        }
        // Responsável por lar com outros membros ativos precisa transferir a responsabilidade ou excluir o lar antes
        $blocking = Database::select(
            "SELECT h.name, (SELECT COUNT(*) FROM household_members m2 WHERE m2.household_id = h.id AND m2.left_at IS NULL AND m2.user_id <> ?) AS others
               FROM households h JOIN household_members m ON m.household_id = h.id AND m.user_id = ? AND m.role = 'owner' AND m.left_at IS NULL
              WHERE h.deleted_at IS NULL HAVING others > 0",
            [$userId, $userId]
        );
        if ($blocking !== []) {
            return 'Você é o responsável pelo lar "' . $blocking[0]['name'] . '", que tem outros membros. Transfira a responsabilidade a outro membro ou exclua o lar antes de excluir a conta.';
        }
        $days = self::graceDays();
        $scheduled = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        $id = Database::insert('deletion_requests', [
            'kind' => 'account', 'user_id' => $userId, 'household_id' => null, 'scheduled_for' => $scheduled, 'ip' => $ip, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        (new User())->update($userId, ['status' => 'pending_deletion']);
        AuditService::log('user.deletion_requested', 'user', $userId, null, ['scheduled_for' => $scheduled], $userId);
        $user = (new User())->find($userId);
        if ($user !== null) {
            Mailer::send((string) $user['email'], (string) $user['name'], 'Exclusão da sua conta agendada', 'deletion-scheduled', [
                'name' => $user['name'], 'days' => $days, 'when' => $scheduled, 'kind' => 'account',
                'cancelUrl' => absolute_url('/conta/privacidade'),
            ], 'transactional', $userId);
        }
        return null;
    }

    public static function cancelAccountDeletion(int $userId): bool
    {
        $pending = self::pendingDeletion($userId);
        if ($pending === null) {
            return false;
        }
        Database::execute('UPDATE deletion_requests SET cancelled_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), (int) $pending['id']]);
        (new User())->update($userId, ['status' => 'active']);
        AuditService::log('user.deletion_cancelled', 'user', $userId, null, null, $userId);
        return true;
    }

    // --- Exclusão do lar ---

    public static function requestHouseholdDeletion(int $householdId, int $userId, string $ip): ?string
    {
        if (self::pendingHouseholdDeletion($householdId) !== null) {
            return 'Já existe um pedido de exclusão deste lar em andamento.';
        }
        $household = Household::findById($householdId);
        if ($household === null) {
            return 'Lar não encontrado.';
        }
        $days = self::graceDays();
        $scheduled = gmdate('Y-m-d H:i:s', time() + $days * 86400);
        Database::insert('deletion_requests', [
            'kind' => 'household', 'user_id' => $userId, 'household_id' => $householdId, 'scheduled_for' => $scheduled, 'ip' => $ip, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        (new Household())->update($householdId, ['status' => 'pending_deletion', 'deletion_scheduled_at' => $scheduled]);
        AuditService::log('household.deletion_requested', 'household', $householdId, null, ['scheduled_for' => $scheduled], $userId, $householdId);
        $members = Database::select('SELECT u.id, u.name, u.email FROM household_members m JOIN users u ON u.id = m.user_id WHERE m.household_id = ? AND m.left_at IS NULL AND u.status = ?', [$householdId, 'active']);
        foreach ($members as $member) {
            Mailer::send((string) $member['email'], (string) $member['name'], 'Exclusão do lar "' . $household['name'] . '" agendada', 'deletion-scheduled', [
                'name' => $member['name'], 'days' => $days, 'when' => $scheduled, 'kind' => 'household', 'householdName' => $household['name'],
                'cancelUrl' => absolute_url('/conta/privacidade'),
            ], 'transactional', (int) $member['id']);
        }
        return null;
    }

    public static function cancelHouseholdDeletion(int $householdId, int $userId): bool
    {
        $pending = self::pendingHouseholdDeletion($householdId);
        if ($pending === null) {
            return false;
        }
        Database::execute('UPDATE deletion_requests SET cancelled_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), (int) $pending['id']]);
        (new Household())->update($householdId, ['status' => 'active', 'deletion_scheduled_at' => null]);
        AuditService::log('household.deletion_cancelled', 'household', $householdId, null, null, $userId, $householdId);
        return true;
    }

    /** Cron: executa pedidos cuja carência terminou. Devolve quantos foram executados. */
    public static function executeDueDeletions(): int
    {
        $due = Database::select('SELECT * FROM deletion_requests WHERE cancelled_at IS NULL AND executed_at IS NULL AND scheduled_for <= ? ORDER BY id', [gmdate('Y-m-d H:i:s')]);
        $count = 0;
        foreach ($due as $request) {
            try {
                if ($request['kind'] === 'household' && $request['household_id'] !== null) {
                    self::deleteHousehold((int) $request['household_id']);
                } else {
                    self::eraseUser((int) $request['user_id']);
                }
                Database::execute('UPDATE deletion_requests SET executed_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), (int) $request['id']]);
                $count++;
            } catch (\Throwable $e) {
                Logger::error('Falha ao executar exclusão agendada', ['request_id' => $request['id'], 'exception' => $e]);
            }
        }
        return $count;
    }

    /**
     * Exclusão definitiva dos dados pessoais: lares em que o usuário era o único membro são apagados por inteiro;
     * nos lares compartilhados os lançamentos ficam (para não alterar as contas da família), ligados a um
     * registro anonimizado "Membro removido". Consentimentos e registros de segurança ficam sem IP/user-agent.
     */
    public static function eraseUser(int $userId): void
    {
        $user = (new User())->withTrashed()->find($userId);
        if ($user === null) {
            return;
        }
        $email = (string) $user['email'];
        $memberships = Database::select('SELECT household_id FROM household_members WHERE user_id = ?', [$userId]);
        foreach ($memberships as $m) {
            $hid = (int) $m['household_id'];
            $others = (int) Database::scalar('SELECT COUNT(*) FROM household_members WHERE household_id = ? AND user_id <> ? AND left_at IS NULL', [$hid, $userId]);
            if ($others === 0) {
                self::deleteHousehold($hid);
            }
        }
        self::anonymizeUser($userId, 'erase');
        // Dados que não precisam ser mantidos
        Database::execute('DELETE FROM login_attempts WHERE email = ?', [$email]);
        Database::execute('DELETE FROM data_exports WHERE user_id = ?', [$userId]);
        foreach (glob((string) Config::get('paths.exports') . '/' . $userId . '-*.zip') ?: [] as $file) {
            @unlink($file);
        }
        (new User())->update($userId, ['deleted_at' => gmdate('Y-m-d H:i:s')]);
        AuditService::log('user.erased', 'user', $userId, null, null, $userId, null);
    }

    /**
     * Anonimização: mantém os lançamentos nos totais do lar, mas desvincula a identidade.
     * Nome vira "Membro removido"; e-mail, senha, 2FA, CPF, IPs e user-agents são apagados; sessões e tokens caem.
     */
    public static function anonymizeUser(int $userId, string $reason = 'anonymize'): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $email = (string) (Database::scalar('SELECT email FROM users WHERE id = ?', [$userId]) ?? '');
        Database::transaction(static function () use ($userId, $now, $email): void {
            Database::execute('UPDATE invitations SET revoked_at = ? WHERE email = ? AND accepted_at IS NULL AND revoked_at IS NULL', [$now, $email]);
            Database::execute('DELETE FROM login_attempts WHERE email = ?', [$email]);
            Database::execute('DELETE FROM email_outbox WHERE user_id = ? OR to_email = ?', [$userId, $email]);
            Database::execute(
                "UPDATE users SET name = 'Membro removido', email = ?, password_hash = '', totp_secret = NULL, totp_enabled_at = NULL,
                        totp_recovery_codes = NULL, totp_last_counter = NULL, document = NULL, last_login_ip = NULL, status = 'anonymized', updated_at = ?
                  WHERE id = ?",
                ['removido-' . $userId . '@anonimizado.invalid', $now, $userId]
            );
            Database::execute('UPDATE household_members SET left_at = ?, estimated_income = NULL, updated_at = ? WHERE user_id = ? AND left_at IS NULL', [$now, $now, $userId]);
            Database::execute('UPDATE consents SET ip = NULL, user_agent = NULL WHERE user_id = ?', [$userId]);
            Database::execute('UPDATE audit_logs SET ip = NULL, user_agent = NULL, before_data = NULL, after_data = NULL WHERE user_id = ?', [$userId]);
            Database::execute('UPDATE transactions SET notes = NULL, attachment_path = NULL, attachment_name = NULL, attachment_mime = NULL WHERE created_by = ? AND is_private = 1', [$userId]);
            Database::execute('DELETE FROM sessions WHERE user_id = ?', [$userId]);
            Database::execute('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            Database::execute('DELETE FROM push_subscriptions WHERE user_id = ?', [$userId]);
            Database::execute('DELETE FROM notification_settings WHERE user_id = ?', [$userId]);
            Database::execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            Database::execute('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
        });
        // Anexos do usuário em storage/uploads
        foreach (glob((string) Config::get('paths.uploads') . '/*/u' . $userId . '-*') ?: [] as $file) {
            @unlink($file);
        }
        AuditService::log('user.anonymized', 'user', $userId, null, ['reason' => $reason], $userId, null);
    }

    /** Exclusão física do lar e de tudo que pertence a ele (as FKs em cascata cuidam das tabelas filhas). */
    public static function deleteHousehold(int $householdId): void
    {
        $household = Household::findById($householdId) ?? Database::selectOne('SELECT * FROM households WHERE id = ?', [$householdId]);
        if ($household === null) {
            return;
        }
        $members = Database::select('SELECT u.id, u.name, u.email, u.status FROM household_members m JOIN users u ON u.id = m.user_id WHERE m.household_id = ?', [$householdId]);
        foreach (Database::select('SELECT attachment_path FROM transactions WHERE household_id = ? AND attachment_path IS NOT NULL', [$householdId]) as $row) {
            $file = (string) Config::get('paths.uploads') . '/' . $row['attachment_path'];
            if (is_file($file)) {
                @unlink($file);
            }
        }
        // Ordem explícita: transactions referencia accounts com RESTRICT, então o cascade do lar sozinho falha
        Database::transaction(static function () use ($householdId): void {
            foreach (['transactions', 'transaction_templates', 'recurring_rules', 'budgets', 'goals', 'savings_actions', 'import_batches', 'category_rules', 'alerts', 'invitations', 'accounts', 'categories', 'household_members', 'audit_logs'] as $table) {
                Database::execute("DELETE FROM `{$table}` WHERE household_id = ?", [$householdId]);
            }
            Database::execute('DELETE FROM households WHERE id = ?', [$householdId]);
        });
        AuditService::log('household.deleted', 'household', $householdId, ['name' => $household['name']], null, null, null);
        foreach ($members as $member) {
            if (($member['status'] ?? '') === 'active') {
                Mailer::send((string) $member['email'], (string) $member['name'], 'O lar "' . $household['name'] . '" foi excluído', 'household-deleted', [
                    'name' => $member['name'], 'householdName' => $household['name'],
                ], 'transactional', (int) $member['id']);
            }
        }
    }

    /**
     * Sair do lar. $takeData = true cria um lar individual novo para o usuário e leva as contas dele
     * e os lançamentos pelos quais ele responde; false deixa tudo no lar, desvinculando o responsável.
     */
    public static function leaveHousehold(int $userId, int $householdId, bool $takeData): ?int
    {
        $member = Database::selectOne('SELECT * FROM household_members WHERE household_id = ? AND user_id = ? AND left_at IS NULL', [$householdId, $userId]);
        if ($member === null || $member['role'] === 'owner') {
            return null;
        }
        $user = (new User())->find($userId) ?? [];
        $newHouseholdId = null;
        Database::transaction(static function () use ($userId, $householdId, $takeData, $member, $user, &$newHouseholdId): void {
            $now = gmdate('Y-m-d H:i:s');
            if ($takeData) {
                $newHouseholdId = HouseholdService::create($userId, 'individual', (string) ($user['name'] ?? 'Meu lar'));
                // Contas do usuário e todos os lançamentos nelas
                Database::execute('UPDATE transactions SET household_id = ? WHERE household_id = ? AND account_id IN (SELECT id FROM accounts WHERE household_id = ? AND owner_user_id = ?)', [$newHouseholdId, $householdId, $householdId, $userId]);
                Database::execute('UPDATE accounts SET household_id = ? WHERE household_id = ? AND owner_user_id = ?', [$newHouseholdId, $householdId, $userId]);
                // Metas e orçamentos pessoais
                Database::execute('UPDATE goals SET household_id = ? WHERE household_id = ? AND user_id = ?', [$newHouseholdId, $householdId, $userId]);
                Database::execute('UPDATE budgets SET household_id = ? WHERE household_id = ? AND user_id = ?', [$newHouseholdId, $householdId, $userId]);
                Database::execute('UPDATE recurring_rules SET household_id = ?, account_id = NULL WHERE household_id = ? AND responsible_user_id = ? AND (account_id IS NULL OR account_id NOT IN (SELECT id FROM accounts WHERE household_id = ?))', [$newHouseholdId, $householdId, $userId, $householdId]);
                Database::execute('UPDATE recurring_rules SET household_id = ? WHERE household_id = ? AND responsible_user_id = ?', [$newHouseholdId, $householdId, $userId]);
            }
            // Lançamentos que ficaram no lar deixam de ter responsável nominal
            Database::execute('UPDATE transactions SET responsible_user_id = NULL WHERE household_id = ? AND responsible_user_id = ?', [$householdId, $userId]);
            Database::execute('UPDATE household_members SET left_at = ?, updated_at = ? WHERE id = ?', [$now, $now, (int) $member['id']]);
        });
        AuditService::log('member.left', 'household_member', (int) $member['id'], ['role' => $member['role']], ['took_data' => $takeData, 'new_household_id' => $newHouseholdId], $userId, $householdId);
        return $newHouseholdId ?? 0;
    }
}
