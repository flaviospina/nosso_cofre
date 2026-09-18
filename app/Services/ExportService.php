<?php
// app/Services/ExportService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Mailer;
use App\Models\User;
use RuntimeException;
use ZipArchive;

/**
 * Portabilidade (art. 18, V): ZIP com JSON completo + CSVs das tabelas principais.
 * Arquivo em storage/exports/{userId}-{id}.zip, link com token de 24 h, aviso por e-mail.
 */
final class ExportService
{
    public const TTL_HOURS = 24;

    /** @return array{id:int,token:string,size:int,expires_at:string} */
    public static function create(int $userId): array
    {
        $user = (new User())->find($userId);
        if ($user === null) {
            throw new RuntimeException('Usuário não encontrado.');
        }
        $dir = (string) Config::get('paths.exports');
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $token = Crypto::randomUrlToken(32);
        $expires = gmdate('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600);
        $id = Database::insert('data_exports', [
            'user_id' => $userId, 'token_hash' => Crypto::hashToken($token), 'status' => 'pending', 'expires_at' => $expires, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $path = $dir . '/' . $userId . '-' . $id . '.zip';

        $data = self::collect($userId, $user);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Database::execute("UPDATE data_exports SET status = 'failed' WHERE id = ?", [$id]);
            throw new RuntimeException('Não foi possível criar o arquivo ZIP.');
        }
        $zip->addFromString('LEIA-ME.txt', self::readme($user));
        $zip->addFromString('meus-dados.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach (['lancamentos', 'contas', 'categorias', 'recorrencias', 'orcamentos', 'metas', 'plano_de_economia', 'consentimentos', 'atividade', 'acessos'] as $name) {
            if (!empty($data[$name]) && is_array($data[$name])) {
                $zip->addFromString($name . '.csv', self::csv($data[$name]));
            }
        }
        $zip->close();
        $size = (int) filesize($path);
        Database::execute("UPDATE data_exports SET status = 'ready', file_path = ?, size_bytes = ? WHERE id = ?", [basename($path), $size, $id]);
        AuditService::log('user.data_exported', 'data_export', $id, null, ['size' => $size], $userId);
        Mailer::send((string) $user['email'], (string) $user['name'], 'Sua exportação de dados está pronta', 'export-ready', [
            'name' => $user['name'], 'url' => absolute_url('/conta/privacidade/exportacoes/' . $token), 'hours' => self::TTL_HOURS,
        ], 'transactional', $userId);
        return ['id' => $id, 'token' => $token, 'size' => $size, 'expires_at' => $expires];
    }

    /** @return array<string,mixed>|null linha válida da exportação (do próprio usuário) */
    public static function findValid(string $token, int $userId): ?array
    {
        $row = Database::selectOne(
            "SELECT * FROM data_exports WHERE token_hash = ? AND user_id = ? AND status IN ('ready','downloaded') AND expires_at > ?",
            [Crypto::hashToken($token), $userId, gmdate('Y-m-d H:i:s')]
        );
        if ($row === null) {
            return null;
        }
        $path = (string) Config::get('paths.exports') . '/' . $row['file_path'];
        if (!is_file($path)) {
            return null;
        }
        $row['absolute_path'] = $path;
        return $row;
    }

    public static function markDownloaded(int $id): void
    {
        Database::execute("UPDATE data_exports SET status = 'downloaded', downloaded_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Remove exportações vencidas (cron). */
    public static function purgeExpired(): int
    {
        $rows = Database::select("SELECT id, file_path FROM data_exports WHERE expires_at <= ? AND status <> 'expired'", [gmdate('Y-m-d H:i:s')]);
        foreach ($rows as $row) {
            if ($row['file_path'] !== null) {
                $path = (string) Config::get('paths.exports') . '/' . $row['file_path'];
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            Database::execute("UPDATE data_exports SET status = 'expired', file_path = NULL WHERE id = ?", [(int) $row['id']]);
        }
        return count($rows);
    }

    /** @param array<string,mixed> $user
     *  @return array<string,mixed> */
    private static function collect(int $userId, array $user): array
    {
        $households = Database::select('SELECT h.id, h.name, h.type, h.currency, m.role, m.joined_at, m.left_at FROM household_members m JOIN households h ON h.id = m.household_id WHERE m.user_id = ?', [$userId]);
        // Só lares em que o usuário ainda está: dos que saiu, exporta-se apenas o histórico de participação
        $ids = array_map(static fn(array $h): int => (int) $h['id'], array_filter($households, static fn(array $h): bool => $h['left_at'] === null));
        $in = $ids === [] ? '0' : implode(',', $ids);
        $visible = [];
        $visibleParams = [];
        foreach ($ids as $hid) {
            [$vs, $vp] = TransactionPolicy::visibleSql('t', $userId, $hid);
            $visible[] = "(t.household_id = {$hid} AND {$vs})";
            $visibleParams = array_merge($visibleParams, $vp);
        }
        $visibleSql = $visible === [] ? '0' : '(' . implode(' OR ', $visible) . ')';
        $sub = static fn(string $sql, array $p = []): array => Database::select($sql, $p);
        return [
            'exportado_em' => gmdate('c'),
            'perfil' => ['nome' => $user['name'], 'email' => $user['email'], 'cor' => $user['color'], 'fuso_horario' => $user['timezone'], 'criado_em' => $user['created_at'], 'email_confirmado_em' => $user['email_verified_at'], 'cpf' => $user['document'] ?? null],
            'lares' => $households,
            'contas' => $sub("SELECT a.id, a.household_id, a.name AS nome, a.type AS tipo, a.institution AS instituicao, a.initial_balance AS saldo_inicial, a.closing_day AS dia_fechamento, a.due_day AS dia_vencimento, a.limit_amount AS limite, a.owner_user_id AS dono_user_id, a.created_at FROM accounts a WHERE a.household_id IN ({$in}) AND a.deleted_at IS NULL"),
            'categorias' => $sub("SELECT id, household_id, parent_id, name AS nome, kind AS tipo, is_essential AS essencial FROM categories WHERE (household_id IS NULL OR household_id IN ({$in})) AND deleted_at IS NULL"),
            // Lançamentos: os do usuário (responsável ou criador) em cada lar; os privados de outros membros nunca saem
            'lancamentos' => array_map(static function (array $t): array {
                $t['observacoes'] = \App\Core\Crypto::tryDecrypt($t['observacoes'] ?? null);
                return $t;
            }, $sub("SELECT id, household_id, account_id AS conta_id, category_id AS categoria_id, responsible_user_id AS responsavel_user_id, created_by AS criado_por, type AS tipo, amount AS valor, date AS data, paid_at AS pago_em, description AS descricao, notes AS observacoes, tags, status, installment_no AS parcela, installment_total AS total_parcelas, auto_debit AS debito_automatico, is_private AS privado, created_at FROM transactions t WHERE t.household_id IN ({$in}) AND (t.responsible_user_id = ? OR t.created_by = ?) AND t.deleted_at IS NULL AND {$visibleSql}", array_merge([$userId, $userId], $visibleParams))),
            'recorrencias' => $sub("SELECT id, household_id, description AS descricao, kind AS tipo, expected_amount AS valor_previsto, frequency AS frequencia, day_of_month AS dia, month_of_year AS mes, start_date AS inicio, end_date AS fim, auto_debit AS debito_automatico, is_subscription AS assinatura, is_active AS ativa FROM recurring_rules WHERE household_id IN ({$in}) AND (responsible_user_id = ? OR responsible_user_id IS NULL) AND deleted_at IS NULL", [$userId]),
            'orcamentos' => $sub("SELECT id, household_id, category_id AS categoria_id, period_month AS mes, limit_amount AS limite FROM budgets WHERE household_id IN ({$in}) AND (user_id = ? OR user_id IS NULL)", [$userId]),
            'metas' => $sub("SELECT id, household_id, name AS nome, target_amount AS alvo, saved_amount AS guardado, deadline AS prazo, status FROM goals WHERE household_id IN ({$in}) AND (user_id = ? OR user_id IS NULL) AND deleted_at IS NULL", [$userId]),
            'plano_de_economia' => $sub("SELECT id, household_id, title AS titulo, status, estimated_saving_month AS economia_estimada, measured_saving_month AS economia_medida FROM savings_actions WHERE household_id IN ({$in}) AND (responsible_user_id = ? OR responsible_user_id IS NULL) AND deleted_at IS NULL", [$userId]),
            'consentimentos' => $sub('SELECT kind AS tipo, granted AS concedido, document_version AS versao_documento, ip, created_at FROM consents WHERE user_id = ? ORDER BY id', [$userId]),
            'notificacoes' => Database::selectOne('SELECT settings FROM notification_settings WHERE user_id = ?', [$userId]),
            'atividade' => $sub('SELECT action AS acao, entity_type AS entidade, entity_id, ip, created_at FROM audit_logs WHERE user_id = ? ORDER BY id DESC LIMIT 5000', [$userId]),
            'acessos' => $sub('SELECT ip, succeeded AS sucesso, user_agent, created_at FROM login_attempts WHERE email = ? ORDER BY id DESC LIMIT 1000', [(string) $user['email']]),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows */
    public static function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel abrir em UTF-8
        fputcsv($out, array_keys($rows[0]), ';', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn($v) => self::csvSafe(is_array($v) ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? '')), array_values($row)), ';', '"', '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * Neutraliza injeção de fórmula em planilhas: célula que começa com = + - @ TAB ou CR ganha apóstrofo na frente
     * (o Excel/LibreOffice mostram o texto em vez de executar). Valores monetários negativos usam o sinal "−" (U+2212).
     */
    public static function csvSafe(string $cell): string
    {
        if ($cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false) {
            if (preg_match('/^[-+]?\d+([.,]\d+)?$/', $cell) === 1) {
                return $cell; // número puro (ex.: -50,00): a planilha lê como número, não como fórmula
            }
            if (preg_match('/^-\s*R\$/u', $cell) === 1) {
                return '−' . substr($cell, 1);
            }
            return "'" . $cell;
        }
        return $cell;
    }

    /** @param array<string,mixed> $user */
    private static function readme(array $user): string
    {
        return "Exportação de dados do Nosso Cofre\n"
            . "Titular: {$user['name']} <{$user['email']}>\n"
            . 'Gerada em: ' . gmdate('d/m/Y H:i') . " (UTC)\n\n"
            . "Conteúdo:\n"
            . "- meus-dados.json: tudo em um único arquivo estruturado (JSON).\n"
            . "- *.csv: as mesmas tabelas em CSV (separador ';', UTF-8), para abrir em planilha.\n\n"
            . "Datas/horas estão em UTC. Valores em formato decimal com ponto.\n"
            . "Lançamentos marcados como 'visível só para mim' por outros membros do lar não fazem parte desta exportação.\n";
    }
}
