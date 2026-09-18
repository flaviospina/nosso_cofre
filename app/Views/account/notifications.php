<?php
// app/Views/account/notifications.php — Conta → Notificações: tudo configurável pelo usuário (§5)
/** @var array<string,mixed> $settings */ /** @var array<string,bool> $consents */ /** @var array<string,array<string,mixed>> $types */
/** @var array<string,string> $colors */ /** @var array<string,string> $sounds */ /** @var array<string,string> $channels */
/** @var array<int,string> $groupOpts */ /** @var array<string,string> $digestOpts */ /** @var list<array<string,mixed>> $devices */
/** @var bool $pushReady */ /** @var string $vapidPublic */ /** @var bool $isFamily */
$s = $settings;
$weekdays = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
$colorPicker = static function (string $name, string $current) use ($colors): void { ?>
    <div class="d-flex flex-wrap gap-1" role="radiogroup" aria-label="Cor">
        <?php foreach ($colors as $hex => $label): ?>
            <label class="nc-swatch" title="<?= e($label) ?>"><input type="radio" name="<?= e($name) ?>" value="<?= e($hex) ?>" <?= $current === $hex ? 'checked' : '' ?>><span data-bg="<?= e($hex) ?>" aria-hidden="true"></span><span class="visually-hidden"><?= e($label) ?></span></label>
        <?php endforeach; ?>
    </div>
<?php };
?>
<div class="nc-maxw-md mx-auto" data-notifications-page data-vapid="<?= e($vapidPublic) ?>" data-subscribe-url="<?= e(route('notifications.subscribe')) ?>" data-unsubscribe-url="<?= e(route('notifications.unsubscribe')) ?>" data-sounds-base="<?= e(asset('assets/sounds/')) ?>">
    <?= \App\Core\View::partial('account-nav', ['active' => 'notifications.index']) ?>
    <h1 class="h3 mb-1">Notificações</h1>
    <p class="text-body-secondary">Nada é enviado sem você escolher. Só os e-mails de segurança (confirmação, senha, aparelho novo) são obrigatórios. Base legal: <strong>consentimento</strong> (art. 7º, I, da LGPD), revogável a qualquer momento aqui ou em <a href="<?= e(route('privacy.index')) ?>">Privacidade e seus dados</a> ("desligar todos os avisos").</p>

    <form method="post" action="<?= e(route('notifications.update')) ?>" data-once novalidate>
        <?= csrf_field() ?>
        <div class="card nc-card mb-3"><div class="card-body">
            <h2 class="h5"><i class="bi bi-toggles me-1" aria-hidden="true"></i>Canais</h2>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="consent_email" name="consent_email" value="1" <?= $consents['digest_email'] ? 'checked' : '' ?>><label class="form-check-label" for="consent_email"><strong>E-mail</strong> para avisos e resumos</label></div>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="consent_push" name="consent_push" value="1" <?= $consents['push'] ? 'checked' : '' ?>><label class="form-check-label" for="consent_push"><strong>Push</strong> no celular ou navegador (aparelho por aparelho, abaixo)</label></div>
            <div class="form-text">"Só no app" mostra o aviso na Central e como toast enquanto o app está aberto, sem push nem e-mail.</div>
        </div></div>

        <div class="card nc-card mb-3"><div class="card-body">
            <h2 class="h5"><i class="bi bi-bell me-1" aria-hidden="true"></i>Tipos de aviso</h2>
            <div class="accordion accordion-flush" id="tiposAviso">
                <?php foreach ($types as $key => $meta): $t = $s['types'][$key]; $n = "types[{$key}]"; $isSecurity = $key === 'security'; $isDigest = $key === 'digest'; $isMember = $key === 'member_activity'; if ($isMember && !$isFamily) { continue; } ?>
                    <div class="accordion-item">
                        <h3 class="accordion-header d-flex align-items-center gap-2 pe-2">
                            <div class="form-check form-switch ms-3 my-2 flex-shrink-0">
                                <?php if ($isSecurity): ?><input class="form-check-input" type="checkbox" checked disabled aria-label="Sempre ligado"><?php elseif ($isMember): ?><input class="form-check-input" type="checkbox" role="switch" id="sw_<?= e($key) ?>" data-member-switch <?= ($t['mode'] ?? 'never') !== 'never' ? 'checked' : '' ?> aria-label="Ligar"><?php else: ?><input class="form-check-input" type="checkbox" role="switch" id="sw_<?= e($key) ?>" name="<?= e($n) ?>[enabled]" value="1" <?= !empty($t['enabled']) ? 'checked' : '' ?> aria-label="Ligar <?= e($meta['label']) ?>"><?php endif; ?>
                            </div>
                            <button class="accordion-button collapsed py-2 ps-1" type="button" data-bs-toggle="collapse" data-bs-target="#tipo_<?= e($key) ?>" aria-expanded="false" aria-controls="tipo_<?= e($key) ?>">
                                <span class="nc-cat-dot nc-cat-dot-sm me-2" data-bg="<?= e($t['color']) ?>" data-preview-dot="<?= e($key) ?>"><i class="bi bi-bell-fill" aria-hidden="true"></i></span><span><?= e($meta['label']) ?> <span class="small text-body-secondary d-block d-sm-inline"><?= e($meta['help']) ?></span></span>
                            </button>
                        </h3>
                        <div id="tipo_<?= e($key) ?>" class="accordion-collapse collapse" data-bs-parent="#tiposAviso"><div class="accordion-body pt-0">
                            <div class="row g-3">
                                <?php if ($isSecurity): ?>
                                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="sec_push" name="<?= e($n) ?>[push]" value="1" <?= ($t['channel'] ?? 'email') === 'both' ? 'checked' : '' ?>><label class="form-check-label" for="sec_push">Também por push (o e-mail é sempre enviado)</label></div></div>
                                <?php elseif ($isDigest): ?>
                                    <div class="col-6 col-sm-4"><label class="form-label small mb-0" for="dg_freq">Frequência</label><select class="form-select form-select-sm" id="dg_freq" name="<?= e($n) ?>[frequency]"><?php foreach (['daily' => 'Diário', 'weekly' => 'Semanal', 'monthly' => 'Mensal'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($t['frequency'] ?? 'weekly') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-6 col-sm-4" data-show-when="<?= e($n) ?>[frequency]=weekly"><label class="form-label small mb-0" for="dg_wd">Dia da semana</label><select class="form-select form-select-sm" id="dg_wd" name="<?= e($n) ?>[weekday]"><?php foreach ($weekdays as $d => $l): ?><option value="<?= $d ?>" <?= (int) ($t['weekday'] ?? 1) === $d ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-6 col-sm-4" data-show-when="<?= e($n) ?>[frequency]=monthly"><label class="form-label small mb-0" for="dg_day">Dia do mês</label><input type="number" min="1" max="28" class="form-control form-control-sm" id="dg_day" name="<?= e($n) ?>[day]" value="<?= (int) ($t['day'] ?? 1) ?>"></div>
                                    <div class="col-6 col-sm-4"><label class="form-label small mb-0" for="dg_time">Hora</label><input type="time" class="form-control form-control-sm" id="dg_time" name="<?= e($n) ?>[time]" value="<?= e($t['time'] ?? '08:00') ?>"></div>
                                    <div class="col-12 col-sm-4"><label class="form-label small mb-0" for="dg_ch">Canal</label><select class="form-select form-select-sm" id="dg_ch" name="<?= e($n) ?>[channel]"><?php foreach (['email' => 'E-mail', 'push' => 'Push', 'both' => 'Push e e-mail'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($t['channel'] ?? 'email') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-12"><span class="form-label small mb-1 d-block">Conteúdo</span><?php foreach ($digestOpts as $k => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="dg_c_<?= e($k) ?>" name="<?= e($n) ?>[contents][]" value="<?= e($k) ?>" <?= in_array($k, (array) ($t['contents'] ?? []), true) ? 'checked' : '' ?>><label class="form-check-label small" for="dg_c_<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
                                <?php else: ?>
                                    <div class="col-12 col-sm-5"><label class="form-label small mb-0" for="ch_<?= e($key) ?>">Canal</label><select class="form-select form-select-sm" id="ch_<?= e($key) ?>" name="<?= e($n) ?>[channel]"><?php foreach ($channels as $k => $l): ?><option value="<?= e($k) ?>" <?= ($t['channel'] ?? 'app') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                                    <?php if ($key === 'due'): ?>
                                        <div class="col-12 col-sm-7"><span class="form-label small mb-1 d-block">Avisar quantos dias antes</span><?php foreach ([7, 5, 3, 1, 0] as $d): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="due_d<?= $d ?>" name="<?= e($n) ?>[days][]" value="<?= $d ?>" <?= in_array($d, (array) ($t['days'] ?? []), true) ? 'checked' : '' ?>><label class="form-check-label small" for="due_d<?= $d ?>"><?= $d === 0 ? 'no dia' : $d ?></label></div><?php endforeach; ?></div>
                                    <?php elseif ($key === 'budget'): ?>
                                        <div class="col-12 col-sm-7"><label class="form-label small mb-0">Limiares (%)</label><div class="d-flex gap-2"><?php foreach ([0, 1, 2] as $i): ?><input type="number" min="10" max="200" class="form-control form-control-sm" name="<?= e($n) ?>[thresholds][]" value="<?= isset($t['thresholds'][$i]) ? (int) $t['thresholds'][$i] : '' ?>" aria-label="Limiar <?= $i + 1 ?>" placeholder="<?= [80, 100, ''][$i] ?>"><?php endforeach; ?></div></div>
                                    <?php elseif ($key === 'event'): ?>
                                        <div class="col-12 col-sm-7"><label class="form-label small mb-0" for="ev_days">Antecedência padrão</label><div class="input-group input-group-sm"><input type="number" min="0" max="120" class="form-control" id="ev_days" name="<?= e($n) ?>[days_before]" value="<?= (int) ($t['days_before'] ?? 30) ?>"><span class="input-group-text">dias</span></div><div class="form-text">Cada recorrência pode ter a sua própria antecedência.</div></div>
                                    <?php elseif ($isMember): ?>
                                        <div class="col-12 col-sm-7"><label class="form-label small mb-0" for="ma_mode">Quando avisar</label><select class="form-select form-select-sm" id="ma_mode" name="<?= e($n) ?>[mode]" data-member-mode><?php foreach (['never' => 'Nunca', 'above' => 'Só acima de um valor', 'all' => 'Todos os lançamentos'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($t['mode'] ?? 'never') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                                        <div class="col-12 col-sm-5" data-show-when="<?= e($n) ?>[mode]=above"><label class="form-label small mb-0" for="ma_min">Valor mínimo</label><div class="input-group input-group-sm"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control" id="ma_min" name="<?= e($n) ?>[min_amount]" value="<?= e(money($t['min_amount'] ?? 200, false)) ?>" data-money></div></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <div class="col-12 col-sm-7"><span class="form-label small mb-1 d-block">Cor</span><?php $colorPicker("{$n}[color]", (string) $t['color']); ?></div>
                                <div class="col-12 col-sm-5"><label class="form-label small mb-0" for="snd_<?= e($key) ?>">Som</label><div class="input-group input-group-sm"><select class="form-select" id="snd_<?= e($key) ?>" name="<?= e($n) ?>[sound]"><?php foreach ($sounds as $k => $l): ?><option value="<?= e($k) ?>" <?= ($t['sound'] ?? 'none') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-secondary" data-preview-type="<?= e($key) ?>" data-preview-title="<?= e($meta['label']) ?>" title="Ver como fica"><i class="bi bi-eye me-1" aria-hidden="true"></i>Ver como fica</button></div></div>
                            </div>
                        </div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div></div>

        <div class="card nc-card mb-3"><div class="card-body">
            <h2 class="h5"><i class="bi bi-clock me-1" aria-hidden="true"></i>Periodicidade e limites</h2>
            <div class="row g-3">
                <div class="col-12 col-sm-6"><label class="form-label small mb-0" for="mode">Modo</label><select class="form-select form-select-sm" id="mode" name="mode"><?php foreach (['immediate' => 'Imediato (cada evento gera um aviso)', 'grouped' => 'Agrupado (um push consolidado por período)', 'digest_only' => 'Somente resumo (o resto fica na Central)'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($s['mode'] ?? 'immediate') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-sm-3" data-show-when="mode=grouped"><label class="form-label small mb-0" for="group_minutes">A cada</label><select class="form-select form-select-sm" id="group_minutes" name="group_minutes"><?php foreach ($groupOpts as $k => $l): ?><option value="<?= $k ?>" <?= (int) ($s['group_minutes'] ?? 60) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-sm-3" data-show-when="group_minutes=1440"><label class="form-label small mb-0" for="group_time">Hora do envio</label><input type="time" class="form-control form-control-sm" id="group_time" name="group_time" value="<?= e($s['group_time'] ?? '08:00') ?>"></div>
                <div class="col-6 col-sm-3"><label class="form-label small mb-0" for="daily_limit">Máximo de pushes por dia</label><input type="number" min="1" max="100" class="form-control form-control-sm" id="daily_limit" name="daily_limit" value="<?= (int) ($s['daily_limit'] ?? 10) ?>"><div class="form-text">O excedente vai para a Central.</div></div>
                <div class="col-6 col-sm-3"><label class="form-label small mb-0" for="min_amount">Valor mínimo para avisar</label><div class="input-group input-group-sm"><span class="input-group-text">R$</span><input type="text" inputmode="decimal" class="form-control" id="min_amount" name="min_amount" value="<?= e(money($s['min_amount'] ?? 0, false)) ?>" data-money></div></div>
            </div>
        </div></div>

        <div class="card nc-card mb-3"><div class="card-body">
            <h2 class="h5"><i class="bi bi-moon-stars me-1" aria-hidden="true"></i>Horário silencioso e dias sem avisos</h2>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="quiet_enabled" name="quiet_enabled" value="1" <?= !empty($s['quiet']['enabled']) ? 'checked' : '' ?>><label class="form-check-label" for="quiet_enabled">Não enviar push nem e-mail entre</label></div>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-4 col-sm-2"><label class="form-label small mb-0" for="quiet_start">Início</label><input type="time" class="form-control form-control-sm" id="quiet_start" name="quiet_start" value="<?= e($s['quiet']['start'] ?? '22:00') ?>"></div>
                <div class="col-4 col-sm-2"><label class="form-label small mb-0" for="quiet_end">Fim</label><input type="time" class="form-control form-control-sm" id="quiet_end" name="quiet_end" value="<?= e($s['quiet']['end'] ?? '07:00') ?>"></div>
                <div class="col-12 col-sm-8"><span class="form-label small mb-1 d-block">Nos dias</span><?php foreach ($weekdays as $d => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="qd<?= $d ?>" name="quiet_days[]" value="<?= $d ?>" <?= in_array($d, (array) ($s['quiet']['days'] ?? []), true) ? 'checked' : '' ?>><label class="form-check-label small" for="qd<?= $d ?>"><?= e($l) ?></label></div><?php endforeach; ?></div>
            </div>
            <span class="form-label small mb-1 d-block">Dias sem avisos (o dia inteiro; a segurança continua)</span>
            <?php foreach ($weekdays as $d => $l): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="off<?= $d ?>" name="days_off[]" value="<?= $d ?>" <?= in_array($d, (array) ($s['days_off'] ?? []), true) ? 'checked' : '' ?>><label class="form-check-label small" for="off<?= $d ?>"><?= e($l) ?></label></div><?php endforeach; ?>
            <div class="form-text">Os avisos gerados nessas janelas ficam guardados e são enviados quando ela termina.</div>
        </div></div>

        <div class="card nc-card mb-3"><div class="card-body">
            <h2 class="h5"><i class="bi bi-volume-up me-1" aria-hidden="true"></i>Aparência no app</h2>
            <div class="row g-3 align-items-center">
                <div class="col-12 col-sm-6"><label class="form-label small mb-0" for="volume">Volume do som (<span data-volume-label><?= (int) round(($s['volume'] ?? 0.8) * 100) ?></span>%)</label><input type="range" class="form-range" id="volume" name="volume" min="0" max="1" step="0.1" value="<?= e((string) ($s['volume'] ?? 0.8)) ?>" data-volume></div>
                <div class="col-12 col-sm-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="vibrate" name="vibrate" value="1" <?= !empty($s['vibrate']) ? 'checked' : '' ?>><label class="form-check-label" for="vibrate">Vibrar no celular (contas atrasadas vibram mais)</label></div></div>
            </div>
        </div></div>

        <div class="d-flex justify-content-end mb-4"><button type="submit" class="btn btn-primary px-4">Salvar preferências</button></div>
    </form>

    <div class="card nc-card mb-3"><div class="card-body">
        <h2 class="h5"><i class="bi bi-phone me-1" aria-hidden="true"></i>Aparelhos (push)</h2>
        <?php if (!$pushReady): ?>
            <div class="alert alert-warning small mb-2">O Web Push ainda não está configurado neste servidor (chaves VAPID). Os avisos continuam na Central e por e-mail.</div>
        <?php elseif (!$consents['push']): ?>
            <p class="small text-body-secondary">Ligue o canal <strong>Push</strong> acima e salve para registrar este aparelho.</p>
        <?php else: ?>
            <p class="small text-body-secondary mb-2">Registre cada celular ou navegador em que quer receber avisos. No iPhone, instale o app na tela inicial primeiro (Compartilhar → Adicionar à Tela de Início).</p>
            <div class="d-flex flex-wrap gap-2 mb-3"><button type="button" class="btn btn-outline-primary" data-push-subscribe><i class="bi bi-bell-fill me-1" aria-hidden="true"></i>Ativar push neste aparelho</button><span class="small text-body-secondary align-self-center" data-push-status></span></div>
        <?php endif; ?>
        <?php if ($devices !== []): ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($devices as $d): ?>
                    <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-0">
                        <div class="flex-grow-1 min-w-0"><div><?= e($d['device_label'] ?: 'Aparelho') ?> <?php if ($d['disabled_at']): ?><span class="badge text-bg-secondary">desativado (falhas)</span><?php endif; ?></div><div class="small text-body-secondary">registrado em <?= e(datetime_br($d['created_at'])) ?><?= $d['last_success_at'] ? ' · último push ' . e(datetime_br($d['last_success_at'])) : '' ?></div></div>
                        <form method="post" action="<?= e(route('notifications.device.test', ['id' => $d['id']])) ?>" class="d-flex gap-1"><?= csrf_field() ?><select class="form-select form-select-sm w-auto" name="type" aria-label="Tipo do teste"><?php foreach ($types as $k => $m): ?><option value="<?= e($k) ?>"><?= e($m['label']) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-sm btn-outline-secondary" <?= $d['disabled_at'] ? 'disabled' : '' ?>>Testar aqui</button></form>
                        <form method="post" action="<?= e(route('notifications.device.remove', ['id' => $d['id']])) ?>" data-confirm="Remover este aparelho?"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-danger">Remover</button></form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div></div>
</div>
