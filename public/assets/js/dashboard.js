// public/assets/js/dashboard.js — gráficos do painel (Chart.js vendorizado). Lê os dados de <script type="application/json" id="dashboardData">.
(function () {
    'use strict';
    var dataEl = document.getElementById('dashboardData');
    if (!dataEl || !window.Chart) { return; }
    var data;
    try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }

    var css = getComputedStyle(document.body);
    var ink = css.color || '#212529';
    var muted = 'rgba(128,128,128,.55)';
    var grid = 'rgba(128,128,128,.18)';
    var INCOME = '#2563eb';   // azul e laranja: par validado para daltonismo em tema claro e escuro
    var EXPENSE = '#ea580c';
    var BRAND = css.getPropertyValue('--nc-brand').trim() || '#0f766e';

    var brl = function (v) { return 'R$ ' + Number(v).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var short = function (v) { v = Number(v); return v >= 1000 ? (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : v.toLocaleString('pt-BR', { maximumFractionDigits: 0 }); };

    Chart.defaults.color = muted;
    Chart.defaults.font.family = css.fontFamily;
    Chart.defaults.font.size = 11;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(20,24,32,.92)';
    Chart.defaults.plugins.tooltip.titleColor = '#fff';
    Chart.defaults.plugins.tooltip.bodyColor = '#fff';
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.displayColors = true;

    // 2) Receitas × despesas (12 meses): barras agrupadas, duas séries fixas, legenda sempre presente
    var seriesEl = document.getElementById('chartSeries');
    if (seriesEl && data.series) {
        new Chart(seriesEl, {
            type: 'bar',
            data: {
                labels: data.series.map(function (s) { return s.label; }),
                datasets: [
                    { label: 'Receitas', data: data.series.map(function (s) { return s.income; }), backgroundColor: INCOME, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 22 },
                    { label: 'Despesas', data: data.series.map(function (s) { return s.expense; }), backgroundColor: EXPENSE, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 22 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, color: ink } },
                    tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); }, footer: function (items) { if (items.length < 2) { return ''; } var d = items[0].parsed.y - items[1].parsed.y; return 'Sobra: ' + brl(d); } } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: muted } },
                    y: { beginAtZero: true, grid: { color: grid, drawBorder: false }, ticks: { color: muted, callback: short, maxTicksLimit: 5 }, border: { display: false } }
                }
            }
        });
    }

    // 3a) Despesas por categoria: barras horizontais, um só matiz (comparação de magnitude)
    var catEl = document.getElementById('chartCategories');
    if (catEl && data.by_category && data.by_category.length) {
        new Chart(catEl, {
            type: 'bar',
            data: {
                labels: data.by_category.map(function (c) { return c.name; }),
                datasets: [{ label: 'Despesas', data: data.by_category.map(function (c) { return c.amount; }), backgroundColor: BRAND, borderRadius: 4, borderSkipped: 'left', maxBarThickness: 18 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { var item = data.by_category[c.dataIndex]; return ' ' + brl(c.parsed.x) + ' (' + item.pct + '%)'; } } } },
                scales: { x: { beginAtZero: true, grid: { color: grid }, ticks: { callback: short, maxTicksLimit: 5 }, border: { display: false } }, y: { grid: { display: false }, ticks: { color: ink, autoSkip: false } } }
            }
        });
    }

    // 3b) Despesas por membro: cor do próprio membro (identidade), rótulo direto pela legenda de eixo
    var memEl = document.getElementById('chartMembers');
    if (memEl && data.by_member && data.by_member.length) {
        new Chart(memEl, {
            type: 'bar',
            data: {
                labels: data.by_member.map(function (m) { return m.name; }),
                datasets: [{ label: 'Despesas', data: data.by_member.map(function (m) { return m.amount; }), backgroundColor: data.by_member.map(function (m) { return m.color; }), borderRadius: 4, borderSkipped: 'left', maxBarThickness: 18 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + brl(c.parsed.x) + ' (' + data.by_member[c.dataIndex].pct + '%)'; } } } },
                scales: { x: { beginAtZero: true, grid: { color: grid }, ticks: { callback: short, maxTicksLimit: 5 }, border: { display: false } }, y: { grid: { display: false }, ticks: { color: ink } } }
            }
        });
    }

    // Relatório por categoria: evolução em 12 meses (linha única, matiz da marca)
    var evoEl = document.getElementById('chartEvolution');
    if (evoEl && data.evolution) {
        new Chart(evoEl, {
            type: 'line',
            data: { labels: data.evolution.map(function (r) { return r.label; }), datasets: [{ label: data.evolution_label || 'Valor', data: data.evolution.map(function (r) { return r.amount; }), borderColor: BRAND, backgroundColor: BRAND + '22', fill: true, tension: .25, borderWidth: 2, pointRadius: 4, pointHoverRadius: 6, pointBackgroundColor: BRAND }] },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + brl(c.parsed.y); } } } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: grid }, ticks: { callback: short, maxTicksLimit: 5 }, border: { display: false } } } }
        });
    }
    // Relatório anual: receitas × despesas por mês
    var annualEl = document.getElementById('chartAnnual');
    if (annualEl && data.annual) {
        new Chart(annualEl, {
            type: 'bar',
            data: { labels: data.annual.map(function (r) { return r.label; }), datasets: [
                { label: 'Receitas', data: data.annual.map(function (r) { return r.income; }), backgroundColor: INCOME, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 22 },
                { label: 'Despesas', data: data.annual.map(function (r) { return r.expense; }), backgroundColor: EXPENSE, borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 22 }
            ] },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'top', align: 'end', labels: { boxWidth: 10, usePointStyle: true, color: ink } }, tooltip: { callbacks: { label: function (c) { return ' ' + c.dataset.label + ': ' + brl(c.parsed.y); } } } },
                scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: grid }, ticks: { callback: short, maxTicksLimit: 5 }, border: { display: false } } } }
        });
    }
})();
