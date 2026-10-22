/* Service worker de Resto Plaza Toro — habilita instalación (PWA) y carga offline del cascarón */
const CACHE = "ptoro-v1";
const CORE = ["./", "./index.html", "./manifest.webmanifest", "./icon-192.png", "./icon-512.png", "./apple-touch-icon.png"];

self.addEventListener("install", e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(CORE.filter(Boolean))).catch(()=>{}));
  self.skipWaiting();
});

self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))));
  self.clients.claim();
});

self.addEventListener("fetch", e => {
  const req = e.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  // No cachear llamadas a datos en vivo (Firestore / APIs); dejar pasar a la red.
  if (url.origin !== location.origin) return;
  // Navegaciones: red primero, con respaldo al cascarón en caché.
  if (req.mode === "navigate") {
    e.respondWith(fetch(req).catch(() => caches.match("./index.html").then(r => r || caches.match("./"))));
    return;
  }
  // Recursos propios: caché primero, luego red (y guarda copia).
  e.respondWith(
    caches.match(req).then(hit => hit || fetch(req).then(res => {
      try { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); } catch (_) {}
      return res;
    }).catch(() => hit))
  );
});
