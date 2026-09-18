<?php
// app/Controllers/CategoryController.php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\Category;
use App\Services\AuditService;

/** Categorias (/categorias): modelo global + categorias do lar, com hierarquia. */
final class CategoryController extends Controller
{
    public function index(): Response
    {
        $model = new Category();
        $tree = $model->tree(null, true, false);
        $usage = [];
        foreach (Database::select('SELECT category_id, COUNT(*) AS n FROM transactions WHERE household_id = ? AND deleted_at IS NULL AND category_id IS NOT NULL GROUP BY category_id', [(int) Auth::householdId()]) as $row) {
            $usage[(int) $row['category_id']] = (int) $row['n'];
        }
        return $this->view('categories/index', [
            'title'    => 'Categorias',
            'tree'     => $tree,
            'usage'    => $usage,
            'parents'  => array_values(array_filter($tree, static fn(array $c): bool => $c['depth'] === 0)),
            'canWrite' => Auth::canManage() || Auth::canWrite(),
            'kinds'    => Category::KINDS,
        ]);
    }

    public function store(): Response
    {
        $this->requireWrite();
        $data = $this->validate([
            'name'         => 'required|min:2|max:80',
            'kind'         => 'required|in:expense,income',
            'parent_id'    => 'nullable|integer',
            'icon'         => 'nullable|alpha_dash|max:40',
            'color'        => 'nullable|color',
            'is_essential' => 'nullable|boolean',
        ], ['name' => 'nome', 'kind' => 'tipo', 'parent_id' => 'categoria pai', 'icon' => 'ícone', 'color' => 'cor'], 'categories.index');
        $model = new Category();
        if (!empty($data['parent_id'])) {
            $parent = $model->find((int) $data['parent_id']);
            if ($parent === null || $parent['parent_id'] !== null || $parent['kind'] !== $data['kind']) {
                throw new HttpException(422, 'Categoria pai inválida.');
            }
        }
        $id = $model->create([
            'parent_id'    => !empty($data['parent_id']) ? (int) $data['parent_id'] : null,
            'name'         => $data['name'],
            'kind'         => $data['kind'],
            'icon'         => $data['icon'] ?: 'tag',
            'color'        => $data['color'],
            'is_essential' => (int) ($data['is_essential'] ?? 0),
            'is_active'    => 1,
            'sort_order'   => 500,
        ]);
        AuditService::log('category.created', 'category', $id, null, ['name' => $data['name'], 'kind' => $data['kind']]);
        $this->flash('success', 'Categoria "' . $data['name'] . '" criada.');
        return $this->redirectRoute('categories.index');
    }

    public function update(string $id): Response
    {
        $this->requireWrite();
        $model = new Category();
        $category = $model->findOrFail((int) $id);
        if ($category['household_id'] === null) {
            throw new HttpException(403, 'Categorias do modelo padrão não podem ser editadas; você pode ocultá-las ou criar as suas.');
        }
        $data = $this->validate([
            'name'         => 'required|min:2|max:80',
            'icon'         => 'nullable|alpha_dash|max:40',
            'color'        => 'nullable|color',
            'is_essential' => 'nullable|boolean',
            'is_active'    => 'nullable|boolean',
        ], ['name' => 'nome', 'icon' => 'ícone', 'color' => 'cor'], 'categories.index');
        $fields = ['name' => $data['name'], 'icon' => $data['icon'] ?: 'tag', 'color' => $data['color'], 'is_essential' => (int) ($data['is_essential'] ?? 0), 'is_active' => (int) ($data['is_active'] ?? 1)];
        $model->update((int) $id, $fields);
        AuditService::log('category.updated', 'category', (int) $id, array_intersect_key($category, $fields), $fields);
        $this->flash('success', 'Categoria atualizada.');
        return $this->redirectRoute('categories.index');
    }

    public function destroy(string $id): Response
    {
        $this->requireWrite();
        $model = new Category();
        $category = $model->findOrFail((int) $id);
        if ($category['household_id'] === null) {
            throw new HttpException(403, 'Categorias do modelo padrão não podem ser excluídas. Use "ocultar".');
        }
        $used = (int) Database::scalar('SELECT COUNT(*) FROM transactions WHERE household_id = ? AND category_id IN (SELECT id FROM categories WHERE id = ? OR parent_id = ?)', [(int) Auth::householdId(), (int) $id, (int) $id]);
        if ($used > 0) {
            $this->flash('danger', 'Há lançamentos nesta categoria. Desative-a em vez de excluir.');
            return $this->redirectRoute('categories.index');
        }
        Database::execute('UPDATE categories SET deleted_at = ? WHERE household_id = ? AND parent_id = ?', [gmdate('Y-m-d H:i:s'), (int) Auth::householdId(), (int) $id]);
        $model->delete((int) $id);
        AuditService::log('category.deleted', 'category', (int) $id, ['name' => $category['name']], null);
        $this->flash('success', 'Categoria excluída.');
        return $this->redirectRoute('categories.index');
    }

    /** Oculta/mostra uma categoria do modelo padrão neste lar. */
    public function toggleHidden(string $id): Response
    {
        $this->requireWrite();
        $model = new Category();
        $category = $model->findOrFail((int) $id);
        $hidden = $model->hiddenIds();
        if (in_array((int) $id, $hidden, true)) {
            $hidden = array_values(array_diff($hidden, [(int) $id]));
            $msg = 'Categoria visível de novo.';
        } else {
            $hidden[] = (int) $id;
            $msg = 'Categoria ocultada neste lar (os lançamentos existentes continuam).';
        }
        $model->setHiddenIds($hidden);
        AuditService::log('category.visibility', 'category', (int) $id, null, ['hidden' => in_array((int) $id, $hidden, true)]);
        $this->flash('success', $msg);
        return $this->redirectRoute('categories.index');
    }

    private function requireWrite(): void
    {
        if (!Auth::canWrite()) {
            throw new HttpException(403, 'Seu papel no lar é somente leitura.');
        }
    }
}
