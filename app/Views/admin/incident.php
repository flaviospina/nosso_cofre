<?php
// app/Views/admin/incident.php — detalhe e comunicação
/** @var array<string,mixed> $incident */
?>
<div class="nc-maxw-md mx-auto">
    <a class="small" href="<?= e(route('admin.incidents')) ?>">← Incidentes</a>
    <h1 class="h3 mt-1 mb-2">#<?= (int) $incident['id'] ?> <?= e($incident['title']) ?></h1>
    <div class="card nc-card mb-3"><div class="card-body">
        <p style="white-space:pre-wrap" class="mb-2"><?= e($incident['description']) ?></p>
        <dl class="row small mb-0">
            <dt class="col-sm-3">Ocorrido em</dt><dd class="col-sm-9"><?= e(datetime_br($incident['occurred_at'])) ?: '—' ?></dd>
            <dt class="col-sm-3">Detectado em</dt><dd class="col-sm-9"><?= e(datetime_br($incident['detected_at'])) ?></dd>
            <dt class="col-sm-3">Comunicado aos titulares</dt><dd class="col-sm-9"><?= $incident['notified_at'] ? e(datetime_br($incident['notified_at'])) . ' (' . (int) $incident['affected_users_count'] . ')' : 'ainda não' ?></dd>
            <dt class="col-sm-3">ANPD</dt><dd class="col-sm-9"><?= $incident['anpd_notified_at'] ? 'comunicada em ' . e(datetime_br($incident['anpd_notified_at'])) : 'não comunicada' ?></dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= e($incident['status']) ?></dd>
        </dl>
        <?php if (!empty($incident['notes'])): ?><hr><pre class="small mb-0 nc-pre"><?= e($incident['notes']) ?></pre><?php endif; ?>
    </div></div>
    <?php if ($incident['status'] !== 'closed'): ?>
        <form method="post" action="<?= e(route('admin.incidents.notify', ['id' => $incident['id']])) ?>" data-once novalidate data-confirm="Enviar o comunicado agora?" class="card nc-card mb-3"><div class="card-body row g-2">
            <?= csrf_field() ?>
            <h2 class="h5">Comunicar os afetados</h2>
            <p class="small text-body-secondary mb-1">Modelo de e-mail: o que aconteceu, dados envolvidos, quando, medidas adotadas, recomendações e contato do encarregado.</p>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="radio" name="scope" id="scope_all" value="all" checked><label class="form-check-label" for="scope_all">Todos os usuários ativos</label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="scope" id="scope_list" value="list"><label class="form-check-label" for="scope_list">Só estes e-mails:</label></div></div>
            <div class="col-12"><textarea class="form-control" name="emails" rows="2" placeholder="um por linha"><?= e(old('emails')) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="measures">Medidas adotadas</label><textarea class="form-control<?= invalid_class('measures') ?>" id="measures" name="measures" rows="2" required><?= e(old('measures')) ?></textarea><?= field_error('measures') ?></div>
            <div class="col-12"><label class="form-label" for="recommendations">Recomendações ao usuário</label><textarea class="form-control<?= invalid_class('recommendations') ?>" id="recommendations" name="recommendations" rows="2" required><?= e(old('recommendations', 'Troque sua senha, ative a verificação em duas etapas e encerre as outras sessões em Conta → Sessões ativas.')) ?></textarea><?= field_error('recommendations') ?></div>
            <div class="col-12 d-flex justify-content-end"><button class="btn btn-danger">Enviar comunicado</button></div>
        </div></form>
        <div class="d-flex gap-2">
            <form method="post" action="<?= e(route('admin.incidents.anpd', ['id' => $incident['id']])) ?>" data-once><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">Registrar comunicação à ANPD</button></form>
            <form method="post" action="<?= e(route('admin.incidents.close', ['id' => $incident['id']])) ?>" data-once data-confirm="Encerrar o incidente?"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">Encerrar</button></form>
        </div>
    <?php endif; ?>
</div>
