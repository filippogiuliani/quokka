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
    // the sender's picture on the right, like Gmail (the quokka when there is none; without any Android draws a letter)
    icon: data.icon || new URL('quokka-icon.png', self.registration.scope).href,
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
    // Quokka already open: take it to that email (the address switches account if needed), else open it there
    const url = data.url || self.registration.scope;
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const open = wins.find((w) => w.url.startsWith(self.registration.scope));
    if (open) {
      try {
        const moved = 'navigate' in open ? await open.navigate(url) : null;
        return (moved || open).focus();
      } catch (e) { /* not ours to move: open a new one */ }
    }
    return self.clients.openWindow(url);
  })());
});

self.addEventListener('notificationclose', (event) => {
  const data = event.notification.data || {};
  if (!data.push && self.client) self.client.postMessage(JSON.stringify({ data, action: event.type }));
});
