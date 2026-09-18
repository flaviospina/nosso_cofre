<?php
// app/Views/account/activity.php — "Minha atividade" (log de auditoria do próprio usuário)
/** @var array<int,array<string,mixed>> $entries */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'account.activity']) ?>
    <h1 class="h3 mb-1">Minha atividade</h1>
    <p class="text-body-secondary">Tudo que foi feito com a sua conta (<?= (int) $total ?> registros). Este log é seu e faz parte do direito de acesso previsto na LGPD.</p>
    <?php if ($entries === []): ?>
        <p>Nenhuma atividade registrada ainda.</p>
    <?php else: ?>
        <div class="list-group nc-card">
            <?php foreach ($entries as $entry): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between gap-2">
                        <strong><?= e(\App\Services\AuditService::describe((string) $entry['action'])) ?></strong>
                        <span class="small text-body-secondary text-nowrap"><?= e(datetime_br($entry['created_at'])) ?></span>
                    </div>
                    <div class="small text-body-secondary">
                        IP <?= e($entry['ip']) ?>
                        <?php if (!empty($entry['after_data']) && is_array($entry['after_data'])): ?>
                            · <?= e(implode(', ', array_map(static fn($k, $v) => $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)), array_keys($entry['after_data']), $entry['after_data']))) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="mt-3" aria-label="Páginas">
                <ul class="pagination justify-content-center">
                    <?php for ($p = 1; $p <= $pages; $p++): ?>
                        <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= e(route('account.activity', [], ['pagina' => $p])) ?>"><?= $p ?></a></li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
