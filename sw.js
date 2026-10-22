/* Service worker de Resto Plaza Toro — v3
   La página (index.html) SIEMPRE se busca en la red (nunca se sirve cacheada),
   para que los cambios se vean al instante. Solo se cachean íconos/estáticos. */
const CACHE = "ptoro-v3";
const ASSETS = ["./manifest.webmanifest", "./icon-192.png", "./icon-512.png", "./apple-touch-icon.png", "./icon-maskable-512.png"];

self.addEventListener("install", e => {
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS).catch(()=>{})));
});

self.addEventListener("activate", e => {
  e.waitUntil(
    caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e => {
  const req = e.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;              // deja pasar Supabase y otros orígenes
  // HTML / navegaciones: SIEMPRE red (sin caché), con respaldo mínimo si no hay red
  if (req.mode === "navigate" || req.destination === "document") {
    e.respondWith(fetch(req, { cache: "no-store" }).catch(() => caches.match("./manifest.webmanifest").then(() => new Response("<h1>Sin conexión</h1>", { headers: { "Content-Type": "text/html" } }))));
    return;
  }
  // Estáticos (íconos): caché primero
  e.respondWith(caches.match(req).then(hit => hit || fetch(req)));
});
