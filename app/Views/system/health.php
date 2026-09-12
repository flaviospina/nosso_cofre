<?php
// app/Views/system/health.php
/** @var array<string,array{label:string,ok:bool,info:string}> $checks */
/** @var bool $allOk */
/** @var bool $debug */
?>
<div class="nc-maxw-md mx-auto">
    <h1 class="h3 mb-3"><i class="bi bi-heart-pulse me-2" aria-hidden="true"></i>Saúde da instalação</h1>
    <div class="alert <?= $allOk ? 'alert-success' : 'alert-warning' ?>">
        <?= $allOk ? 'Tudo certo: a instalação está pronta.' : 'Há itens pendentes. Corrija os marcados em vermelho e recarregue esta página.' ?>
    </div>
    <ul class="list-group nc-card">
        <?php foreach ($checks as $key => $check): ?>
            <li class="list-group-item d-flex align-items-start gap-2">
                <?php if ($check['ok']): ?>
                    <i class="bi bi-check-circle-fill text-success mt-1" aria-label="OK"></i>
                <?php else: ?>
                    <i class="bi bi-x-circle-fill text-danger mt-1" aria-label="Pendente"></i>
                <?php endif; ?>
                <div>
                    <div><?= e($check['label']) ?></div>
                    <?php if ($check['info'] !== '' && (!$check['ok'] || $debug)): ?>
                        <div class="small text-body-secondary"><?= e($check['info']) ?></div>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="small text-body-secondary mt-3">
        Detalhes técnicos (versões, caminhos) só aparecem com <code>APP_DEBUG=true</code>. Em produção mantenha <code>false</code>.
    </p>
</div>
