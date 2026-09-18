<?php
// app/Services/IncidentService.php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;

/**
 * Registro de incidentes de segurança e comunicação aos titulares afetados (art. 48 da LGPD).
 */
final class IncidentService
{
    /** @param array{title:string,description:string,occurred_at:?string,detected_at:string,notes:?string} $data */
    public static function create(array $data, int $byUserId): int
    {
        $id = Database::insert('incidents', [
            'title' => $data['title'], 'description' => $data['description'], 'occurred_at' => $data['occurred_at'], 'detected_at' => $data['detected_at'],
            'status' => 'open', 'notes' => $data['notes'], 'created_by' => $byUserId, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        AuditService::log('incident.created', 'incident', $id, null, ['title' => $data['title']], $byUserId, null);
        return $id;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Database::select('SELECT i.*, u.name AS created_by_name FROM incidents i LEFT JOIN users u ON u.id = i.created_by ORDER BY i.id DESC');
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::selectOne('SELECT * FROM incidents WHERE id = ?', [$id]);
    }

    /**
     * Comunica os afetados. $scope = 'all' (todos os usuários ativos) ou lista de e-mails.
     * @param list<string>|null $emails
     * @return int quantidade de e-mails enfileirados
     */
    public static function notify(int $incidentId, ?array $emails, string $measures, string $recommendations, int $byUserId): int
    {
        $incident = self::find($incidentId);
        if ($incident === null) {
            return 0;
        }
        if ($emails === null) {
            $targets = Database::select("SELECT id, name, email FROM users WHERE status = 'active' AND email_verified_at IS NOT NULL AND deleted_at IS NULL");
        } else {
            $targets = [];
            foreach (array_unique(array_map(static fn(string $e): string => mb_strtolower(trim($e)), $emails)) as $email) {
                $row = Database::selectOne("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'", [$email]);
                if ($row !== null) {
                    $targets[] = $row;
                }
            }
        }
        foreach ($targets as $t) {
            Mailer::send((string) $t['email'], (string) $t['name'], 'Comunicado de segurança: ' . $incident['title'], 'incident', [
                'name' => $t['name'], 'title' => $incident['title'], 'description' => $incident['description'],
                'occurredAt' => $incident['occurred_at'], 'detectedAt' => $incident['detected_at'],
                'measures' => $measures, 'recommendations' => $recommendations,
                'dpoName' => (string) Config::get('legal.dpo_name'), 'dpoEmail' => (string) Config::get('legal.dpo_email'),
            ], 'security', (int) $t['id']);
        }
        Database::execute("UPDATE incidents SET affected_users_count = ?, notified_at = ?, status = 'notified', notes = CONCAT(COALESCE(notes, ''), ?), updated_at = ? WHERE id = ?", [
            count($targets), gmdate('Y-m-d H:i:s'), "\n[" . gmdate('d/m/Y H:i') . " UTC] Comunicado enviado a " . count($targets) . " usuário(s).\nMedidas: {$measures}\nRecomendações: {$recommendations}\n", gmdate('Y-m-d H:i:s'), $incidentId,
        ]);
        AuditService::log('incident.notified', 'incident', $incidentId, null, ['count' => count($targets)], $byUserId, null);
        return count($targets);
    }

    public static function close(int $incidentId, int $byUserId): void
    {
        Database::execute("UPDATE incidents SET status = 'closed', updated_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s'), $incidentId]);
        AuditService::log('incident.closed', 'incident', $incidentId, null, null, $byUserId, null);
    }

    public static function markAnpdNotified(int $incidentId, int $byUserId): void
    {
        Database::execute('UPDATE incidents SET anpd_notified_at = ?, updated_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'), $incidentId]);
        AuditService::log('incident.anpd_notified', 'incident', $incidentId, null, null, $byUserId, null);
    }
}
