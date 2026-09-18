<?php
// app/Views/system/migrate.php
/** @var array<int,array{version:int,name:string,file:string}> $pending */
/** @var list<string>|null $applied */
/** @var int $current */
/** @var int $target */
/** @var string $token */
?>
<div class="nc-maxw-md mx-auto">
    <h1 class="h3 mb-3"><i class="bi bi-database-gear me-2" aria-hidden="true"></i>Atualização do banco</h1>
    <?php if ($applied !== null): ?>
        <div class="alert alert-success">Aplicadas: <?= $applied === [] ? 'nenhuma (já estava atualizado)' : e(implode(', ', $applied)) ?>.</div>
    <?php endif; ?>
    <p>Banco na versão <strong><?= (int) $current ?></strong>; o código espera a versão <strong><?= (int) $target ?></strong>.</p>
    <?php if ($pending === []): ?>
        <div class="alert alert-info">Nenhuma migração pendente.</div>
    <?php else: ?>
        <ul>
            <?php foreach ($pending as $m): ?><li><?= e(sprintf('%03d', $m['version'])) ?> — <?= e($m['name']) ?></li><?php endforeach; ?>
        </ul>
        <form method="post" action="<?= e(route('system.migrate', [], ['token' => $token])) ?>" data-once>
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary">Aplicar migrações</button>
        </form>
        <p class="small text-body-secondary mt-2">Faça um backup no phpMyAdmin antes, se o banco já tiver dados importantes.</p>
    <?php endif; ?>
    <a class="btn btn-outline-secondary mt-3" href="<?= e(route('system.health')) ?>">Voltar à verificação de saúde</a>
</div>
