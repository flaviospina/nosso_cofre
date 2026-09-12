<?php
// app/Views/system/install.php — gera chaves para o .env (só enquanto APP_KEY estiver vazio)
/** @var string $appKey */
/** @var string $backupKey */
/** @var string $cronToken */
?>
<div class="nc-maxw-md mx-auto">
    <h1 class="h3 mb-3"><i class="bi bi-key me-2" aria-hidden="true"></i>Instalação: chaves do <code>.env</code></h1>
    <div class="alert alert-info">
        Copie as linhas abaixo para o arquivo <code>.env</code> (Gerenciador de Arquivos do cPanel → editar).
        Cada recarregamento desta página gera valores novos. Depois que <code>APP_KEY</code> estiver preenchido, esta tela deixa de existir.
    </div>
    <div class="alert alert-warning">
        <strong>Guarde uma cópia do <code>BACKUP_KEY</code> fora do servidor.</strong> Sem ele os backups criptografados não podem ser restaurados.
        Trocar o <code>APP_KEY</code> depois de usar o sistema inutiliza os campos criptografados (2FA, observações, CPF).
    </div>
    <label for="envLines" class="form-label fw-semibold">Linhas para o .env</label>
    <textarea id="envLines" class="form-control font-monospace" rows="4" readonly>APP_KEY=<?= e($appKey) ?>

BACKUP_KEY=<?= e($backupKey) ?>

CRON_TOKEN=<?= e($cronToken) ?></textarea>
    <div class="d-flex gap-2 mt-3">
        <button type="button" class="btn btn-primary" data-copy-target="#envLines"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiar</button>
        <a class="btn btn-outline-secondary" href="<?= e(route('system.health')) ?>">Ir para a verificação de saúde</a>
    </div>
</div>
