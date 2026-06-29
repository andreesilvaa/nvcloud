// Service Worker — StockVision PWA
// Como a app requer sempre ligação ao servidor, o SW serve apenas
// para permitir a instalação da PWA (sem cache offline).

const CACHE_NAME = "stockvision-v4";

self.addEventListener("install", (event) => {
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  event.waitUntil(clients.claim());
});

// Passa todos os pedidos diretamente ao servidor (sem cache)
self.addEventListener("fetch", (event) => {
  event.respondWith(fetch(event.request));
});
