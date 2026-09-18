<?php
// app/Views/emails/verify-email.php
/** @var string $name */
/** @var string $url */
/** @var int $hours */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>Falta só um passo para começar a usar o Nosso Cofre: confirme que este e-mail é seu.</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($url) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Confirmar e-mail</a></p>
<p style="font-size:13px;color:#6b7280;">O link vale por <?= (int) $hours ?> horas. Se o botão não funcionar, copie e cole no navegador:<br><?= e($url) ?></p>
<p style="font-size:13px;color:#6b7280;">Se você não criou uma conta, ignore este e-mail: nada será ativado.</p>
