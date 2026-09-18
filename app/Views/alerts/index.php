<?php
// app/Views/alerts/index.php — Central de avisos
/** @var list<array<string,mixed>> $alerts */ /** @var string|null $type */ /** @var array<string,array<string,mixed>> $types */
/** @var array<string,string> $muted */ /** @var int $unread */
$sevClass = ['info' => 'text-bg-primary-subtle text-primary-emphasis', 'success' => 'text-bg-success-subtle text-success-emphasis', 'warning' => 'text-bg-warning-subtle text-warning-emphasis', 'danger' => 'text-bg-danger-subtle text-danger-emphasis'];
?>
<div class="nc-maxw-md mx-auto" data-alerts-page>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h1 class="h3 mb-0">Avisos <?php if ($unread > 0): ?><span class="badge text-bg-danger"><?= $unread ?> novo(s)</span><?php endif; ?></h1>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(route('notifications.index')) ?>"><i class="bi bi-gear me-1" aria-hidden="true"></i>Configurar</a>
            <?php if ($unread > 0): ?><form method="post" action="<?= e(route('alerts.read_all')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-primary">Marcar todos como lidos</button></form><?php endif; ?>
        </div>
    </div>
    <div class="nc-pills overflow-auto mb-3">
        <a class="btn btn-sm <?= $type === null ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-pill me-1" href="<?= e(route('alerts.index')) ?>">Todos</a>
        <?php foreach ($types as $k => $m): ?><a class="btn btn-sm <?= $type === $k ? 'btn-primary' : 'btn-outline-secondary' ?> rounded-pill me-1" href="<?= e(route('alerts.index', [], ['tipo' => $k])) ?>"><?= e($m['label']) ?></a><?php endforeach; ?>
    </div>
    <?php if ($muted !== []): ?>
        <div class="alert alert-light border small">Silenciados por 7 dias: <?php foreach ($muted as $k => $until): ?><span class="me-2"><?= e($types[$k]['label'] ?? $k) ?> (até <?= e(datetime_br($until)) ?>) <form method="post" action="<?= e(route('alerts.unmute', ['type' => $k])) ?>" class="d-inline"><?= csrf_field() ?><button type="submit" class="btn btn-link btn-sm p-0 align-baseline">reativar</button></form></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($type !== null && !isset($muted[$type])): ?>
        <form method="post" action="<?= e(route('alerts.mute', ['type' => $type])) ?>" class="mb-3"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bell-slash me-1" aria-hidden="true"></i>Silenciar "<?= e($types[$type]['label']) ?>" por 7 dias</button></form>
    <?php endif; ?>
    <?php if ($alerts === []): ?>
        <div class="card nc-card"><div class="card-body text-center py-5"><i class="bi bi-bell fs-1 text-body-secondary" aria-hidden="true"></i><p class="mt-2 mb-0">Nenhum aviso<?= $type !== null ? ' deste tipo' : '' ?> ainda. Ligue os tipos que quer receber em <a href="<?= e(route('notifications.index')) ?>">Notificações</a>.</p></div></div>
    <?php else: ?>
        <div class="card nc-card"><ul class="list-group list-group-flush">
            <?php foreach ($alerts as $a): ?>
                <li class="list-group-item d-flex gap-2 <?= $a['read_at'] === null ? 'fw-semibold' : '' ?>" data-alert-id="<?= (int) $a['id'] ?>">
                    <span class="nc-cat-dot nc-cat-dot-sm flex-shrink-0 mt-1" data-bg="<?= e($a['color'] ?: '#0f766e') ?>"><i class="bi bi-bell-fill" aria-hidden="true"></i></span>
                    <div class="flex-grow-1 min-w-0">
                        <div><?php if ($a['url']): ?><a class="text-body text-decoration-none" href="<?= e(url($a['url'])) ?>"><?= e($a['title']) ?></a><?php else: ?><?= e($a['title']) ?><?php endif; ?> <span class="badge <?= e($sevClass[$a['severity']] ?? '') ?> fw-normal"><?= e($types[$a['type']]['label'] ?? $a['type']) ?></span></div>
                        <?php if ($a['body']): ?><div class="small fw-normal text-body-secondary nc-prewrap"><?= e($a['body']) ?></div><?php endif; ?>
                        <div class="small fw-normal text-body-secondary"><?= e(time_ago($a['created_at'])) ?><?= $a['sent_at'] === null && $a['channel'] !== 'none' ? ' · aguardando envio' : '' ?><?= in_array($a['channel'], ['push', 'both'], true) && $a['sent_at'] ? ' · push' : '' ?><?= in_array($a['channel'], ['email', 'both'], true) && $a['sent_at'] ? ' · e-mail' : '' ?></div>
                    </div>
                    <?php if ($a['read_at'] === null): ?><form method="post" action="<?= e(route('alerts.read', ['id' => $a['id']])) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm nc-btn-icon" title="Marcar como lido" aria-label="Marcar como lido"><i class="bi bi-check2" aria-hidden="true"></i></button></form><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
</div>
