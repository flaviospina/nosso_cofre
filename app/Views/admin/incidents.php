<?php
// app/Views/admin/incidents.php — lista e registro de incidentes (área do controlador)
/** @var array<int,array<string,mixed>> $incidents */
?>
<div class="nc-maxw-md mx-auto">
    <h1 class="h3 mb-1"><i class="bi bi-shield-exclamation me-2" aria-hidden="true"></i>Incidentes de segurança</h1>
    <p class="text-body-secondary">Registro e comunicação aos titulares (art. 48 da LGPD). Procedimento completo no LEIA-ME.</p>
    <form method="post" action="<?= e(route('admin.incidents.store')) ?>" data-once novalidate class="card nc-card mb-4"><div class="card-body row g-2">
        <?= csrf_field() ?>
        <div class="col-12"><label class="form-label" for="title">Título</label><input class="form-control<?= invalid_class('title') ?>" id="title" name="title" value="<?= e(old('title')) ?>" required maxlength="190"><?= field_error('title') ?></div>
        <div class="col-12"><label class="form-label" for="description">O que aconteceu (dados envolvidos, como foi descoberto)</label><textarea class="form-control<?= invalid_class('description') ?>" id="description" name="description" rows="3" required><?= e(old('description')) ?></textarea><?= field_error('description') ?></div>
        <div class="col-12 col-sm-6"><label class="form-label" for="occurred_at">Ocorrido em (se souber)</label><input type="datetime-local" class="form-control<?= invalid_class('occurred_at') ?>" id="occurred_at" name="occurred_at" value="<?= e(old('occurred_at')) ?>"><?= field_error('occurred_at') ?></div>
        <div class="col-12 col-sm-6"><label class="form-label" for="detected_at">Detectado em</label><input type="datetime-local" class="form-control<?= invalid_class('detected_at') ?>" id="detected_at" name="detected_at" value="<?= e(old('detected_at', gmdate('Y-m-d\TH:i'))) ?>" required><?= field_error('detected_at') ?></div>
        <div class="col-12"><label class="form-label" for="notes">Anotações internas</label><textarea class="form-control" id="notes" name="notes" rows="2"><?= e(old('notes')) ?></textarea></div>
        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary">Registrar incidente</button></div>
    </div></form>
    <?php if ($incidents === []): ?><p class="text-body-secondary">Nenhum incidente registrado.</p><?php else: ?>
        <div class="list-group nc-card">
            <?php foreach ($incidents as $i): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= e(route('admin.incidents.show', ['id' => $i['id']])) ?>">
                    <div><strong>#<?= (int) $i['id'] ?> <?= e($i['title']) ?></strong><div class="small text-body-secondary">detectado em <?= e(datetime_br($i['detected_at'])) ?> · <?= (int) $i['affected_users_count'] ?> comunicado(s)</div></div>
                    <span class="badge text-bg-<?= $i['status'] === 'closed' ? 'secondary' : ($i['status'] === 'notified' ? 'info' : 'danger') ?>"><?= e(['open' => 'aberto', 'notified' => 'comunicado', 'closed' => 'encerrado'][$i['status']] ?? $i['status']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
