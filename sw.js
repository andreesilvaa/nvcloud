// Service Worker — StockVision PWA
// Como a app requer sempre ligação ao servidor, o SW serve apenas
// para permitir a instalação da PWA (sem cache offline).

const CACHE_NAME = 'stockvision-v1';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(clients.claim());
});

// Passa todos os pedidos diretamente ao servidor (sem cache)
self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
