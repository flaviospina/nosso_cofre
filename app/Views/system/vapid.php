<?php
// app/Views/system/vapid.php — geração das chaves VAPID (Web Push) sem terminal
/** @var array{public:string,private:string} $keys */ /** @var bool $configured */ /** @var string $current */ /** @var string $subject */
?>
<div class="nc-maxw-md mx-auto">
    <h1 class="h3 mb-2">Chaves VAPID (Web Push)</h1>
    <?php if ($configured): ?>
        <div class="alert alert-success">Já existe um par configurado (chave pública <code><?= e(substr($current, 0, 12)) ?>…</code>). Trocar as chaves invalida as assinaturas push de todos os aparelhos; só faça isso se precisar.</div>
    <?php endif; ?>
    <p>Copie as três linhas abaixo para o arquivo <code>.env</code> (gerenciador de arquivos do cPanel → editar) e salve. Recarregar esta página gera um par novo; use um só.</p>
    <pre class="nc-pre border rounded p-3 bg-body-tertiary user-select-all" id="vapidEnv">VAPID_PUBLIC_KEY=<?= e($keys['public']) ?>

VAPID_PRIVATE_KEY=<?= e($keys['private']) ?>

VAPID_SUBJECT=<?= e($subject) ?></pre>
    <button type="button" class="btn btn-outline-primary" data-copy-target="#vapidEnv"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiar</button>
    <p class="small text-body-secondary mt-3">A chave privada nunca sai do servidor: fica só no <code>.env</code>, que está fora da pasta pública. Depois de salvar, confira em <code>/saude</code> o item "Chaves VAPID configuradas".</p>
</div>
