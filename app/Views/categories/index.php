<?php
// app/Views/categories/index.php — categorias (modelo padrão + do lar), com hierarquia, ocultar/editar/excluir
/** @var array<int,array<string,mixed>> $tree */
/** @var array<int,int> $usage */
/** @var array<int,array<string,mixed>> $parents */
/** @var bool $canWrite */
/** @var array<string,string> $kinds */
$byKind = ['expense' => [], 'income' => []];
foreach ($tree as $c) { $byKind[$c['kind']][] = $c; }
$tab = old('kind', 'expense');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Categorias</h1>
    <?php if ($canWrite): ?><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newCategory"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nova categoria</button><?php endif; ?>
</div>
<p class="text-body-secondary">As categorias do modelo padrão valem para todos os lares e podem ser <strong>ocultadas</strong>; as suas próprias podem ser editadas e excluídas. Categorias <span class="badge text-bg-light border">essenciais</span> ajudam a separar o que é necessidade do que é desejo nos relatórios.</p>

<ul class="nav nav-pills mb-3" role="tablist">
    <?php foreach ($kinds as $k => $label): ?>
        <li class="nav-item" role="presentation"><button class="nav-link <?= $tab === $k ? 'active' : '' ?>" id="tab-<?= e($k) ?>" data-bs-toggle="pill" data-bs-target="#pane-<?= e($k) ?>" type="button" role="tab" aria-controls="pane-<?= e($k) ?>" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>"><?= e($label) ?>s <span class="badge text-bg-secondary ms-1"><?= count($byKind[$k]) ?></span></button></li>
    <?php endforeach; ?>
</ul>
<div class="tab-content">
    <?php foreach ($kinds as $k => $label): ?>
        <div class="tab-pane fade <?= $tab === $k ? 'show active' : '' ?>" id="pane-<?= e($k) ?>" role="tabpanel" aria-labelledby="tab-<?= e($k) ?>" tabindex="0">
            <div class="card nc-card">
                <ul class="list-group list-group-flush">
                    <?php foreach ($byKind[$k] as $c): $own = $c['household_id'] !== null; $inactive = (int) $c['is_active'] !== 1; ?>
                        <li class="list-group-item d-flex align-items-center gap-2 <?= $c['depth'] === 1 ? 'ps-5' : '' ?> <?= $c['hidden'] || $inactive ? 'opacity-50' : '' ?>">
                            <span class="nc-cat-dot" data-bg="<?= e($c['color'] ?: '#94a3b8') ?>"><i class="bi bi-<?= e($c['icon'] ?: 'tag') ?>" aria-hidden="true"></i></span>
                            <div class="flex-grow-1 min-w-0">
                                <span class="<?= $c['depth'] === 0 ? 'fw-semibold' : '' ?>"><?= e($c['name']) ?></span>
                                <?php if ((int) $c['is_essential'] === 1): ?><span class="badge text-bg-light border ms-1">essencial</span><?php endif; ?>
                                <?php if ($own): ?><span class="badge text-bg-primary-subtle text-primary-emphasis ms-1">do lar</span><?php endif; ?>
                                <?php if ($c['hidden']): ?><span class="badge text-bg-secondary ms-1">oculta</span><?php endif; ?>
                                <?php if ($inactive): ?><span class="badge text-bg-secondary ms-1">desativada</span><?php endif; ?>
                                <?php if (isset($usage[(int) $c['id']])): ?><a class="small text-body-secondary ms-1 text-decoration-none" href="<?= e(route('transactions.index', [], ['categoria' => $c['id'], 'de' => '2000-01-01', 'ate' => '2099-12-31'])) ?>"><?= (int) $usage[(int) $c['id']] ?> lanç.</a><?php endif; ?>
                            </div>
                            <?php if ($canWrite): ?>
                                <?php if ($own): ?>
                                    <button type="button" class="btn btn-sm nc-btn-icon" data-bs-toggle="modal" data-bs-target="#editCategory" data-edit-category="<?= e(json_encode(['id' => $c['id'], 'name' => $c['name'], 'icon' => $c['icon'], 'color' => $c['color'] ?: '#0f766e', 'is_essential' => (int) $c['is_essential'], 'is_active' => (int) $c['is_active']])) ?>" aria-label="Editar <?= e($c['name']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                    <form method="post" action="<?= e(route('categories.destroy', ['id' => $c['id']])) ?>" data-confirm="Excluir a categoria &quot;<?= e($c['name']) ?>&quot;?"><?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm nc-btn-icon text-danger" aria-label="Excluir <?= e($c['name']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e(route('categories.hide', ['id' => $c['id']])) ?>"><?= csrf_field() ?>
                                        <button type="submit" class="btn btn-sm nc-btn-icon" aria-label="<?= $c['hidden'] ? 'Mostrar' : 'Ocultar' ?> <?= e($c['name']) ?>" title="<?= $c['hidden'] ? 'Mostrar' : 'Ocultar' ?>"><i class="bi bi-eye<?= $c['hidden'] ? '' : '-slash' ?>" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($canWrite): ?>
<div class="modal fade" id="newCategory" tabindex="-1" aria-labelledby="newCategoryLabel" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(route('categories.store')) ?>" data-once novalidate>
        <?= csrf_field() ?>
        <div class="modal-header"><h2 class="modal-title h5" id="newCategoryLabel">Nova categoria</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-12">
                    <label for="c_name" class="form-label">Nome</label>
                    <input type="text" class="form-control<?= invalid_class('name') ?>" id="c_name" name="name" value="<?= e((string) old('name')) ?>" required maxlength="80">
                    <?= field_error('name') ?>
                </div>
                <div class="col-6">
                    <label for="c_kind" class="form-label">Tipo</label>
                    <select class="form-select" id="c_kind" name="kind"><?php foreach ($kinds as $k => $label): ?><option value="<?= e($k) ?>" <?= $tab === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-6">
                    <label for="c_parent" class="form-label">Dentro de</label>
                    <select class="form-select<?= invalid_class('parent_id') ?>" id="c_parent" name="parent_id" data-parent-select>
                        <option value="">— categoria principal —</option>
                        <?php foreach ($parents as $p): ?><option value="<?= (int) $p['id'] ?>" data-kind="<?= e($p['kind']) ?>" <?= (int) old('parent_id', 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                    </select>
                    <?= field_error('parent_id') ?>
                </div>
                <div class="col-8">
                    <label for="c_icon" class="form-label">Ícone <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener" class="small">(nomes)</a></label>
                    <input type="text" class="form-control<?= invalid_class('icon') ?>" id="c_icon" name="icon" value="<?= e((string) old('icon', 'tag')) ?>" maxlength="40" placeholder="tag">
                    <?= field_error('icon') ?>
                </div>
                <div class="col-4">
                    <label for="c_color" class="form-label">Cor</label>
                    <input type="color" class="form-control form-control-color w-100" id="c_color" name="color" value="<?= e((string) old('color', '#0f766e')) ?>">
                </div>
                <div class="col-12 form-check ms-2">
                    <input class="form-check-input" type="checkbox" id="c_essential" name="is_essential" value="1" <?= old('is_essential') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="c_essential">Gasto essencial (necessidade, não desejo)</label>
                </div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Criar</button></div>
    </form></div>
</div>

<div class="modal fade" id="editCategory" tabindex="-1" aria-labelledby="editCategoryLabel" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" method="post" action="" data-once novalidate data-edit-form data-action-template="<?= e(route('categories.update', ['id' => 0])) ?>">
        <?= csrf_field() ?>
        <div class="modal-header"><h2 class="modal-title h5" id="editCategoryLabel">Editar categoria</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-12"><label for="e_name" class="form-label">Nome</label><input type="text" class="form-control" id="e_name" name="name" required maxlength="80"></div>
                <div class="col-8"><label for="e_icon" class="form-label">Ícone</label><input type="text" class="form-control" id="e_icon" name="icon" maxlength="40"></div>
                <div class="col-4"><label for="e_color" class="form-label">Cor</label><input type="color" class="form-control form-control-color w-100" id="e_color" name="color"></div>
                <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" id="e_essential" name="is_essential" value="1"><label class="form-check-label" for="e_essential">Gasto essencial</label></div>
                <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" id="e_active" name="is_active" value="1"><label class="form-check-label" for="e_active">Ativa (aparece no lançamento)</label></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
    </form></div>
</div>
<?php if (has_error('name') || has_error('parent_id') || has_error('icon')): ?>
<?php \App\Core\View::push('scripts', '<script nonce="' . e(nonce()) . '">document.addEventListener("DOMContentLoaded",function(){var m=document.getElementById("newCategory");if(m&&window.bootstrap){new bootstrap.Modal(m).show();}});</script>'); ?>
<?php endif; ?>
<?php endif; ?>
