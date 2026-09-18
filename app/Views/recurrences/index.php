<?php
// app/Views/recurrences/index.php — recorrências: a confirmar, eventos previstos, débito automático e regras
/** @var array<int,array<string,mixed>> $rules */
/** @var array<int,array<string,mixed>> $pending */
/** @var array<int|string,array{name:string,total:int,auto:int,pct:int}> $autoDebit */
/** @var array<int,array<string,mixed>> $events */
/** @var array<int,array<string,mixed>> $categories */
/** @var array<int,string> $accounts */
/** @var array<int,string> $members */
/** @var bool $canWrite */
/** @var bool $isFamily */
/** @var string $today */
$freq = \App\Models\RecurringRule::FREQUENCIES;
$active = array_values(array_filter($rules, static fn(array $r): bool => (int) $r['is_active'] === 1));
$paused = array_values(array_filter($rules, static fn(array $r): bool => (int) $r['is_active'] !== 1));
$monthlyTotal = 0.0;
foreach ($active as $r) { if ($r['kind'] === 'expense') { $monthlyTotal += (float) $r['expected_amount'] * \App\Services\RecurrenceService::monthlyFactor($r); } }
$freqText = static function (array $r) use ($freq): string {
    return match ($r['frequency']) {
        'weekly' => 'Toda semana (' . ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'][(int) $r['day_of_week']] . ')',
        'yearly' => 'Todo ano em ' . (int) $r['day_of_month'] . '/' . str_pad((string) $r['month_of_year'], 2, '0', STR_PAD_LEFT),
        'custom' => 'A cada ' . (int) $r['interval_count'] . ' dias',
        default  => ((int) $r['interval_count'] > 1 ? 'A cada ' . (int) $r['interval_count'] . ' meses' : 'Todo mês') . ' dia ' . (int) $r['day_of_month'],
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0">Recorrências</h1>
    <?php if ($canWrite): ?>
        <div class="d-flex gap-2">
            <form method="post" action="<?= e(route('recurrences.generate')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-outline-secondary" title="Gera as próximas ocorrências agendadas"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Gerar agora</button></form>
            <a class="btn btn-primary" href="<?= e(route('recurrences.create')) ?>"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nova</a>
        </div>
    <?php endif; ?>
</div>
<p class="text-body-secondary">Contas fixas, assinaturas e receitas periódicas viram lançamentos <strong>agendados</strong> com antecedência. Quando o dinheiro sai ou entra de verdade, confirme aqui (ajustando o valor se precisar) ou marque como pago em Lançamentos. Comprometido por mês: <strong><?= e(money($monthlyTotal)) ?></strong>.</p>

<?php if ($events !== []): ?>
    <div class="card nc-card border-primary-subtle mb-3"><div class="card-body">
        <h2 class="h5"><i class="bi bi-calendar-event me-1 nc-goal" aria-hidden="true"></i>Próximos eventos previstos</h2>
        <?php foreach ($events as $ev): ?>
            <div class="d-flex flex-wrap align-items-start gap-2 py-2 border-top">
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($ev['description']) ?> <span class="badge text-bg-primary-subtle text-primary-emphasis"><?= e(date_br($ev['next_date'])) ?> · em <?= (int) $ev['days_until'] ?> dias</span></div>
                    <div class="small text-body-secondary">Valor esperado <?= e(money($ev['expected_amount'])) ?><?= $ev['responsible_user_id'] !== null && isset($members[(int) $ev['responsible_user_id']]) ? ' · ' . e($members[(int) $ev['responsible_user_id']]) : '' ?>. Quando entrar, confirme o valor real abaixo em "A confirmar".</div>
                    <?php if ($ev['suggestions'] !== []): ?>
                        <div class="small mt-1"><span class="text-body-secondary">Sugestão de destino:</span>
                            <ol class="mb-0 ps-3"><?php foreach ($ev['suggestions'] as $s): ?><li><strong><?= e(money($s['amount'])) ?></strong> — <?= e($s['title']) ?> <span class="text-body-secondary">(<?= e($s['detail']) ?>)</span></li><?php endforeach; ?></ol>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h5"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>A confirmar <span class="badge text-bg-secondary"><?= count($pending) ?></span></h2>
            <?php if ($pending === []): ?>
                <p class="text-body-secondary mb-0">Nenhuma ocorrência vencida ou nos próximos 7 dias. 👍</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($pending as $t): $late = $t['date'] < $today; $major = (int) $t['is_major_event'] === 1; ?>
                        <li class="py-2 border-top">
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-nowrap <?= $late ? 'nc-late fw-semibold' : 'nc-due' ?>"><i class="bi bi-<?= $late ? 'exclamation-circle-fill' : 'clock' ?>" aria-hidden="true"></i> <?= e(date_br($t['date'])) ?></span>
                                <span class="text-truncate flex-grow-1"><?= e($t['description']) ?><?php if ((int) $t['auto_debit'] === 1): ?> <span class="badge text-bg-light border">auto</span><?php endif; ?></span>
                                <span class="fw-semibold text-nowrap <?= $t['type'] === 'income' ? 'nc-income' : 'nc-expense' ?>"><?= e(money($t['amount'])) ?></span>
                            </div>
                            <?php if ($canWrite): ?>
                                <div class="d-flex flex-wrap gap-2 mt-1 ms-md-4">
                                    <form method="post" action="<?= e(route('recurrences.confirm', ['id' => $t['id']])) ?>" class="d-flex gap-1 flex-wrap"><?= csrf_field() ?>
                                        <?php if ($major): ?>
                                            <div class="input-group input-group-sm w-auto"><span class="input-group-text">Valor real R$</span><input type="text" inputmode="decimal" class="form-control" name="amount" value="<?= e(money($t['amount'], false)) ?>" data-money aria-label="Valor real" required></div>
                                            <input type="date" class="form-control form-control-sm w-auto" name="date" value="<?= e($today) ?>" aria-label="Data em que entrou">
                                        <?php else: ?>
                                            <input type="hidden" name="date" value="<?= e($late ? $today : $t['date']) ?>">
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= $t['type'] === 'income' ? 'Recebi' : 'Paguei' ?></button>
                                    </form>
                                    <?php if (!$major): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(route('transactions.edit', ['id' => $t['id']])) ?>">Ajustar valor</a><?php endif; ?>
                                    <form method="post" action="<?= e(route('recurrences.skip', ['id' => $t['id']])) ?>" data-confirm="Pular esta ocorrência? Ela vai para a lixeira."><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Pular</button></form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h5"><i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Débito automático</h2>
            <?php if ($autoDebit === []): ?>
                <p class="text-body-secondary mb-0">Cadastre as contas fixas para acompanhar quantas estão em débito automático.</p>
            <?php else: ?>
                <?php foreach ($autoDebit as $st): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small"><span><?= e($st['name']) ?></span><span><?= $st['auto'] ?> de <?= $st['total'] ?> · <strong><?= $st['pct'] ?>%</strong></span></div>
                        <div class="progress" role="progressbar" aria-label="Débito automático de <?= e($st['name']) ?>" aria-valuenow="<?= $st['pct'] ?>" aria-valuemin="0" aria-valuemax="100" data-height="8px"><div class="progress-bar <?= $st['pct'] >= 80 ? 'bg-success' : ($st['pct'] >= 50 ? 'bg-warning' : 'bg-danger') ?>" data-width="<?= $st['pct'] ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
                <p class="small text-body-secondary mb-0">Débito automático evita atraso e juros. Marque na lista abaixo o que já está automático.</p>
            <?php endif; ?>
        </div></div>
    </div>
</div>

<h2 class="h5 mt-4">Regras ativas <span class="badge text-bg-secondary"><?= count($active) ?></span></h2>
<?php if ($active === []): ?>
    <div class="card nc-card"><div class="card-body text-center py-4 text-body-secondary">Nenhuma recorrência. <?php if ($canWrite): ?><a href="<?= e(route('recurrences.create')) ?>">Cadastre a primeira</a> (aluguel, energia, assinaturas, salário…).<?php endif; ?></div></div>
<?php else: ?>
    <div class="card nc-card"><ul class="list-group list-group-flush">
        <?php foreach ($active as $r): $cat = $r['category_id'] !== null ? ($categories[(int) $r['category_id']] ?? null) : null; ?>
            <li class="list-group-item d-flex align-items-center gap-2">
                <span class="nc-cat-dot nc-cat-dot-sm flex-shrink-0" data-bg="<?= e($cat['color'] ?? ($r['kind'] === 'income' ? '#15803d' : '#94a3b8')) ?>"><i class="bi bi-<?= e($cat['icon'] ?? ($r['kind'] === 'income' ? 'cash-coin' : 'tag')) ?>" aria-hidden="true"></i></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-truncate"><?= e($r['description']) ?>
                        <?php if ((int) $r['is_subscription'] === 1): ?><span class="badge text-bg-light border">assinatura</span><?php endif; ?>
                        <?php if ((int) $r['is_major_event'] === 1): ?><span class="badge text-bg-primary-subtle text-primary-emphasis">evento previsto</span><?php endif; ?>
                    </div>
                    <div class="small text-body-secondary text-truncate"><?= e($freqText($r)) ?> · <?= e($cat['full_name'] ?? 'Sem categoria') ?><?= $r['account_id'] !== null && isset($accounts[(int) $r['account_id']]) ? ' · ' . e($accounts[(int) $r['account_id']]) : '' ?><?= $isFamily ? ' · ' . e($r['responsible_user_id'] !== null ? ($members[(int) $r['responsible_user_id']] ?? 'Membro') : 'Todos') : '' ?><?= $r['expected_amount_source'] === 'average' ? ' · média 3 meses' : '' ?><?= $r['next_run_date'] ? ' · próxima ' . e(date_br($r['next_run_date'])) : '' ?></div>
                </div>
                <span class="fw-semibold text-nowrap <?= $r['kind'] === 'income' ? 'nc-income' : 'nc-expense' ?>"><?= e(money($r['expected_amount'])) ?></span>
                <?php if ($canWrite): ?>
                    <?php if ($r['kind'] === 'expense'): ?>
                        <form method="post" action="<?= e(route('recurrences.auto_debit', ['id' => $r['id']])) ?>" class="d-none d-sm-block"><?= csrf_field() ?><button type="submit" class="btn btn-sm <?= (int) $r['auto_debit'] === 1 ? 'btn-success' : 'btn-outline-secondary' ?>" title="<?= (int) $r['auto_debit'] === 1 ? 'Em débito automático' : 'Marcar débito automático' ?>" aria-label="Débito automático de <?= e($r['description']) ?>"><i class="bi bi-lightning-charge<?= (int) $r['auto_debit'] === 1 ? '-fill' : '' ?>" aria-hidden="true"></i></button></form>
                    <?php endif; ?>
                    <div class="dropdown">
                        <button class="btn btn-sm nc-btn-icon" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Ações de <?= e($r['description']) ?>"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= e(route('recurrences.edit', ['id' => $r['id']])) ?>"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Editar</a></li>
                            <li><a class="dropdown-item" href="<?= e(route('transactions.index', [], ['busca' => $r['description'], 'de' => '2000-01-01', 'ate' => '2099-12-31'])) ?>"><i class="bi bi-list-ul me-2" aria-hidden="true"></i>Histórico</a></li>
                            <li><form method="post" action="<?= e(route('recurrences.toggle', ['id' => $r['id']])) ?>"><?= csrf_field() ?><button type="submit" class="dropdown-item"><i class="bi bi-pause-circle me-2" aria-hidden="true"></i>Pausar</button></form></li>
                            <li><form method="post" action="<?= e(route('recurrences.destroy', ['id' => $r['id']])) ?>" data-confirm="Excluir a recorrência &quot;<?= e($r['description']) ?>&quot;?"><?= csrf_field() ?><button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2" aria-hidden="true"></i>Excluir</button></form></li>
                        </ul>
                    </div>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<?php if ($paused !== []): ?>
    <h2 class="h6 mt-4 text-body-secondary">Pausadas / encerradas</h2>
    <div class="card nc-card opacity-75"><ul class="list-group list-group-flush">
        <?php foreach ($paused as $r): ?>
            <li class="list-group-item d-flex align-items-center gap-2">
                <div class="flex-grow-1 min-w-0"><div class="text-truncate"><?= e($r['description']) ?></div><div class="small text-body-secondary"><?= e($freqText($r)) ?><?= $r['end_date'] ? ' · encerrada em ' . e(date_br($r['end_date'])) : '' ?></div></div>
                <span class="text-nowrap text-body-secondary"><?= e(money($r['expected_amount'])) ?></span>
                <?php if ($canWrite): ?>
                    <form method="post" action="<?= e(route('recurrences.toggle', ['id' => $r['id']])) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-primary">Retomar</button></form>
                    <form method="post" action="<?= e(route('recurrences.destroy', ['id' => $r['id']])) ?>" data-confirm="Excluir?"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Excluir <?= e($r['description']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul></div>
<?php endif; ?>
