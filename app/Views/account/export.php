<?php
// app/Views/account/export.php — link de download da exportação
/** @var array<string,mixed> $row */
/** @var string $token */
?>
<div class="nc-maxw-sm mx-auto">
    <div class="card nc-card"><div class="card-body text-center">
        <i class="bi bi-file-earmark-zip display-4 text-success" aria-hidden="true"></i>
        <h1 class="h4 mt-2">Exportação pronta</h1>
        <p class="text-body-secondary"><?= e(number_format(((int) $row['size_bytes']) / 1024, 1, ',', '.')) ?> KB · válida até <?= e(datetime_br($row['expires_at'])) ?>. O mesmo link foi enviado por e-mail.</p>
        <a class="btn btn-primary" href="<?= e(route('privacy.export.download', ['token' => $token])) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Baixar ZIP</a>
        <a class="btn btn-link" href="<?= e(route('privacy.index')) ?>">Voltar</a>
    </div></div>
</div>
