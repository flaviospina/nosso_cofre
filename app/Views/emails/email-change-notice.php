<?php /** @var string $name */ /** @var string $newEmail */ /** @var string $sessionsUrl */ ?>
<p>Olá, <?= e($name) ?>.</p>
<p>Alguém pediu para trocar o e-mail da sua conta no Nosso Cofre para <strong><?= e($newEmail) ?></strong>. A troca só acontece depois que o novo endereço confirmar o link que enviamos a ele.</p>
<p><strong>Não foi você?</strong> Troque sua senha agora e encerre as outras sessões em <a href="<?= e($sessionsUrl) ?>"><?= e($sessionsUrl) ?></a>.</p>
