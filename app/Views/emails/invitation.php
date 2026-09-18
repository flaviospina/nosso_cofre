<?php
// app/Views/emails/invitation.php
/** @var string $householdName */
/** @var string $inviterName */
/** @var string $role */
/** @var string $url */
/** @var int $days */
?>
<p>Olá!</p>
<p><strong><?= e($inviterName) ?></strong> convidou você para participar do lar <strong><?= e($householdName) ?></strong> no Nosso Cofre, como <strong><?= e($role) ?></strong>.</p>
<p>O Nosso Cofre é um controle financeiro para famílias: cada pessoa registra seus gastos, todos veem o conjunto e o app ajuda a economizar.</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($url) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Aceitar convite</a></p>
<p style="font-size:13px;color:#6b7280;">O convite vale por <?= (int) $days ?> dias. Se você ainda não tem conta, será possível criar uma com este mesmo e-mail. Link: <?= e($url) ?></p>
<p style="font-size:13px;color:#6b7280;">Se não conhece quem enviou, ignore este e-mail.</p>
