// Service Worker for SurveyMate PWA
// v2: 認証フロー（/auth/*）を一切キャッシュ・介入しない．
//     消費済みの認可コード付き callback URL / 認可リダイレクトを SW が
//     再生すると，2回目以降の SSO ログインが invalid_grant で失敗するため．
const CACHE_NAME = 'surveymate-v2';
const urlsToCache = [
  './',
  './manifest.json',
  './favicon.ico'
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(urlsToCache))
      .then(() => self.skipWaiting())
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => name !== CACHE_NAME)
          .map((name) => caches.delete(name))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event - network first, fallback to cache
self.addEventListener('fetch', (event) => {
  // Skip non-GET requests
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // Skip API requests (don't cache)
  if (url.pathname.includes('/api/')) return;

  // Skip authentication flow (SSO redirect / callback).
  // These carry one-time credentials (code / state) and must never be
  // cached or replayed by the service worker.
  if (url.pathname.includes('/auth/')) return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Cache only successful same-origin responses (never redirects)
        if (response.ok && response.type === 'basic') {
          const responseToCache = response.clone();
          caches.open(CACHE_NAME).then((cache) => {
            cache.put(event.request, responseToCache);
          });
        }
        return response;
      })
      .catch(() => caches.match(event.request))
  );
});

// Push notification event
self.addEventListener('push', (event) => {
  let data = { title: 'SurveyMate', body: '新しい論文が追加されました' };

  if (event.data) {
    try {
      data = event.data.json();
    } catch (e) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body,
    icon: './icon-192.png',
    badge: './icon-192.png',
    vibrate: [100, 50, 100],
    data: {
      url: data.url || './',
      dateOfArrival: Date.now()
    },
    actions: [
      { action: 'open', title: '開く' },
      { action: 'close', title: '閉じる' }
    ],
    tag: 'surveymate-notification',
    renotify: true
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// Notification click event
self.addEventListener('notificationclick', (event) => {
  event.notification.close();

  if (event.action === 'close') return;

  const url = event.notification.data?.url || './';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then((clientList) => {
        // Check if there's already a window open
        for (const client of clientList) {
          if (client.url.includes('surveymate') && 'focus' in client) {
            return client.focus();
          }
        }
        // Open new window if none found
        if (clients.openWindow) {
          return clients.openWindow(url);
        }
      })
  );
});
