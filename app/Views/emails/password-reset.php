<?php
// app/Views/emails/password-reset.php
/** @var string $name */
/** @var string $url */
/** @var int $minutes */
?>
<p>Olá, <?= e($name) ?>.</p>
<p>Recebemos um pedido para redefinir a senha da sua conta no Nosso Cofre.</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($url) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Criar nova senha</a></p>
<p style="font-size:13px;color:#6b7280;">O link vale por <?= (int) $minutes ?> minutos e só pode ser usado uma vez. Se não funcionar, copie e cole no navegador:<br><?= e($url) ?></p>
<p style="font-size:13px;color:#6b7280;">Se você não pediu a redefinição, ignore este e-mail: sua senha continua a mesma.</p>
