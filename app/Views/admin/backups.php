<?php
// app/Views/admin/backups.php — backups criptografados (área do controlador)
/** @var list<array{name:string,path:string,size:int,created_at:string}> $backups */ /** @var bool $configured */ /** @var int $retention */
?>
<div class="nc-maxw-md mx-auto">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h1 class="h3 mb-0">Backups</h1>
        <?php if ($configured): ?><form method="post" action="<?= e(route('admin.backups.create')) ?>"><?= csrf_field() ?><button type="submit" class="btn btn-primary"><i class="bi bi-database-down me-1" aria-hidden="true"></i>Gerar agora</button></form><?php endif; ?>
    </div>
    <p class="text-body-secondary">O cron gera um backup por dia (SQL completo, compactado e cifrado com <code>BACKUP_KEY</code>) e mantém os últimos <?= $retention ?> dias em <code>storage/backups</code>. Baixe uma cópia de vez em quando e guarde fora do servidor. Restauração: LEIA-ME §3.16.</p>
    <?php if (!$configured): ?><div class="alert alert-warning"><code>BACKUP_KEY</code> não configurada no <code>.env</code>. Gere uma chave em <code>/instalar</code> (ou qualquer sequência aleatória de 64 caracteres) para ativar os backups.</div><?php endif; ?>
    <?php if ($backups === []): ?><div class="card nc-card"><div class="card-body text-body-secondary">Nenhum backup ainda.</div></div><?php else: ?>
    <div class="card nc-card"><ul class="list-group list-group-flush">
        <?php foreach ($backups as $b): ?><li class="list-group-item d-flex align-items-center gap-2"><i class="bi bi-file-earmark-lock fs-4 text-body-secondary" aria-hidden="true"></i><div class="flex-grow-1"><div><?= e($b['name']) ?></div><div class="small text-body-secondary"><?= e(datetime_br($b['created_at'])) ?> · <?= e(number_format($b['size'] / 1024, 0, ',', '.')) ?> KB</div></div><a class="btn btn-sm btn-outline-primary" href="<?= e(route('admin.backups.download', ['name' => $b['name']])) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Baixar</a></li><?php endforeach; ?>
    </ul></div>
    <?php endif; ?>
</div>
