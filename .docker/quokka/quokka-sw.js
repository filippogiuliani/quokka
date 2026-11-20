// Quokka's service worker, at the root of the site so it covers every page (no Service-Worker-Allowed needed).
// - push: the notifications of new emails the sorter sends, also with Quokka closed (sorter/push.py)
// - notificationclick / close: SnappyMail's own notifications, shown while Quokka is open, tell the page
'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('message', (event) => { self.client = event.source; });

self.addEventListener('push', (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch { data = { body: event.data ? event.data.text() : '' }; }
  event.waitUntil(self.registration.showNotification(data.title || 'Quokka', {
    body: data.body || '',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    // no large icon: Android already shows the app's own next to the notification; the face in white in the bar
    badge: new URL('quokka-badge.png', self.registration.scope).href,
    data: { url: data.url || self.registration.scope, push: true },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const data = event.notification.data || {};
  if (!data.push) {
    // shown by the page: the page knows which email to open
    if (self.client) self.client.postMessage(JSON.stringify({ data, action: event.type }));
    return;
  }
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = wins.find((w) => w.url.startsWith(self.registration.scope));
    if (open && 'focus' in open) return open.focus();       // Quokka already open: it refreshes the list by itself
    return self.clients.openWindow(data.url || self.registration.scope);
  })());
});

self.addEventListener('notificationclose', (event) => {
  const data = event.notification.data || {};
  if (!data.push && self.client) self.client.postMessage(JSON.stringify({ data, action: event.type }));
});
