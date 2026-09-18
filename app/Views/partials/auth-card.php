<?php
// app/Views/partials/auth-card.php — cartão centralizado das telas de acesso
/** @var string $heading */
/** @var string $body */
/** @var string|null $lead */
?>
<div class="nc-maxw-sm mx-auto py-3">
    <div class="card nc-card">
        <div class="card-body p-4">
            <h1 class="h4 mb-1"><?= e($heading) ?></h1>
            <?php if (!empty($lead)): ?><p class="text-body-secondary mb-3"><?= e($lead) ?></p><?php endif; ?>
            <?= $body ?>
        </div>
    </div>
</div>
