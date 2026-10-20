/* GOLDEN TOUCH · Cloudflare Worker
 * D1 = base de datos. Un Durable Object ("Hub") serializa las escrituras y reparte los avisos en vivo por WebSocket.
 * Los archivos de la tienda se sirven como "assets" (gratis y sin pasar por el Worker).
 */
import seed from './seed.json';
import { makeLogic } from './logic.mjs';

const SESSION_DAYS = 30, MAXDOC = 1800000;
const enc = new TextEncoder();
const hex = b => [...new Uint8Array(b)].map(x => x.toString(16).padStart(2, '0')).join('');
const unhex = h => Uint8Array.from(h.match(/../g).map(x => parseInt(x, 16)));
const rand = n => hex(crypto.getRandomValues(new Uint8Array(n)));
const COLS = ['categories', 'products', 'coupons', 'zones', 'users', 'customers', 'orders', 'creditReqs', 'notifs', 'cashClosings', 'reviews', 'carts', 'costs'];

/* ---------------- esquema ---------------- */
const SCHEMA = [
  'CREATE TABLE IF NOT EXISTS docs(col TEXT NOT NULL,id TEXT NOT NULL,data TEXT,rev INTEGER NOT NULL,deleted INTEGER NOT NULL DEFAULT 0,k1 TEXT,k2 TEXT,act INTEGER,PRIMARY KEY(col,id))',
  'CREATE INDEX IF NOT EXISTS docs_rev ON docs(rev)',
  'CREATE INDEX IF NOT EXISTS docs_k1 ON docs(col,k1)',
  'CREATE INDEX IF NOT EXISTS docs_k2 ON docs(col,k2)',
  'CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY,v TEXT)',
  'CREATE TABLE IF NOT EXISTS secrets(kind TEXT NOT NULL,id TEXT NOT NULL,salt TEXT NOT NULL,hash TEXT NOT NULL,PRIMARY KEY(kind,id))',
  'CREATE TABLE IF NOT EXISTS sessions(sid TEXT PRIMARY KEY,kind TEXT NOT NULL,uid TEXT NOT NULL,exp INTEGER NOT NULL)',
  'CREATE TABLE IF NOT EXISTS throttle(ip TEXT PRIMARY KEY,n INTEGER NOT NULL,reset INTEGER NOT NULL)',
];

/* ---------------- utilidades D1 ---------------- */
const first = (env, sql, ...a) => env.DB.prepare(sql).bind(...a).first();
const rows = async (env, sql, ...a) => (await env.DB.prepare(sql).bind(...a).all()).results || [];
const getDoc = async (env, col, id) => { const r = await first(env, 'SELECT data FROM docs WHERE col=? AND id=? AND deleted=0', col, id); return r ? JSON.parse(r.data) : null; };
const listDocs = async (env, col) => (await rows(env, 'SELECT data FROM docs WHERE col=? AND deleted=0 ORDER BY rowid', col)).map(r => JSON.parse(r.data));
const getMeta = async (env, k) => { const r = await first(env, 'SELECT v FROM meta WHERE k=?', k); return r ? r.v : null; };
const hasSecret = async (env, kind, id) => !!(await first(env, 'SELECT 1 AS x FROM secrets WHERE kind=? AND id=?', kind, id));

function keysOf(col, d) {
  if (col === 'products') return [null, null, d.active === false ? 0 : 1];
  if (col === 'customers') return [d.id, d.ownerId || null, null];
  if (col === 'orders') return [d.customerId || null, d.employeeId || null, null];
  if (col === 'creditReqs') return [d.customerId || null, null, null];
  if (col === 'notifs') return [d.to || null, null, null];
  if (col === 'cashClosings') return [d.userId || null, null, null];
  if (col === 'reviews') return [d.customerId || null, null, null];
  if (col === 'users') return [d.id, null, null];
  return [null, null, null];
}
const putStmt = (env, col, id, obj, rev) => {
  const [k1, k2, act] = keysOf(col, obj);
  return env.DB.prepare('INSERT INTO docs(col,id,data,rev,deleted,k1,k2,act) VALUES(?,?,?,?,0,?,?,?) ON CONFLICT(col,id) DO UPDATE SET data=excluded.data,rev=excluded.rev,deleted=0,k1=excluded.k1,k2=excluded.k2,act=excluded.act')
    .bind(col, id, JSON.stringify(obj), rev, k1, k2, act);
};
const delStmt = (env, col, id, rev) => env.DB.prepare('UPDATE docs SET data=NULL,deleted=1,rev=? WHERE col=? AND id=?').bind(rev, col, id);
const metaStmt = (env, k, v) => env.DB.prepare('INSERT INTO meta(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v').bind(k, String(v));
const secStmt = async (env, kind, id, h) => { const salt = rand(16); return env.DB.prepare('INSERT INTO secrets(kind,id,salt,hash) VALUES(?,?,?,?) ON CONFLICT(kind,id) DO UPDATE SET salt=excluded.salt,hash=excluded.hash').bind(kind, id, salt, await pbkdf2(h, salt)); };

/* ---------------- contraseñas y sesiones ---------------- */
async function pbkdf2(h, saltHex) {
  const key = await crypto.subtle.importKey('raw', enc.encode(String(h)), 'PBKDF2', false, ['deriveBits']);
  return hex(await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: unhex(saltHex), iterations: 100000 }, key, 256));
}
const safeEq = (a, b) => { if (a.length !== b.length) return false; let r = 0; for (let i = 0; i < a.length; i++) r |= a.charCodeAt(i) ^ b.charCodeAt(i); return r === 0; };
async function checkSecret(env, kind, id, h) {
  const r = await first(env, 'SELECT salt,hash FROM secrets WHERE kind=? AND id=?', kind, id);
  if (!r || !h) return false;
  return safeEq(await pbkdf2(h, r.salt), r.hash);
}
const cookies = req => { const o = {}; String(req.headers.get('Cookie') || '').split(';').forEach(p => { const i = p.indexOf('='); if (i > 0) o[p.slice(0, i).trim()] = decodeURIComponent(p.slice(i + 1).trim()); }); return o; };
async function actorOf(env, req) {
  const anon = { kind: 'anon' }, sid = cookies(req).gt_sid;
  if (!sid) return anon;
  const s = await first(env, 'SELECT kind,uid,exp FROM sessions WHERE sid=?', sid);
  if (!s || s.exp < Date.now()) { if (s) await env.DB.prepare('DELETE FROM sessions WHERE sid=?').bind(sid).run(); return anon; }
  if (s.kind === 'user') { const u = await getDoc(env, 'users', s.uid); return u && u.active ? { kind: 'user', id: u.id, role: u.role, doc: u, sid } : anon; }
  if (s.kind === 'customer') { const k = await getDoc(env, 'customers', s.uid); return k ? { kind: 'customer', id: k.id, doc: k, sid } : anon; }
  return anon;
}
const isStaff = a => a.kind === 'user' && (a.role === 'admin' || a.role === 'gerente');
const isAdmin = a => a.kind === 'user' && a.role === 'admin';
const meOf = a => a.kind === 'user' ? { kind: 'user', id: a.id, role: a.role } : a.kind === 'customer' ? { kind: 'customer', id: a.id } : { kind: 'anon' };

function cookieHeader(sid, clear, url) {
  return `gt_sid=${clear ? '' : sid}; Path=/; HttpOnly; SameSite=Lax; Max-Age=${clear ? 0 : SESSION_DAYS * 86400}${url.protocol === 'https:' ? '; Secure' : ''}`;
}
const json = (obj, status = 200, headers = {}) => new Response(typeof obj === 'string' ? obj : JSON.stringify(obj), { status, headers: { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers } });

/* ---------------- visibilidad y sincronización ---------------- */
function visSql(a) {
  const pub = "(col IN('settings','categories','zones','reviews') OR (col='products' AND act=1))";
  if (isStaff(a)) return { sql: "(col<>'notifs' OR k1='staff')", args: [] };
  if (a.kind === 'customer') return { sql: `(${pub} OR (col IN('customers','orders','creditReqs','notifs') AND k1=?))`, args: [a.id] };
  if (a.kind === 'user') return { sql: `(${pub} OR (col='users' AND k1=?) OR (col IN('customers','orders') AND k2=?) OR (col='cashClosings' AND k1=?))`, args: [a.id, a.id, a.id] };
  return { sql: pub, args: [] };
}
function canSee(a, col, d) {
  if (col === 'settings' || col === 'categories' || col === 'zones' || col === 'reviews') return true;
  if (col === 'products') return isStaff(a) || d.active !== false;
  if (isStaff(a)) return col !== 'notifs' || d.to === 'staff';
  if (col === 'users') return a.kind === 'user' && d.id === a.id;
  if (col === 'customers') return (a.kind === 'customer' && d.id === a.id) || (a.kind === 'user' && a.role === 'vendedor' && d.ownerId === a.id);
  if (col === 'orders') return (a.kind === 'customer' && d.customerId === a.id) || (a.kind === 'user' && d.employeeId === a.id);
  if (col === 'creditReqs') return a.kind === 'customer' && d.customerId === a.id;
  if (col === 'cashClosings') return a.kind === 'user' && d.userId === a.id;
  if (col === 'notifs') return a.kind === 'customer' && d.to === a.id;
  return false;
}
const cleanOut = async (env, col, d) => {
  if (col === 'customers') { const x = { ...d }; if (await hasSecret(env, 'customer', d.id)) x.passHash = '1'; else delete x.passHash; return x; }
  return d;
};
async function syncRes(env, a, since) {
  const v = visSql(a);
  const stm = since > 0
    ? env.DB.prepare(`SELECT col,id,data,deleted,(${v.sql}) AS vis FROM docs WHERE rev>? ORDER BY rev,rowid`).bind(...v.args, since)
    : env.DB.prepare(`SELECT col,id,data,deleted,1 AS vis FROM docs WHERE rev>? AND deleted=0 AND ${v.sql} ORDER BY rowid`).bind(0, ...v.args);
  const [r1, r2, r3] = await env.DB.batch([
    env.DB.prepare("SELECT v FROM meta WHERE k='rev'"), env.DB.prepare("SELECT v FROM meta WHERE k='counter'"), stm]);
  const rev = +(r1.results[0] && r1.results[0].v) || 0, counter = +(r2.results[0] && r2.results[0].v) || 100;
  let acct = null;
  const parts = [];
  for (const r of r3.results) {
    const head = `{"col":${JSON.stringify(r.col)},"id":${JSON.stringify(r.id)},"doc":`;
    if (r.deleted || !r.vis) { if (since > 0) parts.push(head + 'null}'); continue; }
    if (r.col === 'customers') {
      if (!acct) acct = new Set((await rows(env, "SELECT id FROM secrets WHERE kind='customer'")).map(x => x.id));
      const d = JSON.parse(r.data); if (acct.has(d.id)) d.passHash = '1'; else delete d.passHash;
      parts.push(head + JSON.stringify(d) + '}');
    } else parts.push(head + r.data + '}'); // sin analizar: las imágenes no gastan CPU
  }
  return `{"rev":${rev},"counter":${counter},"me":${JSON.stringify(meOf(a))},"changes":[${parts.join(',')}]}`;
}

/* ---------------- errores ---------------- */
const fail = m => { const e = new Error(m); e.user = true; throw e; };
const plain = o => o && typeof o === 'object' && !Array.isArray(o);

/* ---------------- escritura (dentro del Durable Object) ---------------- */
async function lastAdminGuard(env, prev, next) {
  const admins = (await listDocs(env, 'users')).filter(u => u.role === 'admin' && u.active);
  const rest = admins.filter(u => !prev || u.id !== prev.id);
  const stays = next && next.role === 'admin' && next.active;
  if (prev && prev.role === 'admin' && prev.active && !stays && rest.length === 0) fail('Debe quedar al menos un administrador activo');
}

async function applyOp(hub, a, op) {
  const env = hub.env, { col, id } = op, doc = op.doc, S = []; let mail = null; // S = sentencias
  const rev = () => hub.nextRev();
  if (typeof id !== 'string' || !id || id.length > 64) fail('Identificador no válido');
  const isDel = doc === null || doc === undefined;
  if (!isDel) { if (!plain(doc) || (col !== 'settings' && doc.id !== id)) fail('Documento no válido'); if (JSON.stringify(doc).length > MAXDOC) fail('Documento demasiado grande (máximo 1,8 MB). Usa imágenes más livianas'); }
  const put = (c, i, o) => S.push(putStmt(env, c, i, o, rev()));
  const del = (c, i) => S.push(delStmt(env, c, i, rev()));
  let result = null;

  if (col === 'settings') {
    if (!isStaff(a)) fail('No tienes permiso'); if (id !== 'main' || isDel) fail('Ajuste no válido');
    put('settings', 'main', doc); result = doc;
  } else if (['categories', 'products', 'zones', 'coupons', 'costs'].includes(col)) {
    if (!isStaff(a)) fail('No tienes permiso');
    if (isDel) del(col, id); else { put(col, id, doc); result = doc; }
  } else if (col === 'users') {
    if (!isAdmin(a)) fail('Solo el administrador gestiona el equipo');
    const prev = await getDoc(env, 'users', id);
    if (isDel) { await lastAdminGuard(env, prev, null); del('users', id); S.push(env.DB.prepare("DELETE FROM secrets WHERE kind='user' AND id=?").bind(id)); }
    else {
      if (!['admin', 'gerente', 'cajero', 'vendedor'].includes(doc.role)) fail('Rol no válido');
      const nd = { ...doc }; const ph = nd.passHash; delete nd.passHash;
      if (ph && ph !== '1') S.push(await secStmt(env, 'user', id, ph)); else if (!prev) fail('Falta la contraseña');
      for (const u of await listDocs(env, 'users')) {
        if (u.id !== id && String(u.user).toLowerCase() === String(nd.user).toLowerCase()) fail('Ese usuario ya existe');
        if (u.id !== id && String(u.code).toUpperCase() === String(nd.code).toUpperCase()) fail('Ese código ya existe');
      }
      await lastAdminGuard(env, prev, nd); put('users', id, nd); result = nd;
    }
  } else if (col === 'customers') {
    const prev = await getDoc(env, 'customers', id);
    if (isDel) { if (!isStaff(a)) fail('No tienes permiso'); del('customers', id); S.push(env.DB.prepare("DELETE FROM secrets WHERE kind='customer' AND id=?").bind(id)); }
    else if (isStaff(a) || (a.kind === 'user' && a.role === 'vendedor' && (!prev || prev.ownerId === a.id))) {
      const nd = { ...doc }; if (a.role === 'vendedor') nd.ownerId = a.id;
      const ph = nd.passHash; delete nd.passHash; if (ph && ph !== '1') S.push(await secStmt(env, 'customer', id, ph));
      put('customers', id, nd); result = await cleanOut(env, 'customers', nd);
    } else if (a.kind === 'customer' && a.id === id && prev) {
      const nd = { ...prev };
      for (const f of ['name', 'phone', 'address']) if (typeof doc[f] === 'string') nd[f] = doc[f].slice(0, 200);
      if (doc.passHash && doc.passHash !== '1') S.push(await secStmt(env, 'customer', id, doc.passHash));
      put('customers', id, nd); result = await cleanOut(env, 'customers', nd);
    } else fail('No tienes permiso');
  } else if (col === 'orders') {
    const prev = await getDoc(env, 'orders', id);
    if (!prev && !isDel) return placeOrder(hub, a, doc);
    if (!isStaff(a)) fail('No tienes permiso');
    if (isDel) del('orders', id); else {
      put('orders', id, doc); result = doc;
      const sh0 = (prev && prev.ship) || {}, sh1 = doc.ship || {};
      if (sh1.stage && sh1.at !== sh0.at) mail = { ord: doc, kind: sh1.stage };
      else if (prev && prev.status !== doc.status && ['pagado', 'entregado'].includes(doc.status)) mail = { ord: doc, kind: doc.status };
    }
  } else if (col === 'creditReqs') {
    const prev = await getDoc(env, 'creditReqs', id);
    if (a.kind === 'customer' && !prev && !isDel) {
      const amt = Math.round(+doc.amount || 0); if (amt < 1) fail('Monto no válido');
      const pend = await first(env, "SELECT 1 AS x FROM docs WHERE col='creditReqs' AND k1=? AND deleted=0 AND json_extract(data,'$.status')='pendiente'", a.id);
      if (pend) fail('Ya tienes una solicitud en revisión');
      const nd = { id, customerId: a.id, amount: amt, note: String(doc.note || '').slice(0, 200), status: 'pendiente', date: Date.now() };
      put('creditReqs', id, nd); result = nd;
    } else {
      if (!isStaff(a)) fail('No tienes permiso');
      if (isDel) del('creditReqs', id); else { put('creditReqs', id, doc); result = doc; }
    }
  } else if (col === 'reviews') {
    if (isDel) { if (!isAdmin(a)) fail('Solo el administrador puede borrar reseñas'); del('reviews', id); }
    else {
      if (await getDoc(env, 'reviews', id)) fail('Las reseñas no se pueden editar');
      if (a.kind !== 'customer') fail('Solo las clientas registradas pueden dejar una reseña');
      const cu = await getDoc(env, 'customers', a.id); if (!cu) fail('Cuenta no válida');
      if (cu.reviewed || await first(env, "SELECT 1 AS x FROM docs WHERE col='reviews' AND k1=? AND deleted=0", a.id)) fail('Ya dejaste tu reseña');
      const rating = Math.round(+doc.rating), text = String(doc.text || '').trim().slice(0, 400);
      if (!(rating >= 1 && rating <= 5)) fail('Elige de 1 a 5 estrellas');
      if (!text) fail('Escribe tu opinión');
      const img = typeof doc.img === 'string' && doc.img.length <= 300000 && /^data:image\/(webp|jpeg|png);base64,[A-Za-z0-9+\/=]+$/.test(doc.img) ? doc.img : '';
      const pp = String(cu.name || '').trim().split(/\s+/);
      const nd = { id, customerId: a.id, name: (pp[0] || 'Clienta') + (pp[1] ? ' ' + pp[1][0].toUpperCase() + '.' : ''), rating, text, date: Date.now() };
      if (img) nd.img = img;
      put('reviews', id, nd); put('customers', a.id, { ...cu, reviewed: true }); result = nd;
    }
  } else if (col === 'cashClosings') {
    if (isDel) { if (!isAdmin(a)) fail('No tienes permiso'); del('cashClosings', id); }
    else {
      if (a.kind !== 'user') fail('No tienes permiso');
      const prev = await getDoc(env, 'cashClosings', id);
      if (!prev) {
        if (await first(env, "SELECT 1 AS x FROM docs WHERE col='cashClosings' AND k1=? AND deleted=0 AND json_extract(data,'$.status')='abierta'", a.id)) fail('Ya tienes una caja abierta');
        const nd = { id, userId: a.id, userName: a.doc.name, openedAt: Date.now(), openingFloat: Math.max(0, Math.round(+doc.openingFloat || 0)), status: 'abierta', closedAt: null };
        put('cashClosings', id, nd); result = nd;
      } else {
        if (prev.userId !== a.id && !isStaff(a)) fail('No tienes permiso');
        if (prev.status === 'cerrada') fail('Esa caja ya está cerrada');
        if (doc.status !== 'cerrada') result = prev;
        else {
          const counted = Math.round(+doc.counted); if (!(counted >= 0)) fail('Escribe el efectivo contado');
          const by = { efectivo: 0, tarjeta: 0, transferencia: 0 }; let total = 0, count = 0;
          for (const r of await rows(env, "SELECT data FROM docs WHERE col='orders' AND deleted=0 AND json_extract(data,'$.cashId')=? AND json_extract(data,'$.status')<>'cancelado'", id)) { const o = JSON.parse(r.data); by[o.payMethod] = (by[o.payMethod] || 0) + o.total; total += o.total; count++; }
          const expected = prev.openingFloat + by.efectivo;
          const nd = { ...prev, status: 'cerrada', closedAt: Date.now(), counted, expected, diff: counted - expected, note: String(doc.note || '').slice(0, 200), totals: { by, total, count } };
          put('cashClosings', id, nd); result = nd;
        }
      }
    }
  } else if (col === 'notifs') {
    const prev = await getDoc(env, 'notifs', id);
    const mine = d => d && ((d.to === 'staff' && isStaff(a)) || (a.kind === 'customer' && d.to === a.id));
    if (!prev && !isDel) {
      const ok = isStaff(a) || ((a.kind === 'customer' || (a.kind === 'user' && a.role === 'vendedor')) && doc.to === 'staff');
      if (!ok) fail('No tienes permiso');
      const nd = { id, to: String(doc.to).slice(0, 64), title: String(doc.title || '').slice(0, 200), body: String(doc.body || '').slice(0, 600), go: String(doc.go || '').slice(0, 40), ts: Date.now(), read: false };
      put('notifs', id, nd); result = nd;
    } else if (prev) {
      if (!(isStaff(a) || mine(prev))) fail('No tienes permiso');
      if (isDel) del('notifs', id); else { const nd = { ...prev, read: !!doc.read }; put('notifs', id, nd); result = nd; }
    }
  } else if (col === 'carts') {
    if (!isStaff(a)) fail('No tienes permiso');
    const prev = await getDoc(env, 'carts', id);
    if (isDel) del('carts', id); else {
      if (!prev) fail('El carrito ya no existe');
      const nd = { ...prev, status: ['open', 'dismissed'].includes(doc.status) ? doc.status : prev.status, sent: Array.isArray(doc.sent) ? doc.sent.slice(0, 20).map(x => ({ ch: x.ch === 'wa' ? 'wa' : 'mail', at: +x.at || Date.now() })) : prev.sent };
      if (doc.mailNow && doc.mailNow !== prev.mailNow) { nd.mailNow = +doc.mailNow; nd.mailUrl = String(doc.mailUrl || '').slice(0, 300); mail = { cart: nd }; }
      put('carts', id, nd); result = nd;
    }
  } else fail('Colección no válida');
  return { stmts: S, result, mail };
}

/* ---------------- correos (Resend) ---------------- */
const MAIL_RX = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
async function mailOrder(env, ord, kind) {
  if (!env.RESEND_API_KEY || !env.MAIL_FROM || !ord || !ord.customer || !MAIL_RX.test(String(ord.customer.email || ''))) return;
  const settings = (await getDoc(env, 'settings', 'main')) || {};
  if (!settings.mailOn) return;
  const L = makeLogic(); L.setState({ settings });
  const m = L.emailOrder(ord, kind);
  const r = await fetch(env.RESEND_URL || 'https://api.resend.com/emails', { method: 'POST', headers: { Authorization: 'Bearer ' + env.RESEND_API_KEY, 'Content-Type': 'application/json' }, body: JSON.stringify({ from: env.MAIL_FROM, to: [ord.customer.email], subject: m.subject, html: m.html, text: m.text }) });
  if (!r.ok) console.error('correo', r.status, (await r.text()).slice(0, 200));
}

async function waOrder(env, ord, kind) {
  try {
    if (!env.WA_TOKEN || !env.WA_PHONE_ID || !ord || !ord.customer || ord.channel === 'mostrador') return;
    const st = (await getDoc(env, 'settings', 'main')) || {};
    if (!st.waOn || !(st.waKinds || {})[kind]) return;
    const L = makeLogic(); L.setState({ settings: st, products: [], customers: [], orders: [] });
    const to = L.phoneDigits(String(ord.customer.phone || '')); if (String(to).length < 10) return;
    const parts = L.waParts(ord, kind), clean = t => String(t || '—').replace(/[\n\t]+/g, ' ').replace(/ {4,}/g, '   ').slice(0, 300);
    let body;
    if (st.waMode === 'text') body = { messaging_product: 'whatsapp', to, type: 'text', text: { body: parts.text.slice(0, 1000) } };
    else {
      const name = (st.waTpl || {})[kind]; if (!name) return;
      body = { messaging_product: 'whatsapp', to, type: 'template', template: { name, language: { code: st.waLang || 'es' }, components: [{ type: 'body', parameters: [parts.name, parts.num, parts.detail].map(t => ({ type: 'text', text: clean(t) })) }] } };
    }
    const r = await fetch(env.WA_URL || 'https://graph.facebook.com/v21.0/' + env.WA_PHONE_ID + '/messages', { method: 'POST', headers: { Authorization: 'Bearer ' + env.WA_TOKEN, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    if (!r.ok) console.error('whatsapp', r.status, (await r.text()).slice(0, 200));
  } catch (e) { console.error('whatsapp', e && e.message); }
}
async function mailCart(env, c) {
  if (!env.RESEND_API_KEY || !env.MAIL_FROM || !c || !MAIL_RX.test(String(c.email || ''))) return false;
  const settings = (await getDoc(env, 'settings', 'main')) || {};
  if (!settings.mailOn) return false;
  const url = (c.mailUrl && /^https?:\/\/[^\s<>"']{4,300}$/.test(c.mailUrl) ? c.mailUrl : '') || String(settings.storeUrl || '');
  if (!/^https?:\/\//.test(url)) return false;
  const L = makeLogic(); L.setState({ settings, products: [] });
  const m = L.emailCart({ ...c, url });
  const r = await fetch(env.RESEND_URL || 'https://api.resend.com/emails', { method: 'POST', headers: { Authorization: 'Bearer ' + env.RESEND_API_KEY, 'Content-Type': 'application/json' }, body: JSON.stringify({ from: env.MAIL_FROM, to: [c.email], subject: m.subject, html: m.html, text: m.text }) });
  if (!r.ok) console.error('correo', r.status, (await r.text()).slice(0, 200));
  return r.ok;
}
async function friendCheck(env, code, c) {
  const settings = (await getDoc(env, 'settings', 'main')) || {};
  if (!settings.refOn) return { err: 'Los códigos de amiga no están activos' };
  const K = (await rows(env, "SELECT json_extract(data,'$.id') AS id,json_extract(data,'$.name') AS name,json_extract(data,'$.phone') AS phone,json_extract(data,'$.email') AS email FROM docs WHERE col='customers' AND deleted=0"));
  const W = new Set((await rows(env, "SELECT DISTINCT k1 FROM docs WHERE col='orders' AND deleted=0 AND k1 IS NOT NULL AND json_extract(data,'$.status')<>'cancelado'")).map(r => r.k1));
  const L = makeLogic(); L.setState({ settings, products: [] });
  return L.refResolve(code, { phone: String((c && c.phone) || ''), email: String((c && c.email) || '') }, K, id => W.has(id));
}
async function rateD1(env, key, max) {
  const now = Date.now(), f = await first(env, 'SELECT n,reset FROM throttle WHERE ip=?', key);
  if (!f || f.reset < now) { await env.DB.prepare('INSERT INTO throttle(ip,n,reset) VALUES(?,1,?) ON CONFLICT(ip) DO UPDATE SET n=1,reset=excluded.reset').bind(key, now + 60000).run(); return false; }
  await env.DB.prepare('UPDATE throttle SET n=n+1 WHERE ip=?').bind(key).run(); return f.n + 1 > max;
}

/* Un pedido se vuelve a calcular en el servidor con la MISMA lógica del cliente. */
async function placeOrder(hub, a, d) {
  const env = hub.env, inp = plain(d._in) ? d._in : {};
  const items = (Array.isArray(inp.items) ? inp.items : []).map(i => ({ pid: String(i.pid), qty: Math.floor(+i.qty) })).filter(i => i.qty > 0 && i.qty < 1000);
  if (!items.length) fail('Agrega al menos un producto');
  if (!['presencial', 'domicilio', 'transferencia', 'online', 'contraentrega'].includes(inp.method)) fail('Elige cómo recibirás tu compra');
  const c = inp.customer || {};
  const counter = inp.channel === 'mostrador';
  if (counter) {
    if (a.kind !== 'user') fail('No tienes permiso');
    const cj = await getDoc(env, 'cashClosings', String(inp.cashId || ''));
    if (!cj || cj.status !== 'abierta' || cj.userId !== a.id) fail('Abre tu caja para vender en el mostrador');
    if (!['efectivo', 'tarjeta', 'transferencia'].includes(inp.payMethod)) fail('Elige la forma de pago');
  } else if (!String(c.name || '').trim() || !String(c.phone || '').trim()) fail('Escribe tu nombre y teléfono');

  // estado mínimo para la lógica (sin imágenes)
  const S = { v: 2, counter: +(await getMeta(env, 'counter')) || 100, session: null, custSession: null, ref: null, cart: [], prefs: {}, favs: {}, creditReqs: [], notifs: [], orders: [] };
  S.settings = (await getDoc(env, 'settings', 'main')) || {};
  S.products = (await rows(env, "SELECT json_remove(data,'$.images') AS data FROM docs WHERE col='products' AND deleted=0 ORDER BY rowid")).map(r => JSON.parse(r.data));
  for (const col of ['categories', 'coupons', 'zones', 'users']) S[col] = await listDocs(env, col);
  S.users = S.users.map(u => ({ ...u, passHash: '1' }));
  let acctId = null;
  if (a.kind === 'customer') acctId = a.id;
  else if (a.kind === 'user') { const k = inp.customerId && (await getDoc(env, 'customers', inp.customerId)); if (k && await hasSecret(env, 'customer', k.id)) acctId = k.id; }
  S.customers = (await rows(env, "SELECT data FROM docs WHERE col='customers' AND deleted=0 AND id NOT IN (SELECT id FROM secrets WHERE kind='customer') ORDER BY rowid")).map(r => JSON.parse(r.data));
  if (acctId) { const k = await getDoc(env, 'customers', acctId); if (k) { k.passHash = '1'; S.customers.push(k); S.orders = (await rows(env, "SELECT data FROM docs WHERE col='orders' AND k1=? AND deleted=0", acctId)).map(r => JSON.parse(r.data)); } }
  if (a.kind === 'user') S.session = a.id;
  let friendCtx;
  if (S.settings.refOn && String(inp.friendCode || '').trim()) {
    const r = await friendCheck(env, inp.friendCode, c);
    if (r.err) fail(r.err);
    friendCtx = r;
    const ow = await getDoc(env, 'customers', r.owner.id); if (ow && !S.customers.some(k => k.id === ow.id)) S.customers.push({ ...ow });
  }

  const orderIn = {
    items, customer: { name: String(c.name).slice(0, 120), phone: String(c.phone).slice(0, 40), email: String(c.email || '').slice(0, 120), address: String(c.address || '').slice(0, 240) },
    method: inp.method, zoneId: inp.zoneId || '', couponCode: String(inp.couponCode || '').slice(0, 40), refCode: String(inp.refCode || '').slice(0, 40), note: String(inp.note || '').slice(0, 400), cur: String(inp.cur || '').slice(0, 3),
    payType: inp.payType === 'credito' ? 'credito' : 'contado', installments: +inp.installments || 0, down: +inp.down || 0,
    pickup: !!inp.pickup,
    friendCode: S.settings.refOn ? String(inp.friendCode || '').slice(0, 20) : '', friendCtx, usePts: !!inp.usePts,
    paid: a.kind === 'user' && (counter || !!inp.paid), channel: counter ? 'mostrador' : '', payMethod: counter ? inp.payMethod : '', cashId: counter ? String(inp.cashId) : null, userId: a.kind === 'user' ? a.id : null, customerId: acctId,
  };
  const bStock = new Map(S.products.map(p => [p.id, p.stock])), bC = new Map(S.coupons.map(x => [x.id, JSON.stringify(x)])), bK = new Map(S.customers.map(x => [x.id, JSON.stringify(x)]));
  const L = makeLogic(); L.setState(S); L.migrate(); L.setState(S);
  let ord;
  try { ord = L.createOrder(orderIn); } catch (e) { fail(e.message || 'No se pudo crear el pedido'); }
  if (/^[A-Za-z0-9_-]{3,40}$/.test(d.id)) ord.id = d.id;

  const st = [];
  for (const p of S.products) if (bStock.get(p.id) !== p.stock) st.push(env.DB.prepare("UPDATE docs SET data=json_set(data,'$.stock',?),rev=? WHERE col='products' AND id=?").bind(p.stock, hub.nextRev(), p.id));
  for (const x of S.coupons) if (bC.get(x.id) !== JSON.stringify(x)) st.push(putStmt(env, 'coupons', x.id, x, hub.nextRev()));
  for (const k of S.customers) if (bK.get(k.id) !== JSON.stringify(k)) { const kk = { ...k }; delete kk.passHash; st.push(putStmt(env, 'customers', k.id, kk, hub.nextRev())); }
  st.push(metaStmt(env, 'counter', S.counter));
  for (const n of S.notifs) st.push(putStmt(env, 'notifs', n.id, n, hub.nextRev()));
  st.push(putStmt(env, 'orders', ord.id, ord, hub.nextRev()));
  const cid = /^[A-Za-z0-9]{6,40}$/.test(String(inp.cartId || '')) ? 'cart_' + inp.cartId : null;
  const ph = String((ord.customer && ord.customer.phone) || '').replace(/\D/g, '').slice(-10), em = String((ord.customer && ord.customer.email) || '').toLowerCase();
  for (const r of await rows(env, "SELECT data FROM docs WHERE col='carts' AND deleted=0 AND json_extract(data,'$.status')='open'")) {
    const cc = JSON.parse(r.data);
    if (cc.id === cid || (ph.length >= 7 && String(cc.phone || '').replace(/\D/g, '').slice(-10) === ph) || (em && String(cc.email || '').toLowerCase() === em))
      st.push(putStmt(env, 'carts', cc.id, { ...cc, status: 'bought', rec: (cc.sent || []).length > 0, orderTotal: ord.total, boughtAt: Date.now() }, hub.nextRev()));
  }
  return { stmts: st, result: ord, mail: { ord, kind: 'nuevo' } };
}

/* ---------------- siembra inicial ---------------- */
async function seedIfEmpty(hub) {
  const env = hub.env;
  if (await getMeta(env, 'seeded')) return;
  const st = [];
  st.push(putStmt(env, 'settings', 'main', seed.settings || {}, hub.nextRev()));
  for (const c of COLS) for (const d of (seed[c] || [])) {
    const o = { ...d }; const ph = o.passHash; delete o.passHash;
    if (ph && (c === 'users' || c === 'customers')) st.push(await secStmt(env, c === 'users' ? 'user' : 'customer', o.id, ph));
    st.push(putStmt(env, c, o.id, o, hub.nextRev()));
  }
  st.push(metaStmt(env, 'counter', seed.counter || 100));
  st.push(metaStmt(env, 'seeded', '1'));
  st.push(metaStmt(env, 'rev', hub.rev));
  await env.DB.batch(st);
}

/* ---------------- Durable Object ---------------- */
export class Hub {
  constructor(ctx, env) { this.ctx = ctx; this.env = env; this.ready = null; this.chain = Promise.resolve(); this.rev = 0; }
  nextRev() { return ++this.rev; }
  async reloadRev() { this.rev = +(await getMeta(this.env, 'rev')) || 0; }
  init() {
    if (!this.ready) this.ready = (async () => { await this.env.DB.batch(SCHEMA.map(s => this.env.DB.prepare(s))); await this.reloadRev(); await seedIfEmpty(this); })().catch(e => { this.ready = null; throw e; });
    return this.ready;
  }
  lock(fn) { const run = this.chain.then(fn, fn); this.chain = run.catch(() => {}); return run; }
  broadcast() { const m = JSON.stringify({ rev: this.rev }); for (const ws of this.ctx.getWebSockets()) { try { ws.send(m); } catch (e) {} } }
  webSocketMessage(ws, msg) { if (msg === 'ping') { try { ws.send('pong'); } catch (e) {} } }
  webSocketClose(ws) { try { ws.close(); } catch (e) {} }
  async fetch(req) {
    const url = new URL(req.url);
    if (req.headers.get('Upgrade') === 'websocket') { const pair = new WebSocketPair(); this.ctx.acceptWebSocket(pair[1]); return new Response(null, { status: 101, webSocket: pair[0] }); }
    await this.init();
    if (url.pathname === '/api/init') return json({ ok: true });
    return this.lock(() => handleWrite(this, req, url));
  }
}

async function readJson(req) { try { return await req.json(); } catch (e) { return {}; } }
const clientIp = req => String(req.headers.get('CF-Connecting-IP') || req.headers.get('x-forwarded-for') || '').split(',')[0].trim() || 'x';
async function throttled(env, ip) { const f = await first(env, 'SELECT n,reset FROM throttle WHERE ip=?', ip); return !!(f && f.reset > Date.now() && f.n >= 10); }
async function noteFail(env, ip) {
  const f = await first(env, 'SELECT n,reset FROM throttle WHERE ip=?', ip);
  if (!f || f.reset < Date.now()) await env.DB.prepare('INSERT INTO throttle(ip,n,reset) VALUES(?,1,?) ON CONFLICT(ip) DO UPDATE SET n=1,reset=excluded.reset').bind(ip, Date.now() + 600000).run();
  else await env.DB.prepare('UPDATE throttle SET n=n+1 WHERE ip=?').bind(ip).run();
}
const newSession = async (env, kind, uid) => { const sid = rand(32); await env.DB.prepare('INSERT INTO sessions(sid,kind,uid,exp) VALUES(?,?,?,?)').bind(sid, kind, uid, Date.now() + SESSION_DAYS * 864e5).run(); return sid; };

async function autoCarts(hub) {
  const env = hub.env, st = (await getDoc(env, 'settings', 'main')) || {};
  if (!st.cartOn || !st.cartAuto || !st.mailOn || !/^https?:\/\//.test(String(st.storeUrl || ''))) return;
  const now = Date.now(), delay = (+st.cartDelay || 1) * 36e5, all = await listDocs(env, 'carts');
  let n = 0;
  for (const c of all) {
    if (n >= 15) break;
    if (c.status !== 'open' || (c.sent || []).length || !MAIL_RX.test(String(c.email || '')) || !(c.items || []).length) continue;
    if (now - c.updatedAt < delay || now - c.updatedAt > 7 * 864e5) continue;
    const em = String(c.email).toLowerCase();
    if (all.some(x => x.id !== c.id && String(x.email || '').toLowerCase() === em && (x.sent || []).some(y => now - y.at < 7 * 864e5))) continue;
    if (await mailCart(env, { ...c, mailUrl: '' })) { n++; await env.DB.batch([putStmt(env, 'carts', c.id, { ...c, sent: [{ ch: 'mail', at: now, auto: true }] }, hub.nextRev()), metaStmt(env, 'rev', hub.rev)]); }
  }
  if (n) hub.broadcast();
}
async function handleWrite(hub, req, url) {
  const env = hub.env, p = url.pathname, a = await actorOf(env, req);
  if (p === '/api/ops') {
    const b = await readJson(req), ops = Array.isArray(b.ops) ? b.ops.slice(0, 200) : [], results = []; let changed = false;
    for (const op of ops) {
      try {
        const { stmts, result, mail } = await applyOp(hub, a, op);
        if (stmts.length) { stmts.push(metaStmt(env, 'rev', hub.rev)); await env.DB.batch(stmts); changed = true; }
        if (mail) (mail.cart ? mailCart(env, mail.cart) : Promise.all([mailOrder(env, mail.ord, mail.kind), waOrder(env, mail.ord, mail.kind)])).catch(e => console.error('correo', e && e.message));
        results.push({ col: op.col, id: op.id, ok: true, doc: result ?? null });
      } catch (e) {
        await hub.reloadRev();
        if (!e.user) console.error('op error', op.col, e && e.stack || e);
        const cur = op.col === 'settings' ? await getDoc(env, 'settings', 'main') : await getDoc(env, op.col, op.id);
        results.push({ col: op.col, id: op.id, ok: false, error: e.user ? e.message : 'Error interno', doc: cur && canSee(a, op.col, cur) ? await cleanOut(env, op.col, cur) : null });
      }
    }
    if (changed) hub.broadcast();
    return json({ results, rev: hub.rev, counter: +(await getMeta(env, 'counter')) || 100 });
  }
  if (p === '/api/cart') {
    if (await rateD1(env, 'c:' + clientIp(req), 30)) return json({ error: 'Demasiados intentos' }, 429);
    const st = (await getDoc(env, 'settings', 'main')) || {};
    if (!st.cartOn) return json({ ok: false });
    const b = await readJson(req), cid = String(b.id || '');
    if (!/^[A-Za-z0-9]{6,40}$/.test(cid)) return json({ error: 'Carrito no válido' }, 400);
    const id = 'cart_' + cid, prev = await getDoc(env, 'carts', id);
    const L = makeLogic(); L.setState({ settings: st, products: [] });
    const items = [];
    for (const i of (Array.isArray(b.items) ? b.items : []).slice(0, 40)) {
      const pr = await getDoc(env, 'products', String(i.pid)), q = Math.floor(+i.qty);
      if (pr && pr.active !== false && q > 0 && q < 1000) items.push({ pid: pr.id, name: pr.name, price: L.salePrice({ ...pr, images: [] }), qty: q });
    }
    if (!items.length) { if (prev && prev.status === 'open') { await env.DB.batch([delStmt(env, 'carts', id, hub.nextRev()), metaStmt(env, 'rev', hub.rev)]); hub.broadcast(); } return json({ ok: true }); }
    const email = String(b.email || '').trim().slice(0, 120), phone = String(b.phone || '').trim().slice(0, 40);
    const okE = MAIL_RX.test(email), okP = phone.replace(/\D/g, '').length >= 10;
    if (!okE && !okP) return json({ ok: false });
    const base = prev && prev.status === 'open' ? prev : { id, createdAt: Date.now(), status: 'open', sent: [] };
    const nd = { ...base, name: String(b.name || '').trim().slice(0, 120), email: okE ? email : '', phone: okP ? phone : '', items, total: items.reduce((t, i) => t + i.price * i.qty, 0), updatedAt: Date.now() };
    if (!prev || JSON.stringify([prev.items, prev.email, prev.phone, prev.name]) !== JSON.stringify([nd.items, nd.email, nd.phone, nd.name])) {
      await env.DB.batch([putStmt(env, 'carts', id, nd, hub.nextRev()), metaStmt(env, 'rev', hub.rev)]); hub.broadcast();
    }
    return json({ ok: true });
  }
  if (p === '/api/cron') { await autoCarts(hub); return json({ ok: true }); }
  if (p === '/api/login') {
    const ip = clientIp(req); if (await throttled(env, ip)) return json({ error: 'Demasiados intentos. Espera unos minutos' }, 429);
    const b = await readJson(req), user = String(b.user || '').trim().toLowerCase(), h = String(b.h || '');
    let sid = null, me = null;
    const u = (await listDocs(env, 'users')).find(x => x.active && String(x.user).toLowerCase() === user);
    if (u && await checkSecret(env, 'user', u.id, h)) { sid = await newSession(env, 'user', u.id); me = { kind: 'user', id: u.id, role: u.role }; }
    else {
      const k = await first(env, "SELECT data FROM docs WHERE col='customers' AND deleted=0 AND lower(json_extract(data,'$.email'))=? AND id IN (SELECT id FROM secrets WHERE kind='customer')", user);
      const kd = k && JSON.parse(k.data);
      if (kd && await checkSecret(env, 'customer', kd.id, h)) { sid = await newSession(env, 'customer', kd.id); me = { kind: 'customer', id: kd.id }; }
    }
    if (!sid) { await noteFail(env, ip); return json({ error: 'Usuario o contraseña incorrectos' }, 401); }
    if (a.sid) await env.DB.prepare('DELETE FROM sessions WHERE sid=?').bind(a.sid).run();
    return json({ me }, 200, { 'Set-Cookie': cookieHeader(sid, false, url) });
  }
  if (p === '/api/register') {
    const ip = clientIp(req); if (await throttled(env, ip)) return json({ error: 'Demasiados intentos. Espera unos minutos' }, 429);
    const b = await readJson(req), email = String(b.email || '').trim(), em = email.toLowerCase(), h = String(b.h || '');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) || !h) return json({ error: 'Correo o contraseña no válidos' }, 400);
    if (!String(b.name || '').trim() || !String(b.phone || '').trim()) return json({ error: 'Escribe tu nombre y teléfono' }, 400);
    if ((await listDocs(env, 'users')).some(x => String(x.user).toLowerCase() === em)) return json({ error: 'Ese correo no está disponible' }, 409);
    const dup = await first(env, "SELECT 1 AS x FROM docs WHERE col='customers' AND deleted=0 AND lower(json_extract(data,'$.email'))=? AND id IN (SELECT id FROM secrets WHERE kind='customer')", em);
    if (dup) return json({ error: 'Ya existe una cuenta con ese correo. Inicia sesión' }, 409);
    await noteFail(env, ip);
    const id = 'k' + rand(4);
    const st = [putStmt(env, 'customers', id, { id, name: String(b.name).trim().slice(0, 120), phone: String(b.phone).trim().slice(0, 40), email: email.slice(0, 120), address: '', notes: '', ownerId: null, createdAt: Date.now() }, hub.nextRev()), await secStmt(env, 'customer', id, h), metaStmt(env, 'rev', hub.rev)];
    await env.DB.batch(st);
    const sid = await newSession(env, 'customer', id); hub.broadcast();
    return json({ me: { kind: 'customer', id } }, 200, { 'Set-Cookie': cookieHeader(sid, false, url) });
  }
  if (p === '/api/logout') { if (a.sid) await env.DB.prepare('DELETE FROM sessions WHERE sid=?').bind(a.sid).run(); return json({ ok: true }, 200, { 'Set-Cookie': cookieHeader('', true, url) }); }
  return json({ error: 'No encontrado' }, 404);
}

/* ---------------- Worker (rutas) ---------------- */
let READY = false;
const hubOf = env => env.HUB.get(env.HUB.idFromName('main'));
async function route(req, env, url) {
  const p = url.pathname, m = req.method;
  if (p === '/api/ping') return json({ ok: true, app: 'golden-touch', v: 1, rt: 'ws' });
  if (!READY) { const r = await hubOf(env).fetch(new Request(new URL('/api/init', url), { method: 'GET' })); if (!r.ok) return json({ error: 'Servidor iniciando' }, 503); READY = true; }
  if (p === '/api/ws') return hubOf(env).fetch(req);
  if (p === '/api/cron') return json({ error: 'No encontrado' }, 404);
  if (m === 'POST') {
    const o = req.headers.get('Origin'); if (o) { try { if (new URL(o).host !== url.host) return json({ error: 'Origen no permitido' }, 403); } catch (e) { return json({ error: 'Origen no permitido' }, 403); } }
    if (!/application\/json/.test(req.headers.get('Content-Type') || '')) return json({ error: 'Tipo no válido' }, 415);
    return hubOf(env).fetch(req);
  }
  const a = await actorOf(env, req);
  if (p === '/api/me') return json({ me: meOf(a) });
  if (p === '/api/sync') return json(await syncRes(env, a, Math.max(0, +url.searchParams.get('since') || 0)));
  if (p === '/api/coupon') {
    const code = String(url.searchParams.get('code') || '').trim().toUpperCase();
    const c = code && (await listDocs(env, 'coupons')).find(x => x.active && String(x.code).toUpperCase() === code);
    return json({ coupon: c || null });
  }
  if (p === '/api/track') {
    const ip = clientIp(req);
    if (await throttled(env, ip)) return json({ error: 'Demasiados intentos. Espera unos minutos' }, 429);
    const n = String(url.searchParams.get('n') || '').trim().toUpperCase().slice(0, 20), ph = String(url.searchParams.get('p') || '').replace(/\D/g, '');
    let o = null;
    if (n && ph.length >= 7) { const r = await first(env, "SELECT data FROM docs WHERE col='orders' AND deleted=0 AND upper(json_extract(data,'$.number'))=?", n); const x = r && JSON.parse(r.data); if (x && String((x.customer && x.customer.phone) || '').replace(/\D/g, '').slice(-7) === ph.slice(-7)) o = x; }
    if (!o) { await noteFail(env, ip); return json({ error: 'No encontramos ese pedido. Revisa el número y el teléfono.' }, 404); }
    return json({ order: { number: o.number, date: o.date, status: o.status, payType: o.payType, method: o.method, channel: o.channel || '', pickup: !!o.pickup, ship: o.ship || null, customer: { name: String(o.customer.name || '').split(' ')[0] } } });
  }
  if (p === '/api/friend') {
    if (await rateD1(env, 'f:' + clientIp(req), 20)) return json({ error: 'Demasiados intentos. Espera un momento' }, 429);
    const r = await friendCheck(env, url.searchParams.get('code'), { phone: url.searchParams.get('phone'), email: url.searchParams.get('email') });
    return json(r.err ? { err: r.err } : { name: String(r.owner.name || '').split(' ')[0] });
  }
  if (p === '/api/ref') {
    const code = String(url.searchParams.get('code') || '').trim().toUpperCase();
    const u = code && (await listDocs(env, 'users')).find(x => x.active && String(x.code).toUpperCase() === code);
    return json({ user: u ? { id: u.id, code: u.code, name: u.name, role: u.role, active: true, commission: u.commission } : null });
  }
  if (p === '/api/backup') {
    if (!isAdmin(a)) return json({ error: 'Solo el administrador' }, 403);
    const dump = { app: 'golden-touch', date: new Date().toISOString(), docs: await rows(env, 'SELECT * FROM docs'), meta: await rows(env, 'SELECT * FROM meta'), secrets: await rows(env, 'SELECT * FROM secrets') };
    return new Response(JSON.stringify(dump), { headers: { 'Content-Type': 'application/json', 'Content-Disposition': `attachment; filename="goldentouch-${new Date().toISOString().slice(0, 10)}.json"`, 'Cache-Control': 'no-store' } });
  }
  return json({ error: 'No encontrado' }, 404);
}

export default {
  async fetch(req, env) {
    const url = new URL(req.url);
    if (!url.pathname.startsWith('/api/')) return env.ASSETS.fetch(req);
    try { return await route(req, env, url); }
    catch (e) { console.error(e && e.stack || e); return json({ error: 'Error interno' }, 500); }
  },
  async scheduled(event, env, ctx) {
    ctx.waitUntil(hubOf(env).fetch(new Request('https://internal/api/cron', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })).catch(e => console.error('cron', e && e.message)));
  },
};
