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
        root.querySelectorAll('[data-height]').forEach(function (el) { el.style.height = el.getAttribute('data-height'); });
        root.querySelectorAll('[data-maxw]').forEach(function (el) { el.style.maxWidth = el.getAttribute('data-maxw'); });
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
        var raw = block.getAttribute('data-show-when');
        var negate = raw.indexOf('!=') !== -1;
        var rule = raw.split(negate ? '!=' : '=');
        var inputs = document.querySelectorAll('[name="' + rule[0] + '"]');
        var update = function () {
            var value = null;
            inputs.forEach(function (i) { if ((i.type !== 'radio' && i.type !== 'checkbox') || i.checked) { value = i.value; } });
            block.classList.toggle('is-visible', negate ? value !== rule[1] : value === rule[1]);
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

    // --- Fase 4: valores em R$, lançamento rápido, lote, importação, categorias ---
    NC.parseMoney = function (text) {
        var s = String(text || '').replace(/[R$\s ]/g, '');
        if (s === '') { return null; }
        var neg = s.charAt(0) === '-';
        s = s.replace(/^-/, '');
        if (s.indexOf(',') !== -1) { s = s.replace(/\./g, '').replace(',', '.'); }
        else if ((s.match(/\./g) || []).length > 1) { s = s.replace(/\./g, ''); }
        var n = parseFloat(s);
        if (isNaN(n)) { return null; }
        return neg ? -n : n;
    };
    NC.formatMoney = function (n) {
        var fixed = Math.abs(n).toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (n < 0 ? '-' : '') + parts.join(',');
    };
    document.querySelectorAll('input[data-money]').forEach(function (input) {
        input.addEventListener('blur', function () {
            var n = NC.parseMoney(input.value);
            if (n !== null) { input.value = NC.formatMoney(n); }
        });
    });

    var txForm = document.querySelector('[data-tx-form]');
    if (txForm) {
        var typeInputs = txForm.querySelectorAll('input[name="type"]');
        var currentType = function () { var t = 'expense'; typeInputs.forEach(function (i) { if (i.checked) { t = i.value; } }); return t; };
        var chipGroups = txForm.querySelectorAll('[data-chips]');
        var accountLabel = txForm.querySelector('[data-label-when]');
        var accountLabelDefault = accountLabel ? accountLabel.textContent : '';
        var syncType = function () {
            var t = currentType();
            chipGroups.forEach(function (g) {
                var on = g.getAttribute('data-chips') === t;
                g.classList.toggle('is-visible', on);
                if (!on) { g.querySelectorAll('input[type="radio"]').forEach(function (r) { r.checked = false; }); }
            });
            if (accountLabel) { accountLabel.textContent = t === accountLabel.getAttribute('data-label-when') ? accountLabel.getAttribute('data-label-text') : accountLabelDefault; }
            updateInstallments();
        };
        typeInputs.forEach(function (i) { i.addEventListener('change', syncType); });
        // Filtro de categorias
        var chipFilter = txForm.querySelector('[data-chip-filter]');
        if (chipFilter) {
            chipFilter.addEventListener('input', function () {
                var q = chipFilter.value.trim().toLowerCase();
                txForm.querySelectorAll('.nc-chip').forEach(function (chip) {
                    chip.classList.toggle('d-none', q !== '' && chip.getAttribute('data-chip-text').indexOf(q) === -1);
                });
            });
        }
        // Parcelas: prévia do valor de cada uma
        var amountInput = txForm.querySelector('#amount');
        var instInput = txForm.querySelector('[data-installments]');
        var instPreview = txForm.querySelector('[data-installments-preview]');
        var updateInstallments = function () {
            if (!instInput || !instPreview) { return; }
            var n = parseInt(instInput.value, 10) || 1;
            var total = NC.parseMoney(amountInput ? amountInput.value : '');
            instPreview.textContent = n <= 1 ? 'à vista' : (total ? n + '× R$ ' + NC.formatMoney(total / n) : n + '×');
        };
        if (instInput) { instInput.addEventListener('input', updateInstallments); }
        if (amountInput) { amountInput.addEventListener('blur', updateInstallments); }
        // Datas rápidas
        txForm.querySelectorAll('[data-set-date]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var d = new Date();
                d.setDate(d.getDate() + parseInt(btn.getAttribute('data-set-date'), 10));
                var pad = function (x) { return (x < 10 ? '0' : '') + x; };
                txForm.querySelector('#date').value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            });
        });
        // Sugestão de categoria aprendida
        var descInput = txForm.querySelector('[data-suggest-category]');
        var hint = txForm.querySelector('[data-suggest-hint]');
        var userPicked = false;
        txForm.querySelectorAll('input[name="category_id"]').forEach(function (r) { r.addEventListener('change', function () { userPicked = true; if (hint) { hint.hidden = true; } }); });
        if (descInput) {
            descInput.addEventListener('change', function () {
                var text = descInput.value.trim();
                if (text.length < 3 || userPicked || currentType() === 'transfer') { return; }
                NC.fetchJson(txForm.getAttribute('data-suggest-url') + '?descricao=' + encodeURIComponent(text)).then(function (json) {
                    if (!json.ok || !json.data || !json.data.category_id) { return; }
                    var radio = txForm.querySelector('input[name="category_id"][value="' + json.data.category_id + '"][data-kind="' + currentType() + '"]');
                    if (radio) { radio.checked = true; if (hint) { hint.hidden = false; } }
                });
            });
        }
        syncType();
    }

    // Seleção em lote na lista de lançamentos
    var bulkBar = document.querySelector('[data-bulk-bar]');
    if (bulkBar) {
        var items = document.querySelectorAll('[data-bulk-item]');
        var count = bulkBar.querySelector('[data-bulk-count]');
        var action = bulkBar.querySelector('[data-bulk-action]');
        var values = bulkBar.querySelectorAll('[data-bulk-value]');
        var refresh = function () {
            var n = 0; items.forEach(function (i) { if (i.checked) { n++; } });
            count.textContent = n;
            bulkBar.classList.toggle('d-none', n === 0);
        };
        var syncAction = function () {
            values.forEach(function (sel) {
                var on = sel.getAttribute('data-bulk-value') === action.value;
                sel.classList.toggle('d-none', !on);
                sel.disabled = !on;
                if (on) { sel.setAttribute('name', 'value'); } else { sel.removeAttribute('name'); }
            });
        };
        items.forEach(function (i) { i.addEventListener('change', refresh); });
        action.addEventListener('change', syncAction);
        bulkBar.querySelector('[data-bulk-clear]').addEventListener('click', function () { items.forEach(function (i) { i.checked = false; }); refresh(); });
        refresh(); syncAction();
    }

    // Importação: seleção rápida das linhas
    var importForm = document.querySelector('[data-import-form]');
    if (importForm) {
        var rows = importForm.querySelectorAll('[data-import-row]');
        var rowCount = importForm.querySelector('[data-import-count]');
        var recount = function () { var n = 0; rows.forEach(function (r) { if (r.checked) { n++; } }); rowCount.textContent = n; };
        rows.forEach(function (r) { r.addEventListener('change', recount); });
        importForm.querySelectorAll('[data-import-select]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mode = btn.getAttribute('data-import-select');
                rows.forEach(function (r) { r.checked = mode === 'all' || (mode === 'new' && r.getAttribute('data-duplicate') === '0'); });
                recount();
            });
        });
    }

    // Categorias: modal de edição preenchido a partir do botão; pai filtrado pelo tipo
    var editForm = document.querySelector('[data-edit-form]');
    if (editForm) {
        document.querySelectorAll('[data-edit-category]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var c = JSON.parse(btn.getAttribute('data-edit-category'));
                editForm.setAttribute('action', editForm.getAttribute('data-action-template').replace(/0(\/editar)$/, c.id + '$1'));
                editForm.querySelector('#e_name').value = c.name;
                editForm.querySelector('#e_icon').value = c.icon || 'tag';
                editForm.querySelector('#e_color').value = c.color || '#0f766e';
                editForm.querySelector('#e_essential').checked = c.is_essential === 1;
                editForm.querySelector('#e_active').checked = c.is_active === 1;
            });
        });
    }
    var kindSelect = document.getElementById('c_kind');
    var parentSelect = document.querySelector('[data-parent-select]');
    if (kindSelect && parentSelect) {
        var syncParents = function () {
            parentSelect.querySelectorAll('option[data-kind]').forEach(function (o) {
                var on = o.getAttribute('data-kind') === kindSelect.value;
                o.hidden = !on; o.disabled = !on;
                if (!on && o.selected) { parentSelect.value = ''; }
            });
        };
        kindSelect.addEventListener('change', syncParents); syncParents();
    }

    // --- Service worker (PWA) ---
    if ('serviceWorker' in navigator && window.location.protocol === 'https:') {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(NC.url('sw.js'), { scope: NC.base + '/' }).catch(function (err) {
                if (window.console) { console.warn('Service worker não registrado:', err); }
            });
        });
    }
})();
