<?php
// app/Views/simulator/index.php — simulador "e se": cortes por categoria e cancelamento de assinaturas
/** @var array<string,mixed> $baseline */
/** @var array<int,float> $cuts */
/** @var list<int> $cancel */
/** @var array<string,mixed>|null $result */
?>
<h1 class="h3 mb-2">Simulador "e se"</h1>
<p class="text-body-secondary">Ponto de partida: média <?= $baseline['months_used'] === 3 ? 'dos últimos 3 meses' : 'do mês atual' ?> — receitas <strong class="nc-income"><?= e(money($baseline['income'])) ?></strong>, despesas <strong class="nc-expense"><?= e(money($baseline['expense'])) ?></strong> (essenciais <?= e(money($baseline['essential'])) ?>), sobra <strong class="<?= $baseline['surplus'] < 0 ? 'nc-expense' : 'nc-income' ?>"><?= e(money($baseline['surplus'])) ?>/mês</strong>. Reserva atual (poupança + investimentos): <strong><?= e(money($baseline['reserve'])) ?></strong>.</p>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <form method="get" action="<?= e(route('simulator.index')) ?>" class="card nc-card"><div class="card-body">
            <h2 class="h5"><i class="bi bi-scissors me-1" aria-hidden="true"></i>O que você cortaria?</h2>
            <?php if ($baseline['categories'] === [] && $baseline['subscriptions'] === []): ?>
                <p class="text-body-secondary mb-0">Registre alguns meses de lançamentos para simular cortes.</p>
            <?php else: ?>
                <?php if ($baseline['categories'] !== []): ?>
                    <p class="small text-body-secondary mb-1">Corte mensal por categoria (média atual entre parênteses):</p>
                    <?php foreach (array_slice($baseline['categories'], 0, 12) as $c): ?>
                        <div class="input-group input-group-sm mb-1">
                            <label class="input-group-text w-50 text-truncate d-block" for="corte_<?= (int) $c['id'] ?>" title="<?= e($c['name']) ?>"><?= e($c['name']) ?> <span class="text-body-secondary">(<?= e(money($c['monthly'])) ?>)</span></label>
                            <span class="input-group-text">R$</span>
                            <input type="text" inputmode="decimal" class="form-control" id="corte_<?= (int) $c['id'] ?>" name="corte[<?= (int) $c['id'] ?>]" value="<?= isset($cuts[(int) $c['id']]) ? e(money($cuts[(int) $c['id']], false)) : '' ?>" data-money placeholder="0,00">
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($baseline['subscriptions'] !== []): ?>
                    <p class="small text-body-secondary mb-1 mt-3">Assinaturas que você cancelaria:</p>
                    <?php foreach ($baseline['subscriptions'] as $s): ?>
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="cancelar[]" value="<?= (int) $s['id'] ?>" id="cancel_<?= (int) $s['id'] ?>" <?= in_array($s['id'], $cancel, true) ? 'checked' : '' ?>><label class="form-check-label" for="cancel_<?= (int) $s['id'] ?>"><?= e($s['description']) ?> <span class="text-body-secondary small">(<?= e(money($s['monthly'])) ?>/mês)</span></label></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="d-flex justify-content-between mt-3">
                    <a class="btn btn-outline-secondary" href="<?= e(route('simulator.index')) ?>">Limpar</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-calculator me-1" aria-hidden="true"></i>Simular</button>
                </div>
            <?php endif; ?>
        </div></form>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card nc-card h-100"><div class="card-body">
            <h2 class="h5"><i class="bi bi-graph-up-arrow me-1" aria-hidden="true"></i>Resultado</h2>
            <?php if ($result === null): ?>
                <p class="text-body-secondary mb-0">Escolha cortes ou assinaturas e toque em Simular.</p>
            <?php elseif ($result['monthly_saving'] <= 0): ?>
                <p class="text-body-secondary mb-0">Nenhum corte informado.</p>
            <?php else: ?>
                <ul class="small mb-2"><?php foreach ($result['lines'] as $l): ?><li><?= e($l['label']) ?><?= $l['capped'] ? ' <span class="text-body-secondary">(limitado à média da categoria)</span>' : '' ?></li><?php endforeach; ?></ul>
                <div class="fs-4 fw-semibold nc-income">+ <?= e(money($result['monthly_saving'])) ?><span class="fs-6 fw-normal text-body-secondary">/mês</span></div>
                <div class="small text-body-secondary mb-3">Sobra mensal passa de <?= e(money($result['surplus_now'])) ?> para <strong><?= e(money($result['surplus_new'])) ?></strong>.</div>
                <table class="table table-sm small">
                    <thead><tr><th></th><th class="text-end">6 meses</th><th class="text-end">12 meses</th></tr></thead>
                    <tbody>
                        <tr><td>Economia extra acumulada</td><td class="text-end nc-income"><?= e(money($result['horizons'][6]['saved_extra'])) ?></td><td class="text-end nc-income"><?= e(money($result['horizons'][12]['saved_extra'])) ?></td></tr>
                        <tr><td>Sobra total (hoje → com cortes)</td><td class="text-end"><?= e(money($result['horizons'][6]['surplus_now'])) ?> → <strong><?= e(money($result['horizons'][6]['surplus_new'])) ?></strong></td><td class="text-end"><?= e(money($result['horizons'][12]['surplus_now'])) ?> → <strong><?= e(money($result['horizons'][12]['surplus_new'])) ?></strong></td></tr>
                        <tr><td>Reserva em meses cobertos</td><td class="text-end"><?= e((string) $result['horizons'][6]['reserve_now']) ?> → <strong><?= e((string) $result['horizons'][6]['reserve_new']) ?></strong></td><td class="text-end"><?= e((string) $result['horizons'][12]['reserve_now']) ?> → <strong><?= e((string) $result['horizons'][12]['reserve_new']) ?></strong></td></tr>
                    </tbody>
                </table>
                <p class="small text-body-secondary">Hoje a reserva cobre <strong><?= e((string) $result['reserve_months']) ?></strong> mês(es) de gastos essenciais. Meses cobertos = (reserva + sobra acumulada) ÷ gastos essenciais mensais.</p>
                <?php if ($result['goal'] !== null): ?>
                    <div class="alert alert-light border small mb-0">
                        <strong><?= e($result['goal']['name']) ?></strong> (faltam <?= e(money($result['goal']['gap'])) ?>):
                        <?php if ($result['goal']['gap'] <= 0): ?>já batida.
                        <?php elseif ($result['goal']['months_new'] === null): ?>mesmo com os cortes a sobra mensal continua negativa; não dá para prever.
                        <?php else: ?>de <?= $result['goal']['months_now'] === null ? 'nunca (sobra negativa)' : (int) $result['goal']['months_now'] . ' mês(es)' ?> para <strong><?= (int) $result['goal']['months_new'] ?> mês(es)</strong> guardando toda a sobra.<?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div></div>
    </div>
</div>
