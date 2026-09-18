<?php
// app/Views/dashboard/index.php — painel com os 11 indicadores (§6.4), filtro por mês e por membro, gráficos Chart.js
/** @var array<string,mixed> $household */
/** @var array<int,array<string,mixed>> $members */
/** @var int|null $memberId */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<string,mixed> $insights */
/** @var string $month */
/** @var string $monthLabel */
/** @var string $prevMonth */
/** @var string $nextMonth */
/** @var bool $isCurrent */
/** @var string $today */
/** @var string $stage */
/** @var bool $canWrite */
/** @var bool $isFamily */
/** @var array<int,array<string,mixed>> $categories */
$m = $insights['month'];
$score = $insights['score'];
$scoreClass = $score['total'] >= 60 ? 'nc-score-green' : ($score['total'] >= 40 ? 'nc-score-yellow' : 'nc-score-red');
$q = static fn(array $over): array => array_filter(['mes' => substr($month, 0, 7), 'membro' => $memberId] + $over, static fn($v): bool => $v !== null && $v !== '');
$chartData = ['series' => $insights['series'], 'by_category' => $insights['by_category'], 'by_member' => $isFamily ? $insights['by_member'] : []];
$statusBar = ['ok' => '', 'risk' => 'bg-info', 'warn' => 'bg-warning', 'over' => 'bg-danger'];
$urgencyClass = ['late' => 'nc-late', 'today' => 'nc-due', 'soon' => 'text-body-secondary'];
\App\Core\View::push('scripts', '<script src="' . e(asset('assets/vendor/chart.umd.js')) . '" nonce="' . e(nonce()) . '"></script><script type="application/json" id="dashboardData" nonce="' . e(nonce()) . '">' . json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script><script src="' . e(asset('assets/js/dashboard.js')) . '" nonce="' . e(nonce()) . '"></script>');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <div>
        <h1 class="h3 mb-0">Olá, <?= e(auth_user()['name'] ?? '') ?></h1>
        <span class="text-body-secondary small"><?= e($household['name']) ?></span>
    </div>
    <?php if ($canWrite): ?>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-danger" href="<?= e(route('transactions.create', [], ['tipo' => 'expense'])) ?>"><i class="bi bi-dash-circle me-1" aria-hidden="true"></i>Gasto</a>
            <a class="btn btn-outline-success" href="<?= e(route('transactions.create', [], ['tipo' => 'income'])) ?>"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Receita</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($stage !== 'done'): ?>
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div><i class="bi bi-magic me-1" aria-hidden="true"></i>Falta pouco: conclua a configuração inicial (contas, moeda e avisos).</div>
        <a class="btn btn-sm btn-primary" href="<?= e(route($stage === 'notifications' ? 'onboarding.notifications' : 'onboarding.setup')) ?>">Continuar</a>
    </div>
<?php endif; ?>

<form method="get" action="<?= e(route('dashboard')) ?>" class="d-flex flex-wrap align-items-center gap-2 mb-3 nc-period">
    <a class="btn btn-sm nc-btn-icon" href="<?= e(route('dashboard', [], $q(['mes' => $prevMonth]))) ?>" aria-label="Mês anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
    <span class="fw-semibold fs-5"><?= e($monthLabel) ?></span>
    <a class="btn btn-sm nc-btn-icon" href="<?= e(route('dashboard', [], $q(['mes' => $nextMonth]))) ?>" aria-label="Próximo mês"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    <input type="hidden" name="mes" value="<?= e(substr($month, 0, 7)) ?>">
    <?php if ($isFamily): ?>
        <select class="form-select form-select-sm w-auto ms-auto" name="membro" aria-label="Filtrar por membro" data-autosubmit>
            <option value="">Lar inteiro</option>
            <?php foreach ($members as $mb): ?><option value="<?= (int) $mb['user_id'] ?>" <?= $memberId === (int) $mb['user_id'] ? 'selected' : '' ?>><?= e($mb['name']) ?></option><?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Filtrar</button></noscript>
    <?php endif; ?>
</form>

<!-- 1) Saldo do mês e projetado · 6) poupança e idade do dinheiro · 7) supérfluo -->
<div class="row g-2 g-md-3 mb-3 nc-kpi">
    <div class="col-6 col-lg-3"><div class="card nc-card h-100"><div class="card-body py-3">
        <div class="small text-body-secondary">Saldo do mês</div>
        <div class="fs-4 fw-semibold <?= $m['balance'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($m['balance'])) ?></div>
        <div class="small text-body-secondary"><?= e(money($m['income'])) ?> receitas · <?= e(money($m['expense'])) ?> despesas</div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card nc-card h-100"><div class="card-body py-3">
        <div class="small text-body-secondary">Projetado até o fim do mês</div>
        <div class="fs-4 fw-semibold <?= $m['projected'] < 0 ? 'nc-expense' : 'nc-goal' ?>"><?= e(money($m['projected'])) ?></div>
        <div class="small text-body-secondary">inclui pendentes e agendados<?= $m['pending_expense'] > 0 ? ' · ' . e(money($m['pending_expense'])) . ' a pagar' : '' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card nc-card h-100"><div class="card-body py-3">
        <div class="small text-body-secondary">Taxa de poupança</div>
        <div class="fs-4 fw-semibold <?= $insights['savings_rate'] === null ? 'text-body-secondary' : ($insights['savings_rate'] >= 20 ? 'nc-income' : ($insights['savings_rate'] >= 0 ? 'nc-due' : 'nc-expense')) ?>"><?= $insights['savings_rate'] === null ? '—' : (int) $insights['savings_rate'] . '%' ?></div>
        <div class="small text-body-secondary">idade do dinheiro: <strong><?= $insights['age_of_money']['days'] === null ? '—' : (int) $insights['age_of_money']['days'] . ' dias' ?></strong> <i class="bi bi-info-circle" title="Quantos dias de gasto médio o dinheiro em conta cobre (saldo líquido ÷ gasto diário dos últimos 90 dias)." aria-label="Explicação"></i></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card nc-card h-100"><div class="card-body py-3">
        <div class="small text-body-secondary">Gasto supérfluo do mês</div>
        <div class="fs-4 fw-semibold nc-due"><?= e(money($insights['superfluous']['amount'])) ?></div>
        <div class="small text-body-secondary"><?= (int) $insights['superfluous']['share'] ?>% das despesas · <a href="<?= e(route('budgets.index', [], ['mes' => substr($month, 0, 7)])) ?>">ver 50/30/20</a></div>
    </div></div></div>
</div>

<div class="row g-3">
    <!-- 2) Receitas × despesas 12 meses -->
    <div class="col-12 col-xl-8">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h6 mb-2"><i class="bi bi-bar-chart me-1" aria-hidden="true"></i>Receitas × despesas · 12 meses</h2>
            <div class="nc-chart nc-chart-lg"><canvas id="chartSeries" role="img" aria-label="Receitas e despesas por mês nos últimos 12 meses"></canvas></div>
            <details class="small mt-2"><summary class="text-body-secondary">Ver como tabela</summary>
                <div class="table-responsive"><table class="table table-sm mt-1"><thead><tr><th>Mês</th><th class="text-end">Receitas</th><th class="text-end">Despesas</th><th class="text-end">Sobra</th></tr></thead><tbody>
                    <?php foreach ($insights['series'] as $s): ?><tr><td><?= e($s['label']) ?></td><td class="text-end"><?= e(money($s['income'])) ?></td><td class="text-end"><?= e(money($s['expense'])) ?></td><td class="text-end"><?= e(money($s['income'] - $s['expense'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </details>
        </div></div>
    </div>
    <!-- 11) Score -->
    <div class="col-12 col-xl-4">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h6 mb-2"><i class="bi bi-heart-pulse me-1" aria-hidden="true"></i>Saúde financeira</h2>
            <div class="d-flex align-items-center gap-3">
                <div class="nc-score <?= e($scoreClass) ?>" role="img" aria-label="Score <?= (int) $score['total'] ?> de 100"><?= (int) $score['total'] ?><small>de 100</small></div>
                <div><div class="fw-semibold"><?= e($score['level']) ?></div><div class="small text-body-secondary">Como é calculado: cada item abaixo vale uma parte dos 100 pontos.</div></div>
            </div>
            <ul class="list-unstyled small mt-2 mb-0">
                <?php foreach ($score['items'] as $it): ?>
                    <li class="d-flex gap-2 py-1 border-top"><span class="text-nowrap fw-semibold" data-color="<?= $it['points'] >= $it['max'] * 0.7 ? '#15803d' : ($it['points'] >= $it['max'] * 0.4 ? '#ca8a04' : '#b91c1c') ?>"><?= (int) $it['points'] ?>/<?= (int) $it['max'] ?></span><span><strong><?= e($it['label']) ?>:</strong> <?= e($it['detail']) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div></div>
    </div>

    <!-- 3) Despesas por categoria e por membro -->
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h6 mb-2"><i class="bi bi-tags me-1" aria-hidden="true"></i>Despesas por categoria</h2>
            <?php if ($insights['by_category'] === []): ?><p class="text-body-secondary small mb-0">Sem despesas no mês.</p><?php else: ?>
                <div class="nc-chart"><canvas id="chartCategories" role="img" aria-label="Despesas por categoria"></canvas></div>
                <ul class="list-unstyled small mt-2 mb-0">
                    <?php foreach ($insights['by_category'] as $c): ?><li class="d-flex justify-content-between gap-2 py-1 border-top"><span><span class="nc-cat-dot nc-cat-dot-sm me-1" data-bg="<?= e($c['color']) ?>"><i class="bi bi-<?= e($c['icon']) ?>" aria-hidden="true"></i></span><?= e($c['name']) ?></span><span class="text-nowrap"><?= e(money($c['amount'])) ?> <span class="text-body-secondary">(<?= (int) $c['pct'] ?>%)</span></span></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100"><div class="card-body">
            <?php if ($isFamily): ?>
                <h2 class="h6 mb-2"><i class="bi bi-people me-1" aria-hidden="true"></i>Despesas por membro</h2>
                <?php if ($insights['by_member'] === []): ?><p class="text-body-secondary small mb-0">Sem despesas no mês.</p><?php else: ?>
                    <div class="nc-chart nc-chart-sm"><canvas id="chartMembers" role="img" aria-label="Despesas por membro"></canvas></div>
                    <ul class="list-unstyled small mt-2 mb-0">
                        <?php foreach ($insights['by_member'] as $mb): ?><li class="d-flex justify-content-between gap-2 py-1 border-top"><span><span class="nc-avatar nc-avatar-sm me-1" data-bg="<?= e($mb['color']) ?>"><?= $mb['user_id'] === null ? '<i class="bi bi-people" aria-hidden="true"></i>' : e(initials($mb['name'])) ?></span><?= e($mb['name']) ?></span><span class="text-nowrap"><?= e(money($mb['amount'])) ?> <span class="text-body-secondary">(<?= (int) $mb['pct'] ?>%)</span></span></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php else: ?>
                <h2 class="h6 mb-2"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Contas e cartões</h2>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($accounts as $a): $isCard = $a['type'] === 'credit_card'; ?><li class="d-flex justify-content-between gap-2 py-1 border-top"><span><i class="bi bi-<?= e($a['icon'] ?: 'bank') ?> me-1 text-body-secondary" aria-hidden="true"></i><?= e($a['name']) ?></span><span class="text-nowrap fw-semibold"><?= $isCard ? 'fatura ' . e(money(abs((float) $a['projected']))) : e(money($a['balance'])) ?></span></li><?php endforeach; ?>
                </ul>
                <a class="small" href="<?= e(route('accounts.index')) ?>">Gerenciar contas</a>
            <?php endif; ?>
        </div></div>
    </div>

    <!-- 4) Contas a vencer · 5) orçamentos em risco -->
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h6 mb-2"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>A vencer nos próximos 7 dias <?php if ($insights['overdue'] > 0): ?><span class="badge text-bg-danger"><?= (int) $insights['overdue'] ?> atrasada(s)</span><?php endif; ?></h2>
            <?php if ($insights['upcoming'] === []): ?>
                <p class="text-body-secondary small mb-0">Nada pendente até <?= e(date_br((new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'))) ?>. 🎉</p>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($insights['upcoming'] as $t): ?>
                        <li class="d-flex align-items-center gap-2 py-1 border-top">
                            <span class="text-nowrap <?= e($urgencyClass[$t['urgency']]) ?> <?= $t['urgency'] === 'late' ? 'fw-semibold' : '' ?>"><i class="bi bi-<?= $t['urgency'] === 'late' ? 'exclamation-circle-fill' : ($t['urgency'] === 'today' ? 'alarm-fill' : 'clock') ?>" aria-hidden="true"></i> <?= $t['urgency'] === 'today' ? 'hoje' : e(date_br($t['date'])) ?></span>
                            <span class="text-truncate flex-grow-1"><?= e($t['description']) ?><?= $t['recurring_id'] !== null ? ' <i class="bi bi-arrow-repeat text-body-secondary" title="Recorrente" aria-hidden="true"></i>' : '' ?></span>
                            <span class="fw-semibold nc-expense text-nowrap"><?= e(money($t['amount'])) ?></span>
                            <?php if ($canWrite && empty($t['masked'])): ?><form method="post" action="<?= e(route('transactions.status', ['id' => $t['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="paid"><button type="submit" class="btn btn-sm btn-outline-success py-0" title="Marcar como pago" aria-label="Marcar <?= e($t['description']) ?> como pago"><i class="bi bi-check-lg" aria-hidden="true"></i></button></form><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-pie-chart me-1" aria-hidden="true"></i>Orçamentos mais perto de estourar</h2><a class="small" href="<?= e(route('budgets.index', [], ['mes' => substr($month, 0, 7)])) ?>">Orçamento</a></div>
            <?php if ($insights['budgets'] === []): ?>
                <p class="text-body-secondary small mb-0">Nenhum limite neste mês. <a href="<?= e(route('budgets.index', [], ['mes' => substr($month, 0, 7)])) ?>">Defina o primeiro</a>.</p>
            <?php else: ?>
                <?php foreach ($insights['budgets'] as $b): ?>
                    <div class="small py-1">
                        <div class="d-flex justify-content-between"><span class="text-truncate"><?= e($b['category_name']) ?><?= $b['user_name'] ? ' <span class="text-body-secondary">(' . e($b['user_name']) . ')</span>' : '' ?></span><span class="text-nowrap"><strong class="<?= $b['status'] === 'over' ? 'nc-late' : ($b['status'] === 'warn' ? 'nc-due' : '') ?>"><?= (int) $b['pct'] ?>%</strong> · <?= e(money($b['spent'])) ?> de <?= e(money($b['limit_amount'])) ?></span></div>
                        <div class="progress" role="progressbar" aria-label="<?= e($b['category_name']) ?>" aria-valuenow="<?= min(100, (int) $b['pct']) ?>" aria-valuemin="0" aria-valuemax="100" data-height="6px"><div class="progress-bar <?= e($statusBar[$b['status']]) ?>" data-width="<?= min(100, (int) $b['pct']) ?>%"></div></div>
                        <?php if ($b['burst_day'] !== null): ?><div class="text-body-secondary">neste ritmo estoura no dia <?= (int) $b['burst_day'] ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div></div>
    </div>

    <!-- 8) Metas e plano · 9) radar · 10) eventos -->
    <div class="col-12 col-lg-4">
        <div class="card nc-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-flag me-1" aria-hidden="true"></i>Metas e plano de ação</h2><a class="small" href="<?= e(route('goals.index')) ?>">Metas</a></div>
            <?php if ($insights['goals'] === []): ?><p class="text-body-secondary small mb-2">Nenhuma meta ativa.</p><?php else: ?>
                <?php foreach ($insights['goals'] as $g): ?>
                    <div class="small py-1">
                        <div class="d-flex justify-content-between"><span class="text-truncate"><?= e($g['name']) ?></span><span class="text-nowrap"><?= (int) $g['pct'] ?>% · <?= e(money($g['saved'])) ?></span></div>
                        <div class="progress" role="progressbar" aria-label="<?= e($g['name']) ?>" aria-valuenow="<?= (int) $g['pct'] ?>" aria-valuemin="0" aria-valuemax="100" data-height="6px"><div class="progress-bar bg-success" data-width="<?= (int) $g['pct'] ?>%"></div></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div class="small border-top pt-2 mt-2 d-flex justify-content-between"><span>Plano de ação: <strong><?= (int) $insights['plan']['done'] ?>/<?= (int) $insights['plan']['total'] ?></strong> feitas</span><a href="<?= e(route('savings.index')) ?>">estimado <?= e(money($insights['plan']['estimated'])) ?>/mês</a></div>
            <?php if ($insights['plan']['measured'] != 0): ?><div class="small text-body-secondary">realizado neste mês: <span class="<?= $insights['plan']['measured'] >= 0 ? 'nc-income' : 'nc-expense' ?>"><?= e(money($insights['plan']['measured'])) ?></span></div><?php endif; ?>
        </div></div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card nc-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-broadcast me-1" aria-hidden="true"></i>Radar de assinaturas</h2><a class="small" href="<?= e(route('subscriptions.index')) ?>">Ver radar</a></div>
            <div class="fs-4 fw-semibold nc-expense"><?= e(money($insights['radar']['monthly_total'])) ?><span class="fs-6 fw-normal text-body-secondary">/mês</span></div>
            <div class="small text-body-secondary"><?= (int) $insights['radar']['count'] ?> assinatura(s) · <?= e(money($insights['radar']['monthly_total'] * 12)) ?> por ano<?= $insights['radar']['detected'] > 0 ? ' · ' . (int) $insights['radar']['detected'] . ' detectada(s) sem cadastro' : '' ?></div>
            <div class="small mt-2"><?php if ($insights['radar']['alerts'] > 0): ?><span class="badge text-bg-danger"><?= (int) $insights['radar']['alerts'] ?> alerta(s)</span> duplicidade ou aumento de preço<?php else: ?><span class="badge text-bg-success">sem alertas</span><?php endif; ?></div>
            <div class="small border-top pt-2 mt-2">Reserva: <strong><?= e(money($insights['reserve']['amount'])) ?></strong> · cobre <strong><?= e(number_format($insights['reserve']['months'], 1, ',', '.')) ?></strong> mês(es) de gastos essenciais</div>
        </div></div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card nc-card h-100"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2"><h2 class="h6 mb-0"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Próximos eventos previstos</h2><a class="small" href="<?= e(route('recurrences.index')) ?>">Recorrências</a></div>
            <?php if ($insights['events'] === []): ?>
                <p class="text-body-secondary small mb-0">Nenhum evento grande nos próximos 120 dias. Marque "evento previsto" em recorrências como PLR, 13º ou IPVA.</p>
            <?php else: ?>
                <ul class="list-unstyled small mb-0">
                    <?php foreach ($insights['events'] as $ev): ?><li class="py-1 border-top"><div class="d-flex justify-content-between"><span><?= e($ev['description']) ?></span><span class="text-nowrap <?= $ev['kind'] === 'income' ? 'nc-income' : 'nc-expense' ?>"><?= e(money($ev['expected_amount'])) ?></span></div><div class="text-body-secondary"><?= e(date_br($ev['next_date'])) ?> · em <?= (int) $ev['days_until'] ?> dias</div></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div></div>
    </div>
</div>
