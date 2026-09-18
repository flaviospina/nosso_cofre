<?php
// app/Models/Consent.php
declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Model;

/**
 * Registro de consentimentos (LGPD). Sempre insere uma linha nova: o histórico é a prova.
 */
final class Consent extends Model
{
    protected string $table = 'consents';
    protected bool $householdScoped = false;
    protected bool $timestamps = false;
    protected array $fillable = ['user_id', 'kind', 'granted', 'document_version', 'ip', 'user_agent', 'created_at'];

    public const KINDS = ['terms', 'privacy', 'adult', 'push', 'transactional_email', 'digest_email', 'share_with_household', 'security_push'];

    public static function record(int $userId, string $kind, bool $granted, ?string $documentVersion = null): int
    {
        $request = App::request();
        return Database::insert('consents', [
            'user_id'          => $userId,
            'kind'             => $kind,
            'granted'          => $granted ? 1 : 0,
            'document_version' => $documentVersion,
            'ip'               => $request?->ip(),
            'user_agent'       => $request?->userAgent(),
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Estado atual (última linha) de cada tipo de consentimento do usuário. */
    /** @return array<string,array<string,mixed>> */
    public static function currentFor(int $userId): array
    {
        $rows = Database::select('SELECT * FROM consents WHERE user_id = ? ORDER BY id ASC', [$userId]);
        $current = [];
        foreach ($rows as $row) {
            $current[(string) $row['kind']] = $row;
        }
        return $current;
    }

    public static function isGranted(int $userId, string $kind): bool
    {
        $row = Database::selectOne('SELECT granted FROM consents WHERE user_id = ? AND kind = ? ORDER BY id DESC LIMIT 1', [$userId, $kind]);
        return $row !== null && (int) $row['granted'] === 1;
    }
}
