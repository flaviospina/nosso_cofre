<?php
// app/Models/Household.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * O lar é a raiz do multi-tenant. Este model não é escopado por household_id (ele É o household).
 */
final class Household extends Model
{
    protected string $table = 'households';
    protected bool $householdScoped = false;
    protected bool $softDeletes = true;
    protected array $fillable = [
        'name', 'type', 'currency', 'fiscal_month_start_day', 'settings', 'owner_user_id', 'status', 'deletion_scheduled_at',
    ];
    protected array $json = ['settings'];

    public const TYPES = ['individual', 'family'];

    /** Configurações padrão do lar (mescladas com o JSON gravado). */
    public const DEFAULT_SETTINGS = [
        'members_can_edit_others' => false,
        'members_can_see_income'  => true,
    ];

    /** @return array<string,mixed>|null */
    public static function findById(int $id): ?array
    {
        $row = Database::selectOne('SELECT * FROM households WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($row === null) {
            return null;
        }
        $row = (new self())->castRow($row);
        $row['settings'] = array_merge(self::DEFAULT_SETTINGS, is_array($row['settings'] ?? null) ? $row['settings'] : []);
        return $row;
    }
}
