<?php
// app/Views/savings/index.php — plano de ação de economia: estimado × realizado
/** @var array{items:list<array<string,mixed>>,estimated:float,measured:float,done:int,total:int} $plan */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,array<string,mixed>> $categoryMap */
/** @var array<int,array<string,mixed>> $members */
/** @var bool $canWrite */
/** @var bool $isFamily */
/** @var array<string,string> $statuses */
$memberNames = array_column($members, 'name', 'user_id');
$byStatus = ['doing' => [], 'todo' => [], 'done' => []];
foreach ($plan['items'] as $a) { $byStatus[$a['status']][] = $a; }
$statusIcon = ['todo' => 'circle', 'doing' => 'play-circle-fill nc-due', 'done' => 'check-circle-fill nc-income'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Plano de ação de economia</h1>
    <?php if ($canWrite): ?><button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#actionModal" data-new-action><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nova ação</button><?php endif; ?>
</div>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Economia estimada</div><div class="fs-4 fw-semibold"><?= e(money($plan['estimated'])) ?><span class="small fw-normal text-body-secondary">/mês</span></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Realizada neste mês</div><div class="fs-4 fw-semibold <?= $plan['measured'] >= 0 ? 'nc-income' : 'nc-expense' ?>"><?= e(money($plan['measured'])) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Ações concluídas</div><div class="fs-4 fw-semibold"><?= $plan['done'] ?> <span class="small fw-normal text-body-secondary">de <?= $plan['total'] ?></span></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card nc-card"><div class="card-body py-3"><div class="small text-body-secondary">Em 12 meses</div><div class="fs-4 fw-semibold nc-goal"><?= e(money($plan['estimated'] * 12)) ?></div></div></div></div>
</div>
<p class="text-body-secondary small">"Realizada" compara a média dos 3 meses anteriores ao início da ação com o gasto do mês atual na categoria ligada (só para ações iniciadas e com categoria). Positivo = economizou.</p>

<?php if ($plan['items'] === []): ?>
    <div class="card nc-card"><div class="card-body text-center py-5"><i class="bi bi-check2-square fs-1 text-body-secondary" aria-hidden="true"></i><p class="mt-2 mb-0">Nenhuma ação ainda. Anote cortes concretos: "cancelar X", "padaria só no fim de semana", "colocar Y em débito automático".</p></div></div>
<?php else: ?>
    <?php foreach (['doing' => 'Em andamento', 'todo' => 'A fazer', 'done' => 'Feito'] as $st => $label): if ($byStatus[$st] === []) { continue; } ?>
        <h2 class="h6 text-body-secondary mt-3"><?= e($label) ?> <span class="badge text-bg-secondary"><?= count($byStatus[$st]) ?></span></h2>
        <div class="card nc-card <?= $st === 'done' ? 'opacity-75' : '' ?>"><ul class="list-group list-group-flush">
            <?php foreach ($byStatus[$st] as $a): $cat = $a['category_id'] !== null ? ($categoryMap[(int) $a['category_id']] ?? null) : null; ?>
                <li class="list-group-item">
                    <div class="d-flex align-items-start gap-2">
                        <i class="bi bi-<?= e($statusIcon[$a['status']]) ?> fs-5 mt-1" aria-hidden="true"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold"><?= e($a['title']) ?></div>
                            <?php if ($a['description']): ?><div class="small"><?= e($a['description']) ?></div><?php endif; ?>
                            <div class="small text-body-secondary">
                                <?= $isFamily ? e($a['responsible_user_id'] !== null ? ($memberNames[(int) $a['responsible_user_id']] ?? 'Membro') : 'Ambos / todos') . ' · ' : '' ?>
                                <?= $cat ? e($cat['full_name']) . ' · ' : '' ?>estimado <strong><?= e(money($a['estimated_saving_month'])) ?>/mês</strong>
                                <?php if ($a['measured'] !== null): ?> · realizado <strong class="<?= $a['measured'] >= 0 ? 'nc-income' : 'nc-expense' ?>"><?= e(money($a['measured'])) ?></strong> <span title="Média de 3 meses antes: <?= e(money($a['baseline'])) ?> · este mês: <?= e(money($a['current'])) ?>">(base <?= e(money($a['baseline'])) ?> → mês <?= e(money($a['current'])) ?>)</span><?php endif; ?>
                                <?= $a['started_at'] ? ' · desde ' . e(date_br($a['started_at'])) : '' ?><?= $a['done_at'] ? ' · feito em ' . e(date_br($a['done_at'])) : '' ?>
                            </div>
                        </div>
                        <?php if ($canWrite): ?>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <?php if ($a['status'] === 'todo'): ?><form method="post" action="<?= e(route('savings.status', ['id' => $a['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="doing"><button type="submit" class="btn btn-sm btn-outline-primary">Iniciar</button></form><?php endif; ?>
                                <?php if ($a['status'] === 'doing'): ?><form method="post" action="<?= e(route('savings.status', ['id' => $a['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="done"><button type="submit" class="btn btn-sm btn-success">Concluir</button></form><?php endif; ?>
                                <?php if ($a['status'] === 'done'): ?><form method="post" action="<?= e(route('savings.status', ['id' => $a['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="doing"><button type="submit" class="btn btn-sm btn-outline-secondary">Reabrir</button></form><?php endif; ?>
                                <div class="dropdown">
                                    <button class="btn btn-sm nc-btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Mais ações para <?= e($a['title']) ?>"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#actionModal" data-edit-action="<?= e(json_encode(['id' => $a['id'], 'title' => $a['title'], 'description' => $a['description'], 'responsible' => $a['responsible_user_id'], 'category' => $a['category_id'], 'estimated' => money($a['estimated_saving_month'], false)])) ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Editar</button></li>
                                        <?php if ($a['status'] !== 'todo'): ?><li><form method="post" action="<?= e(route('savings.status', ['id' => $a['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="todo"><button type="submit" class="dropdown-item"><i class="bi bi-arrow-counterclockwise me-2" aria-hidden="true"></i>Voltar para "a fazer"</button></form></li><?php endif; ?>
                                        <li><form method="post" action="<?= e(route('savings.destroy', ['id' => $a['id']])) ?>" data-confirm="Remover a ação &quot;<?= e($a['title']) ?>&quot;?"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Remover</button></form></li>
                                    </ul>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul></div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($canWrite): ?>
<div class="modal fade" id="actionModal" tabindex="-1" aria-labelledby="actionModalLabel" aria-hidden="true">
    <div class="modal-dialog"><form class="modal-content" method="post" action="<?= e(route('savings.store')) ?>" data-once novalidate data-action-form data-store-url="<?= e(route('savings.store')) ?>" data-update-template="<?= e(route('savings.update', ['id' => 0])) ?>">
        <?= csrf_field() ?>
        <div class="modal-header"><h2 class="modal-title h5" id="actionModalLabel" data-modal-title>Nova ação</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-12"><label for="a_title" class="form-label">O que fazer</label><input type="text" class="form-control<?= invalid_class('title') ?>" id="a_title" name="title" value="<?= e((string) old('title')) ?>" required maxlength="190" placeholder="Ex.: Cancelar a segunda assinatura do Amazon Prime"><?= field_error('title') ?></div>
            <div class="col-12"><label for="a_desc" class="form-label">Detalhes <span class="text-body-secondary small">(opcional)</span></label><textarea class="form-control" id="a_desc" name="description" rows="2" maxlength="1000"><?= e((string) old('description')) ?></textarea></div>
            <?php if ($isFamily): ?><div class="col-6"><label for="a_resp" class="form-label">Responsável</label><select class="form-select" id="a_resp" name="responsible_user_id"><option value="">Ambos / todos</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="col-6"><label for="a_est" class="form-label">Economia estimada</label><div class="input-group"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control" id="a_est" name="estimated_saving_month" value="<?= e((string) old('estimated_saving_month')) ?>" data-money placeholder="0,00"><span class="input-group-text">/mês</span></div></div>
            <div class="col-12"><label for="a_cat" class="form-label">Categoria para medir <span class="text-body-secondary small">(opcional)</span></label><select class="form-select" id="a_cat" name="category_id"><option value="">Sem medição automática</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $c['depth'] === 1 ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?></select><div class="form-text">Com uma categoria, o app compara a média dos 3 meses anteriores com o mês atual.</div></div>
        </div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
    </form></div>
</div>
<?php if (has_error('title')): ?>
<?php \App\Core\View::push('scripts', '<script nonce="' . e(nonce()) . '">document.addEventListener("DOMContentLoaded",function(){var m=document.getElementById("actionModal");if(m&&window.bootstrap){new bootstrap.Modal(m).show();}});</script>'); ?>
<?php endif; ?>
<?php endif; ?>
