<?php /** @var string $name */ /** @var int $days */ /** @var string $when */ /** @var string $kind */ /** @var string $cancelUrl */ ?>
<p>Olá, <?= e($name) ?>.</p>
<?php if ($kind === 'household'): ?>
<p>O responsável pelo lar <strong><?= e($householdName ?? '') ?></strong> pediu a exclusão do lar. Ela acontece em <?= (int) $days ?> dias (<?= e(datetime_br($when)) ?>, horário de Brasília aproximado).</p>
<p>Quando isso ocorrer, contas, lançamentos, metas e anexos do lar serão apagados. Sua conta pessoal continua existindo. Se quiser guardar seus dados, exporte-os antes em Conta → Privacidade e seus dados.</p>
<?php else: ?>
<p>Recebemos o pedido de exclusão da sua conta. Ela será apagada em <?= (int) $days ?> dias (<?= e(datetime_br($when)) ?>).</p>
<p>Até lá tudo continua funcionando e você pode cancelar a qualquer momento:</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($cancelUrl) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Cancelar exclusão</a></p>
<p style="font-size:13px;color:#6b7280;">Se não foi você, troque sua senha imediatamente e encerre as outras sessões.</p>
<?php endif; ?>
