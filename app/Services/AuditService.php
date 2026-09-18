<?php
// app/Services/AuditService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Logger;

/**
 * Log de auditoria (quem, quando, o quê, IP, user-agent, antes/depois) para ações financeiras e de conta.
 * Campos sensíveis nunca entram no "antes/depois".
 */
final class AuditService
{
    private const HIDDEN = ['password', 'password_hash', 'totp_secret', 'totp_recovery_codes', 'token', 'token_hash', 'validator_hash', 'document', 'notes'];

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function log(string $action, ?string $entityType = null, ?int $entityId = null, ?array $before = null, ?array $after = null, ?int $userId = null, ?int $householdId = null): void
    {
        try {
            $request = App::request();
            Database::insert('audit_logs', [
                'household_id' => $householdId ?? Auth::householdId(),
                'user_id'      => $userId ?? Auth::id(),
                'action'       => mb_substr($action, 0, 60),
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'before_data'  => $before === null ? null : json_encode(self::sanitize($before), JSON_UNESCAPED_UNICODE),
                'after_data'   => $after === null ? null : json_encode(self::sanitize($after), JSON_UNESCAPED_UNICODE),
                'ip'           => $request?->ip(),
                'user_agent'   => $request?->userAgent(),
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Falha ao gravar auditoria', ['action' => $action, 'exception' => $e]);
        }
    }

    /** @param array<string,mixed> $data
     *  @return array<string,mixed> */
    private static function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::HIDDEN, true)) {
                $data[$key] = '[oculto]';
            } elseif (is_array($value)) {
                $data[$key] = self::sanitize($value);
            }
        }
        return $data;
    }

    /** Descrições legíveis das ações (tela "Minha atividade"). */
    public static function describe(string $action): string
    {
        return match ($action) {
            'user.register'            => 'Conta criada',
            'user.email_verified'      => 'E-mail confirmado',
            'user.login'               => 'Entrou no sistema',
            'user.login_remembered'    => 'Entrou automaticamente (lembrar-me)',
            'user.logout'              => 'Saiu do sistema',
            'user.password_changed'    => 'Senha alterada',
            'user.password_reset'      => 'Senha redefinida por recuperação',
            'user.profile_updated'     => 'Dados do perfil alterados',
            'user.2fa_enabled'         => 'Verificação em duas etapas ativada',
            'user.2fa_disabled'        => 'Verificação em duas etapas desativada',
            'user.2fa_recovery_used'   => 'Código de recuperação utilizado',
            'user.2fa_recovery_regenerated' => 'Códigos de recuperação gerados novamente',
            'user.sessions_revoked'    => 'Outras sessões encerradas',
            'consent.granted'          => 'Consentimento concedido',
            'consent.revoked'          => 'Consentimento revogado',
            'household.created'        => 'Lar criado',
            'household.updated'        => 'Configurações do lar alteradas',
            'household.converted'      => 'Conta convertida para familiar',
            'member.invited'           => 'Membro convidado',
            'member.invite_resent'     => 'Convite reenviado',
            'member.invite_revoked'    => 'Convite cancelado',
            'member.joined'            => 'Entrou no lar',
            'member.role_changed'      => 'Papel de membro alterado',
            'member.removed'           => 'Membro removido do lar',
            'member.left'              => 'Saiu do lar',
            'account.created'          => 'Conta financeira criada',
            'account.updated'          => 'Conta financeira alterada',
            'account.archived'         => 'Conta financeira arquivada',
            'account.reactivated'      => 'Conta financeira reativada',
            'account.deleted'          => 'Conta financeira excluída',
            'category.created'         => 'Categoria criada',
            'category.updated'         => 'Categoria alterada',
            'category.deleted'         => 'Categoria excluída',
            'category.visibility'      => 'Visibilidade de categoria alterada',
            'transaction.create'       => 'Lançamento registrado',
            'transaction.update'       => 'Lançamento alterado',
            'transaction.status'       => 'Situação do lançamento alterada',
            'transaction.trash'        => 'Lançamento enviado à lixeira',
            'transaction.restore'      => 'Lançamento restaurado',
            'transaction.destroy'      => 'Lançamento excluído definitivamente',
            'template.create'          => 'Modelo de lançamento salvo',
            'import.commit'            => 'Extrato importado',
            'import.undo'              => 'Importação desfeita',
            'user.email_change_requested' => 'Troca de e-mail solicitada',
            'user.email_changed'       => 'E-mail alterado',
            'user.data_exported'       => 'Dados exportados',
            'user.deletion_requested'  => 'Exclusão da conta solicitada',
            'user.deletion_cancelled'  => 'Exclusão da conta cancelada',
            'user.anonymized'          => 'Conta anonimizada',
            'user.erased'              => 'Dados pessoais excluídos',
            'household.deletion_requested' => 'Exclusão do lar solicitada',
            'household.deletion_cancelled' => 'Exclusão do lar cancelada',
            'household.deleted'        => 'Lar excluído',
            'household.ownership_transferred' => 'Responsabilidade do lar transferida',
            'incident.created'         => 'Incidente registrado',
            'incident.notified'        => 'Usuários comunicados sobre incidente',
            'incident.closed'          => 'Incidente encerrado',
            'incident.anpd_notified'   => 'ANPD comunicada',
            default                    => $action,
        };
    }
}
