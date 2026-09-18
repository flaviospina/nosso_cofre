<?php
// app/Views/account/my-data.php — confirmação e acesso (art. 18, I e II)
/** @var array<string,mixed> $info */
$render = static function (mixed $v): string {
    if ($v === null || $v === '') return '<span class="text-body-secondary">—</span>';
    if (is_bool($v) || $v === 0 || $v === 1 || $v === '0' || $v === '1') return $v ? 'sim' : 'não';
    if (is_array($v)) return '<code>' . e(json_encode($v, JSON_UNESCAPED_UNICODE)) . '</code>';
    return e((string) $v);
};
$table = static function (array $rows) use ($render): string {
    if ($rows === []) return '<p class="text-body-secondary small mb-0">Nenhum registro.</p>';
    $h = '<div class="table-responsive"><table class="table table-sm small"><thead><tr>';
    foreach (array_keys($rows[0]) as $k) $h .= '<th>' . e((string) $k) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $h .= '<tr>'; foreach ($r as $v) $h .= '<td>' . $render($v) . '</td>'; $h .= '</tr>'; }
    return $h . '</tbody></table></div>';
};
?>
<div class="nc-maxw-md mx-auto">
    <?= \App\Core\View::partial('account-nav', ['active' => 'privacy.index']) ?>
    <h1 class="h3 mb-1">Tudo que guardamos sobre você</h1>
    <p class="text-body-secondary">Datas em UTC. Para levar em arquivo, use "Exportar meus dados" na tela de privacidade.</p>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Perfil</h2><dl class="row small mb-0"><?php foreach ($info['perfil'] as $k => $v): ?><dt class="col-sm-5"><?= e(str_replace('_', ' ', $k)) ?></dt><dd class="col-sm-7"><?= $render($v) ?></dd><?php endforeach; ?></dl></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Lares e o que é seu em cada um</h2><?= $table(array_map(static fn(array $h) => $h + ($info['contagens_por_lar'][(int) $h['id']] ?? []), $info['lares'])) ?></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Consentimentos (histórico completo)</h2><?= $table($info['consentimentos']) ?></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Sessões ativas</h2><?= $table($info['sessoes']) ?></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Aparelhos lembrados</h2><?= $table($info['lembrar_me']) ?></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Últimos acessos (registros de segurança)</h2><?= $table($info['acessos']) ?></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Notificações</h2><p class="small mb-1">Assinaturas push: <?= count($info['push']) ?>. Preferências: <?= $render($info['notificacoes']['settings'] ?? null) ?></p></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Auditoria</h2><p class="small mb-0"><?= (int) $info['auditoria_total'] ?> registros, visíveis em <a href="<?= e(route('account.activity')) ?>">Minha atividade</a>.</p></div></section>
    <section class="card nc-card mb-3"><div class="card-body"><h2 class="h6">Exportações</h2><?= $table($info['exportacoes']) ?></div></section>
    <?php if ($info['exclusao'] !== null): ?><div class="alert alert-warning small">Exclusão da conta agendada para <?= e(datetime_br($info['exclusao']['scheduled_for'])) ?>.</div><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= e(route('privacy.index')) ?>">Voltar</a>
</div>
