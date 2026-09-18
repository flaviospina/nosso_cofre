<?php
// app/Models/Category.php
declare(strict_types=1);

namespace App\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Model;
use App\Core\Query;

/**
 * Categorias: as globais (household_id NULL, modelo pt-BR) são visíveis para todos os lares;
 * cada lar pode criar as suas e ocultar globais (households.settings.hidden_categories).
 */
final class Category extends Model
{
    protected string $table = 'categories';
    protected bool $softDeletes = true;
    protected array $fillable = ['parent_id', 'name', 'icon', 'color', 'kind', 'is_essential', 'is_active', 'sort_order'];

    public const KINDS = ['expense' => 'Despesa', 'income' => 'Receita'];

    /** Escopo: globais + do lar. */
    protected function applyScope(Query $query): void
    {
        $query->whereRaw('(`household_id` IS NULL OR `household_id` = ?)', [$this->householdId()]);
        $query->whereNull('deleted_at');
    }

    /** Lista plana ordenada (pais, depois filhas), com flag de oculta. */
    /** @param 'expense'|'income'|null $kind
     *  @return array<int,array<string,mixed>> */
    public function tree(?string $kind = null, bool $includeHidden = false, bool $onlyActive = true): array
    {
        $hidden = $this->hiddenIds();
        $rows = Database::select(
            'SELECT * FROM categories WHERE (household_id IS NULL OR household_id = ?) AND deleted_at IS NULL' . ($kind !== null ? ' AND kind = ?' : '') . ($onlyActive ? ' AND is_active = 1' : '') . ' ORDER BY kind, sort_order, name',
            $kind !== null ? [$this->householdId(), $kind] : [$this->householdId()]
        );
        $byParent = [];
        foreach ($rows as $row) {
            $row['hidden'] = in_array((int) $row['id'], $hidden, true);
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }
        $out = [];
        foreach ($byParent[0] ?? [] as $parent) {
            if (!$includeHidden && $parent['hidden']) {
                continue;
            }
            $parent['depth'] = 0;
            $out[] = $parent;
            foreach ($byParent[(int) $parent['id']] ?? [] as $child) {
                if (!$includeHidden && ($child['hidden'] || $parent['hidden'])) {
                    continue;
                }
                $child['depth'] = 1;
                $child['parent_name'] = $parent['name'];
                $child['color'] = $child['color'] ?: $parent['color'];
                $out[] = $child;
            }
        }
        return $out;
    }

    /** Mapa id → linha (com nome completo "Pai › Filha") para exibição rápida. */
    /** @return array<int,array<string,mixed>> */
    public function map(): array
    {
        $map = [];
        foreach ($this->tree(null, true, false) as $row) {
            $row['full_name'] = isset($row['parent_name']) ? $row['parent_name'] . ' › ' . $row['name'] : $row['name'];
            $map[(int) $row['id']] = $row;
        }
        return $map;
    }

    /** @return list<int> */
    public function hiddenIds(): array
    {
        $household = Auth::householdId() === $this->householdId() ? Auth::household() : Household::findById($this->householdId());
        $ids = $household['settings']['hidden_categories'] ?? [];
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    /** @param list<int> $ids */
    public function setHiddenIds(array $ids): void
    {
        $household = Household::findById($this->householdId());
        if ($household === null) {
            return;
        }
        $settings = $household['settings'];
        $settings['hidden_categories'] = array_values(array_unique(array_map('intval', $ids)));
        (new Household())->update($this->householdId(), ['settings' => $settings]);
        Auth::refresh();
    }

    public function isGlobal(int $id): bool
    {
        return Database::scalar('SELECT household_id FROM categories WHERE id = ?', [$id]) === null;
    }

    /** Confirma que a categoria existe no escopo do lar (global ou própria) e é do tipo indicado. */
    public function validFor(int $id, ?string $kind = null): bool
    {
        $row = $this->find($id);
        return $row !== null && (int) $row['is_active'] === 1 && ($kind === null || $row['kind'] === $kind);
    }
}
