<?php
// app/Views/emails/account-exists.php
/** @var string $name */
/** @var string $loginUrl */
/** @var string $resetUrl */
?>
<p>Olá, <?= e($name) ?>!</p>
<p>Alguém (talvez você) tentou criar uma conta no Nosso Cofre com este e-mail, mas ele já está cadastrado.</p>
<p style="text-align:center;margin:28px 0;"><a href="<?= e($loginUrl) ?>" style="background:#0f766e;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;display:inline-block;">Entrar na minha conta</a></p>
<p style="font-size:13px;color:#6b7280;">Esqueceu a senha? Peça uma nova em <?= e($resetUrl) ?>.</p>
<p style="font-size:13px;color:#6b7280;">Se não foi você, nada aconteceu: nenhuma conta nova foi criada e a sua continua igual.</p>
