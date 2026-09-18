// public/sw.js — service worker do Nosso Cofre (PWA + base para Web Push).
// Estratégia: assets em cache primeiro; páginas pela rede com fallback à página offline.
// Dados financeiros nunca são guardados em cache (respostas de rotas dinâmicas não são armazenadas).
var CACHE_VERSION = 'nc-v2';
var BASE = self.registration.scope.replace(/\/$/, '');
var OFFLINE_URL = BASE + '/offline';
var PRECACHE = [
    OFFLINE_URL,
    BASE + '/assets/css/app.css',
    BASE + '/assets/js/app.js',
    BASE + '/assets/img/favicon.svg',
    BASE + '/assets/img/icon-192.png',
    BASE + '/assets/vendor/chart.umd.js',
    BASE + '/assets/js/dashboard.js'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_VERSION).then(function (cache) {
            return Promise.all(PRECACHE.map(function (url) {
                return cache.add(url).catch(function () { /* asset opcional indisponível: segue */ });
            }));
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== CACHE_VERSION; }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var req = event.request;
    if (req.method !== 'GET') { return; }
    var url = new URL(req.url);
    var isAsset = url.origin === self.location.origin && url.pathname.indexOf(BASE + '/assets/') === 0;
    var isCdn = url.hostname === 'cdn.jsdelivr.net';

    if (isAsset || isCdn) {
        // Cache primeiro, atualiza em segundo plano
        event.respondWith(
            caches.match(req).then(function (cached) {
                var network = fetch(req).then(function (res) {
                    if (res && res.ok) {
                        var copy = res.clone();
                        caches.open(CACHE_VERSION).then(function (cache) { cache.put(req, copy); });
                    }
                    return res;
                }).catch(function () { return cached; });
                return cached || network;
            })
        );
        return;
    }

    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(function () {
                return caches.match(OFFLINE_URL).then(function (page) {
                    return page || new Response('<h1>Sem conexão</h1>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
                });
            })
        );
    }
});

// --- Web Push: cor (ícone/badge), vibração por tipo, tag para agrupar, ações "Ver" e "Marcar como pago" ---
self.addEventListener('push', function (event) {
    var payload = {};
    try { payload = event.data ? event.data.json() : {}; } catch (e) { payload = { title: 'Nosso Cofre', body: event.data ? event.data.text() : '' }; }
    var options = {
        body: payload.body || '',
        icon: payload.icon || (BASE + '/assets/img/icon-192.png'),
        badge: payload.badge || (BASE + '/assets/img/badge-96.png'),
        tag: payload.tag || 'nosso-cofre',
        renotify: !!payload.renotify,
        vibrate: (payload.vibrate && payload.vibrate.length) ? payload.vibrate : undefined,
        silent: false,
        data: { url: payload.url || (BASE + '/'), payUrl: payload.data && payload.data.payUrl ? payload.data.payUrl : null, sound: payload.sound || null, type: payload.type || null, color: payload.color || null, alertId: payload.alertId || null },
        actions: payload.actions || []
    };
    event.waitUntil(
        self.registration.showNotification(payload.title || 'Nosso Cofre', options).then(function () {
            // Aba aberta: toca o som configurado dentro do app
            return self.clients.matchAll({ type: 'window' }).then(function (list) { list.forEach(function (c) { c.postMessage({ type: 'notification-open', data: options.data }); }); });
        })
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    var target = (event.notification.data && event.notification.data.url) || (BASE + '/');
    if (event.action === 'pay' && event.notification.data && event.notification.data.payUrl) { target = event.notification.data.payUrl; }
    try { if (new URL(target, self.location.origin).origin !== self.location.origin) { target = BASE + '/'; } } catch (e) { target = BASE + '/'; }
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
            for (var i = 0; i < list.length; i++) {
                if (new URL(list[i].url).origin === self.location.origin && new URL(list[i].url).pathname.indexOf(BASE + '/') === 0 && 'focus' in list[i]) {
                    list[i].postMessage({ type: 'notification-open', data: event.notification.data });
                    list[i].navigate(target);
                    return list[i].focus();
                }
            }
            return self.clients.openWindow(target);
        })
    );
});
