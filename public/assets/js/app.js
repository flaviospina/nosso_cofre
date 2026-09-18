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

    // --- Fase 5: modais de edição (orçamento, meta, ação), recorrência, simulador ---
    // Modal reutilizado para "novo" e "editar": data-edit-* traz o JSON; data-new-* limpa. Campos data-only-new somem na edição.
    var wireEditModal = function (formSelector, editAttr, newAttr, fill) {
        var form = document.querySelector(formSelector);
        if (!form) { return; }
        var title = form.querySelector('[data-modal-title]');
        var titleNew = title ? title.textContent : '';
        var setMode = function (editing, id) {
            form.setAttribute('action', editing ? form.getAttribute('data-update-template').replace(/0(\/editar)$/, id + '$1') : form.getAttribute('data-store-url'));
            form.querySelectorAll('[data-only-new]').forEach(function (el) {
                el.classList.toggle('d-none', editing);
                el.querySelectorAll('select, input').forEach(function (i) { i.disabled = editing; });
            });
            if (title) { title.textContent = editing ? titleNew.replace(/^Nov[oa]/, 'Editar') : titleNew; }
        };
        document.querySelectorAll('[' + editAttr + ']').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var data = JSON.parse(btn.getAttribute(editAttr));
                setMode(true, data.id);
                fill(form, data);
            });
        });
        document.querySelectorAll('[' + newAttr + ']').forEach(function (btn) {
            btn.addEventListener('click', function () { setMode(false, 0); form.reset(); });
        });
    };
    var setVal = function (form, name, value) { var el = form.querySelector('[name="' + name + '"]'); if (el) { el.value = value === null || value === undefined ? '' : value; } };
    wireEditModal('[data-budget-form]', 'data-edit-budget', 'data-new-budget', function (form, d) { setVal(form, 'limit_amount', d.limit); setVal(form, 'warn_at', d.warn); });
    wireEditModal('[data-goal-form]', 'data-edit-goal', 'data-new-goal', function (form, d) {
        setVal(form, 'name', d.name); setVal(form, 'target_amount', d.target); setVal(form, 'deadline', d.deadline);
        setVal(form, 'linked_account_id', d.account); setVal(form, 'user_id', d.user); setVal(form, 'color', d.color); setVal(form, 'icon', d.icon);
    });
    wireEditModal('[data-action-form]', 'data-edit-action', 'data-new-action', function (form, d) {
        setVal(form, 'title', d.title); setVal(form, 'description', d.description); setVal(form, 'responsible_user_id', d.responsible);
        setVal(form, 'category_id', d.category); setVal(form, 'estimated_saving_month', d.estimated);
    });
    // Orçamento: botão "usar a média dos 3 meses" conforme a categoria escolhida
    var budgetCategory = document.querySelector('[data-budget-category]');
    var useAverage = document.querySelector('[data-use-average]');
    if (budgetCategory && useAverage) {
        var syncAverage = function () {
            var opt = budgetCategory.options[budgetCategory.selectedIndex];
            var avg = opt ? opt.getAttribute('data-avg') : '';
            useAverage.hidden = !avg;
            useAverage.textContent = avg ? 'Usar a média dos 3 meses (R$ ' + avg + ')' : '';
        };
        budgetCategory.addEventListener('change', syncAverage); syncAverage();
        useAverage.addEventListener('click', function () {
            var opt = budgetCategory.options[budgetCategory.selectedIndex];
            var limit = document.getElementById('b_limit');
            if (opt && limit) { limit.value = opt.getAttribute('data-avg'); }
        });
    }
    // Recorrência: campos espelho (dia do mês da anual, N dias do personalizado) e categoria filtrada pelo tipo
    document.querySelectorAll('[data-mirror]').forEach(function (input) {
        var target = document.querySelector('[name="' + input.getAttribute('data-mirror') + '"]');
        if (!target) { return; }
        input.addEventListener('input', function () { target.value = input.value; });
    });
    var recForm = document.querySelector('[data-recurrence-form]');
    if (recForm) {
        var kindInputs = recForm.querySelectorAll('input[name="kind"]');
        var catSelect = recForm.querySelector('[data-kind-filter]');
        var syncKind = function () {
            var kind = 'expense'; kindInputs.forEach(function (i) { if (i.checked) { kind = i.value; } });
            catSelect.querySelectorAll('option[data-kind]').forEach(function (o) {
                var on = o.getAttribute('data-kind') === kind;
                o.hidden = !on; o.disabled = !on;
                if (!on && o.selected) { catSelect.value = ''; }
            });
        };
        kindInputs.forEach(function (i) { i.addEventListener('change', syncKind); }); syncKind();
    }

    // Selects que enviam o formulário ao mudar (filtros do painel e dos relatórios)
    document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
        sel.addEventListener('change', function () { if (sel.form) { sel.form.submit(); } });
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
