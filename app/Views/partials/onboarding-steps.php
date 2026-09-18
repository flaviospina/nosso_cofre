<?php
// app/Views/partials/onboarding-steps.php
/** @var int $step */
$steps = [1 => 'Como vai usar', 2 => 'Configuração', 3 => 'Avisos'];
?>
<ol class="nc-steps list-unstyled d-flex gap-3 mb-4 small">
    <?php foreach ($steps as $n => $label): ?>
        <li class="d-flex align-items-center gap-2 <?= $n === $step ? 'fw-semibold' : 'text-body-secondary' ?>">
            <span class="nc-step-dot <?= $n < $step ? 'done' : ($n === $step ? 'current' : '') ?>"><?= $n < $step ? '✓' : $n ?></span><?= e($label) ?>
        </li>
    <?php endforeach; ?>
</ol>
