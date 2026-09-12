<?php
// app/Views/errors/419.php
/** @var int $status */
/** @var string $message */
/** @var array<string,string>|null $debug */
?>
<div class="text-center py-5 nc-maxw-md mx-auto">
    <i class="bi bi-hourglass-split display-3 text-body-secondary" aria-hidden="true"></i>
    <h1 class="h3 mt-3">Sessão expirada</h1>
    <p class="text-body-secondary"><?= e($message) ?></p>
    <a class="btn btn-primary" href="<?= e(route('home')) ?>"><i class="bi bi-house me-1" aria-hidden="true"></i>Voltar ao início</a>
    <?php if (!empty($debug)): ?>
        <div class="alert alert-danger text-start mt-4 small">
            <div><strong><?= e($debug['exception']) ?></strong>: <?= e($debug['detail']) ?></div>
            <div class="text-body-secondary"><?= e($debug['file']) ?></div>
            <pre class="mt-2 mb-0 nc-pre"><?= e($debug['trace']) ?></pre>
        </div>
    <?php endif; ?>
</div>
