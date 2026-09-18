<?php
// app/Views/reports/index.php — hub dos relatórios
/** @var string $month */
/** @var int $year */
/** @var array<int,array<string,mixed>> $members */
/** @var array<int,array<string,mixed>> $accounts */
/** @var array<int,array<string,mixed>> $categories */
/** @var bool $isFamily */
?>
<h1 class="h3 mb-2">Relatórios</h1>
<p class="text-body-secondary">Cada relatório abre na tela e pode ser baixado em <strong>CSV</strong> (abre no Excel/LibreOffice) ou <strong>PDF</strong>.</p>
<div class="row g-3">
    <div class="col-12 col-md-6 col-xl-4"><form method="get" action="<?= e(route('reports.monthly')) ?>" class="card nc-card h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>Mensal por categoria</h2>
        <p class="small text-body-secondary">Despesas e receitas do mês, comparadas com o mês anterior e com o mesmo mês do ano passado; ranking de onde o dinheiro mais cresceu.</p>
        <div class="d-flex gap-2"><input type="month" class="form-control form-control-sm" name="mes" value="<?= e($month) ?>" aria-label="Mês"><?php if ($isFamily): ?><select class="form-select form-select-sm" name="membro" aria-label="Membro"><option value="">Lar inteiro</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select><?php endif; ?><button type="submit" class="btn btn-sm btn-primary">Abrir</button></div>
    </div></form></div>
    <div class="col-12 col-md-6 col-xl-4"><form method="get" action="<?= e(route('reports.annual')) ?>" class="card nc-card h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i>Anual</h2>
        <p class="small text-body-secondary">Receitas, despesas, saldo e taxa de poupança mês a mês; melhor e pior mês; comparação ano a ano.</p>
        <div class="d-flex gap-2"><input type="number" min="2000" max="2100" class="form-control form-control-sm" name="ano" value="<?= $year ?>" aria-label="Ano"><?php if ($isFamily): ?><select class="form-select form-select-sm" name="membro" aria-label="Membro"><option value="">Lar inteiro</option><?php foreach ($members as $m): ?><option value="<?= (int) $m['user_id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select><?php endif; ?><button type="submit" class="btn btn-sm btn-primary">Abrir</button></div>
    </div></form></div>
    <?php if ($isFamily): ?>
    <div class="col-12 col-md-6 col-xl-4"><form method="get" action="<?= e(route('reports.members')) ?>" class="card nc-card h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-people me-1" aria-hidden="true"></i>Por membro</h2>
        <p class="small text-body-secondary">Quanto cada um recebeu e gastou no mês, parte nas despesas e maiores categorias.</p>
        <div class="d-flex gap-2"><input type="month" class="form-control form-control-sm" name="mes" value="<?= e($month) ?>" aria-label="Mês"><button type="submit" class="btn btn-sm btn-primary">Abrir</button></div>
    </div></form></div>
    <?php endif; ?>
    <div class="col-12 col-md-6 col-xl-4"><form method="get" action="<?= e(route('reports.category')) ?>" class="card nc-card h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-tag me-1" aria-hidden="true"></i>Por categoria</h2>
        <p class="small text-body-secondary">Evolução de uma categoria nos últimos 12 meses e os lançamentos do mês escolhido.</p>
        <div class="d-flex gap-2"><select class="form-select form-select-sm" name="categoria" aria-label="Categoria"><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= $c['depth'] === 1 ? '— ' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?></select><input type="month" class="form-control form-control-sm" name="mes" value="<?= e($month) ?>" aria-label="Mês"><button type="submit" class="btn btn-sm btn-primary">Abrir</button></div>
    </div></form></div>
    <div class="col-12 col-md-6 col-xl-4"><form method="get" action="<?= e(route('reports.account')) ?>" class="card nc-card h-100"><div class="card-body">
        <h2 class="h5"><i class="bi bi-credit-card me-1" aria-hidden="true"></i>Conta ou cartão (fatura)</h2>
        <p class="small text-body-secondary">Extrato com saldo inicial e final; para cartões, a fatura pelo dia de fechamento.</p>
        <div class="d-flex gap-2"><select class="form-select form-select-sm" name="conta" aria-label="Conta"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?><?= (int) $a['is_active'] !== 1 ? ' (arquivada)' : '' ?></option><?php endforeach; ?></select><input type="month" class="form-control form-control-sm" name="mes" value="<?= e($month) ?>" aria-label="Mês"><button type="submit" class="btn btn-sm btn-primary">Abrir</button></div>
    </div></form></div>
</div>
