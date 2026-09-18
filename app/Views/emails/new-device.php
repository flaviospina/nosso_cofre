<?php
// app/Views/emails/new-device.php
/** @var string $name */
/** @var string $device */
/** @var string $ip */
/** @var string $when */
/** @var string $sessionsUrl */
?>
<p>Olá, <?= e($name) ?>.</p>
<p>Sua conta no Nosso Cofre acabou de ser acessada de um aparelho ou endereço que não conhecíamos:</p>
<table role="presentation" cellspacing="0" cellpadding="6" style="font-size:14px;border:1px solid #e5e7eb;border-radius:8px;">
<tr><td style="color:#6b7280;">Aparelho</td><td><strong><?= e($device) ?></strong></td></tr>
<tr><td style="color:#6b7280;">Endereço IP</td><td><?= e($ip) ?></td></tr>
<tr><td style="color:#6b7280;">Quando</td><td><?= e($when) ?></td></tr>
</table>
<p>Foi você? Então não precisa fazer nada.</p>
<p><strong>Não foi você?</strong> Troque sua senha agora e encerre as outras sessões em <a href="<?= e($sessionsUrl) ?>"><?= e($sessionsUrl) ?></a>. Se ainda não ativou a verificação em duas etapas, este é o momento.</p>
