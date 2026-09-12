<?php
// app/Views/partials/footer.php — rodapé obrigatório LGPD em todas as telas
$dpoEmail = (string) config('legal.dpo_email', '');
?>
<footer class="nc-footer text-body-secondary small">
    <div class="container-fluid px-3 py-3 d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div><?= e($appName) ?> · v<?= e($appVersion) ?></div>
        <nav aria-label="Privacidade e termos" class="d-flex flex-wrap gap-3">
            <a href="<?= e(route_exists('legal.privacy') ? route('legal.privacy') : url('/privacidade')) ?>">Privacidade</a>
            <a href="<?= e(route_exists('legal.terms') ? route('legal.terms') : url('/termos')) ?>">Termos</a>
            <a href="<?= e(route_exists('privacy.index') ? route('privacy.index') : url('/conta/privacidade')) ?>">Seus dados</a>
            <?php if ($dpoEmail !== ''): ?>
                <a href="mailto:<?= e($dpoEmail) ?>">Encarregado de dados</a>
            <?php else: ?>
                <a href="<?= e(route_exists('legal.privacy') ? route('legal.privacy') : url('/privacidade')) ?>#encarregado">Encarregado de dados</a>
            <?php endif; ?>
        </nav>
    </div>
</footer>
