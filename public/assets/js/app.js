// public/assets/js/app.js — comportamento comum: tema, CSRF em fetch, service worker, toasts, utilitários de CSP
(function () {
    'use strict';

    var meta = function (name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') : '';
    };

    var NC = window.NC = {
        base: (meta('app-base') || '/').replace(/\/$/, ''),
        csrf: meta('csrf-token'),
        url: function (path) { return NC.base + '/' + String(path || '').replace(/^\//, ''); }
    };

    // --- Tema claro/escuro ---
    var applyTheme = function (theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        try { localStorage.setItem('nc-theme', theme); } catch (e) {}
        var icon = document.querySelector('#themeToggle i');
        if (icon) { icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; }
    };
    var toggle = document.getElementById('themeToggle');
    if (toggle) {
        var icon = toggle.querySelector('i');
        if (icon) { icon.className = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; }
        toggle.addEventListener('click', function () {
            applyTheme(document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark');
        });
    }

    // --- Atributos data-* no lugar de style inline (a CSP bloqueia style="" no HTML) ---
    NC.applyDataStyles = function (root) {
        root = root || document;
        root.querySelectorAll('[data-bg]').forEach(function (el) { el.style.backgroundColor = el.getAttribute('data-bg'); });
        root.querySelectorAll('[data-color]').forEach(function (el) { el.style.color = el.getAttribute('data-color'); });
        root.querySelectorAll('[data-width]').forEach(function (el) { el.style.width = el.getAttribute('data-width'); });
        root.querySelectorAll('[data-border]').forEach(function (el) { el.style.borderColor = el.getAttribute('data-border'); });
    };
    NC.applyDataStyles();

    // --- fetch com CSRF e JSON no padrão { ok, data, mensagem } ---
    NC.fetchJson = function (path, options) {
        options = options || {};
        var headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-Token': NC.csrf
        }, options.headers || {});
        var init = { method: options.method || 'GET', headers: headers, credentials: 'same-origin' };
        if (options.body !== undefined) {
            if (options.body instanceof FormData) {
                init.body = options.body;
            } else {
                headers['Content-Type'] = 'application/json';
                init.body = JSON.stringify(options.body);
            }
        }
        return fetch(path.indexOf('http') === 0 || path.indexOf('/') === 0 ? path : NC.url(path), init)
            .then(function (res) {
                return res.json().catch(function () {
                    return { ok: false, data: null, mensagem: 'Resposta inválida do servidor.' };
                }).then(function (json) {
                    json.status = res.status;
                    return json;
                });
            });
    };

    // --- Toasts (usados pela pré-visualização de avisos e por mensagens em tempo real) ---
    NC.toast = function (opts) {
        opts = opts || {};
        var area = document.getElementById('ncToastArea');
        if (!area || !window.bootstrap) { return; }
        var el = document.createElement('div');
        el.className = 'toast nc-toast';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        if (opts.color) { el.style.borderLeftColor = opts.color; }
        var title = document.createElement('strong');
        title.className = 'me-auto';
        title.textContent = opts.title || 'Nosso Cofre';
        var header = document.createElement('div');
        header.className = 'toast-header';
        header.appendChild(title);
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', 'Fechar');
        header.appendChild(close);
        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = opts.body || '';
        el.appendChild(header);
        el.appendChild(body);
        area.appendChild(el);
        var t = new bootstrap.Toast(el, { delay: opts.delay || 6000 });
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        t.show();
        if (opts.vibrate && navigator.vibrate) { try { navigator.vibrate(opts.vibrate); } catch (e) {} }
    };

    // --- Utilidades de tela ---
    document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var target = document.querySelector(btn.getAttribute('data-copy-target'));
            if (!target) { return; }
            var text = target.value !== undefined ? target.value : target.textContent;
            var done = function () { NC.toast({ title: 'Copiado', body: 'Conteúdo copiado para a área de transferência.' }); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () { target.select && target.select(); });
            } else if (target.select) { target.select(); document.execCommand('copy'); done(); }
        });
    });
    document.querySelectorAll('[data-reload]').forEach(function (btn) {
        btn.addEventListener('click', function () { window.location.reload(); });
    });
    // Formulários: evita duplo envio
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
        });
    });

    // --- Campos condicionais: data-show-when="campo=valor" ---
    document.querySelectorAll('[data-show-when]').forEach(function (block) {
        var rule = block.getAttribute('data-show-when').split('=');
        var inputs = document.querySelectorAll('[name="' + rule[0] + '"]');
        var update = function () {
            var value = null;
            inputs.forEach(function (i) { if ((i.type !== 'radio' && i.type !== 'checkbox') || i.checked) { value = i.value; } });
            block.classList.toggle('is-visible', value === rule[1]);
        };
        inputs.forEach(function (i) { i.addEventListener('change', update); });
        update();
    });
    // Confirmação antes de enviar formulários destrutivos
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!window.confirm(form.getAttribute('data-confirm'))) { ev.preventDefault(); }
        });
    });

    // --- Service worker (PWA) ---
    if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(NC.url('sw.js'), { scope: NC.base + '/' }).catch(function (err) {
                if (window.console) { console.warn('Service worker não registrado:', err); }
            });
        });
    }
})();
