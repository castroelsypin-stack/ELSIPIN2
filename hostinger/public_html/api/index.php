<?php
/* GOLDEN TOUCH · API (PHP + MySQL). Mismo contrato que el servidor Node: api/ping, sync, ops, login... */
declare(strict_types=1);
ini_set('display_errors', '0'); error_reporting(E_ALL);
require __DIR__ . '/core.php';
require __DIR__ . '/logic.php';
require __DIR__ . '/mail.php';

const COLS = ['categories', 'products', 'coupons', 'zones', 'users', 'customers', 'orders', 'creditReqs', 'notifs', 'cashClosings', 'reviews', 'carts', 'costs'];

/* ---------- visibilidad ---------- */
function cleanOut(string $col, $d) {
  if ($col === 'users') { $x = clone $d; unset($x->passHash); return $x; }
  if ($col === 'customers') { $x = clone $d; if (hasSecret('customer', $d->id)) $x->passHash = '1'; else unset($x->passHash); return $x; }
  return $d;
}
function canSee(array $a, string $col, $d): bool {
  if (in_array($col, ['settings', 'categories', 'zones', 'reviews'], true)) return true;
  if ($col === 'products') return isStaff($a) || g($d, 'active') !== false;
  if (isStaff($a)) return $col !== 'notifs' ? true : g($d, 'to') === 'staff';
  if ($col === 'users') return $a['kind'] === 'user' && $d->id === $a['id'];
  if ($col === 'customers') return ($a['kind'] === 'customer' && $d->id === $a['id']) || ($a['kind'] === 'user' && $a['role'] === 'vendedor' && g($d, 'ownerId') === $a['id']);
  if ($col === 'orders') return ($a['kind'] === 'customer' && g($d, 'customerId') === $a['id']) || ($a['kind'] === 'user' && g($d, 'employeeId') === $a['id']);
  if ($col === 'creditReqs') return $a['kind'] === 'customer' && g($d, 'customerId') === $a['id'];
  if ($col === 'cashClosings') return $a['kind'] === 'user' && g($d, 'userId') === $a['id'];
  if ($col === 'notifs') return $a['kind'] === 'customer' && g($d, 'to') === $a['id'];
  return false;
}
function syncOut(array $a, int $since): array {
  $out = [];
  foreach (all('SELECT col,id,data,deleted FROM gt_docs WHERE rev>? ORDER BY rev,seq', [$since]) as $r) {
    if ((int)$r['deleted']) { if ($since > 0) $out[] = ['col' => $r['col'], 'id' => $r['id'], 'doc' => null]; continue; }
    $d = jdec($r['data']);
    if (canSee($a, $r['col'], $d)) $out[] = ['col' => $r['col'], 'id' => $r['id'], 'doc' => cleanOut($r['col'], $d)];
    elseif ($since > 0) $out[] = ['col' => $r['col'], 'id' => $r['id'], 'doc' => null];
  }
  return ['rev' => curRev(), 'counter' => (int)(meta('counter') ?? 100) ?: 100, 'changes' => $out];
}

/* ---------- escritura con permisos ---------- */
function applySecrets(string $kind, stdClass $doc): stdClass {
  if (!empty($doc->passHash) && $doc->passHash !== '1') setSecret($kind, $doc->id, (string)$doc->passHash);
  unset($doc->passHash); return $doc;
}
function lastAdminGuard($prev, $next): void {
  $admins = array_filter(listDocs('users'), fn($u) => $u->role === 'admin' && g($u, 'active'));
  $rest = array_filter($admins, fn($u) => !$prev || $u->id !== $prev->id);
  $stays = $next && $next->role === 'admin' && g($next, 'active');
  if ($prev && $prev->role === 'admin' && g($prev, 'active') && !$stays && count($rest) === 0) fail('Debe quedar al menos un administrador activo');
}
function allUsers(): array { return listDocs('users'); }

function applyOp(array $a, $op) {
  if (!plain($op)) fail('Operación no válida');
  $col = str($op->col ?? ''); $id = $op->id ?? null; $doc = $op->doc ?? null;
  if (!is_string($id) || $id === '' || strlen($id) > 64) fail('Identificador no válido');
  $isDel = $doc === null;
  if (!$isDel) { if (!plain($doc) || ($col !== 'settings' && ($doc->id ?? null) !== $id)) fail('Documento no válido'); if (strlen(jenc($doc)) > MAXDOC) fail('Documento demasiado grande'); }

  if ($col === 'settings') {
    if (!isStaff($a)) fail('No tienes permiso');
    if ($id !== 'main' || $isDel) fail('Ajuste no válido');
    putDoc('settings', 'main', $doc); return $doc;
  }
  if (in_array($col, ['categories', 'products', 'zones', 'coupons', 'costs'], true)) {
    if (!isStaff($a)) fail('No tienes permiso');
    if ($isDel) { delDoc($col, $id); return null; }
    putDoc($col, $id, $doc); return $doc;
  }
  if ($col === 'users') {
    if (!isAdmin($a)) fail('Solo el administrador gestiona el equipo');
    $prev = getDoc('users', $id);
    if ($isDel) { lastAdminGuard($prev, null); delDoc('users', $id); delSecret('user', $id); return null; }
    if (!in_array($doc->role ?? '', ['admin', 'gerente', 'cajero', 'vendedor'], true)) fail('Rol no válido');
    $nd = applySecrets('user', clone $doc);
    if (!$prev && !hasSecret('user', $id)) fail('Falta la contraseña');
    foreach (allUsers() as $u) {
      if ($u->id !== $id && strtolower(str($u->user ?? '')) === strtolower(str($nd->user ?? ''))) fail('Ese usuario ya existe');
      if ($u->id !== $id && strtoupper(str($u->code ?? '')) === strtoupper(str($nd->code ?? ''))) fail('Ese código ya existe');
    }
    lastAdminGuard($prev, $nd);
    putDoc('users', $id, $nd); return cleanOut('users', $nd);
  }
  if ($col === 'customers') {
    $prev = getDoc('customers', $id);
    if ($isDel) { if (!isStaff($a)) fail('No tienes permiso'); delDoc('customers', $id); delSecret('customer', $id); return null; }
    if (isStaff($a)) { $nd = applySecrets('customer', clone $doc); putDoc('customers', $id, $nd); return cleanOut('customers', $nd); }
    if ($a['kind'] === 'customer' && $a['id'] === $id && $prev) {
      $nd = clone $prev;
      foreach (['name', 'phone', 'address'] as $f) if (isset($doc->$f) && is_string($doc->$f)) $nd->$f = trunc($doc->$f, 200);
      if (!empty($doc->passHash) && $doc->passHash !== '1') setSecret('customer', $id, (string)$doc->passHash);
      putDoc('customers', $id, $nd); return cleanOut('customers', $nd);
    }
    if ($a['kind'] === 'user' && $a['role'] === 'vendedor') {
      if ($prev && g($prev, 'ownerId') !== $a['id']) fail('No tienes permiso');
      $c2 = clone $doc; $c2->ownerId = $a['id']; $nd = applySecrets('customer', $c2); putDoc('customers', $id, $nd); return cleanOut('customers', $nd);
    }
    fail('No tienes permiso');
  }
  if ($col === 'orders') {
    $prev = getDoc('orders', $id);
    if (!$prev && !$isDel) return placeOrder($a, $doc);
    if (!isStaff($a)) fail('No tienes permiso');
    if ($isDel) { delDoc('orders', $id); return null; }
    putDoc('orders', $id, $doc);
    $sh0 = g($prev, 'ship') ?: new stdClass(); $sh1 = g($doc, 'ship') ?: new stdClass();
    if (g($sh1, 'stage') && g($sh1, 'at') !== g($sh0, 'at')) queueMail(['ord' => $doc, 'kind' => $sh1->stage]);
    elseif ($prev && g($prev, 'status') !== g($doc, 'status') && in_array(g($doc, 'status'), ['pagado', 'entregado'], true)) queueMail(['ord' => $doc, 'kind' => $doc->status]);
    return $doc;
  }
  if ($col === 'carts') {
    if (!isStaff($a)) fail('No tienes permiso');
    $prev = getDoc('carts', $id);
    if ($isDel) { delDoc('carts', $id); return null; }
    if (!$prev) fail('El carrito ya no existe');
    $nd = clone $prev;
    $nd->status = in_array($doc->status ?? '', ['open', 'dismissed'], true) ? $doc->status : $prev->status;
    if (isset($doc->sent) && is_array($doc->sent)) $nd->sent = array_map(fn($x) => (object)['ch' => g($x, 'ch') === 'wa' ? 'wa' : 'mail', 'at' => (int)jsnum(g($x, 'at')) ?: nowms()], array_slice($doc->sent, 0, 20));
    if (!empty($doc->mailNow) && ($doc->mailNow !== g($prev, 'mailNow'))) { $nd->mailNow = (int)$doc->mailNow; $nd->mailUrl = trunc(str($doc->mailUrl ?? ''), 300); queueMail(['cart' => $nd]); }
    putDoc('carts', $id, $nd); return $nd;
  }
  if ($col === 'creditReqs') {
    $prev = getDoc('creditReqs', $id);
    if ($a['kind'] === 'customer' && !$prev && !$isDel) {
      $amt = jsround(jsnum($doc->amount ?? 0)); if ($amt < 1) fail('Monto no válido');
      foreach (listDocs('creditReqs') as $r) if ($r->customerId === $a['id'] && $r->status === 'pendiente') fail('Ya tienes una solicitud en revisión');
      $nd = (object)['id' => $id, 'customerId' => $a['id'], 'amount' => $amt, 'note' => trunc(str($doc->note ?? ''), 200), 'status' => 'pendiente', 'date' => nowms()];
      putDoc('creditReqs', $id, $nd); return $nd;
    }
    if (!isStaff($a)) fail('No tienes permiso');
    if ($isDel) { delDoc('creditReqs', $id); return null; }
    putDoc('creditReqs', $id, $doc); return $doc;
  }
  if ($col === 'reviews') {
    if ($isDel) { if (!isAdmin($a)) fail('Solo el administrador puede borrar reseñas'); delDoc('reviews', $id); return null; }
    if (getDoc('reviews', $id)) fail('Las reseñas no se pueden editar');
    if ($a['kind'] !== 'customer') fail('Solo las clientas registradas pueden dejar una reseña');
    $cu = getDoc('customers', $a['id']); if (!$cu) fail('Cuenta no válida');
    if (g($cu, 'reviewed')) fail('Ya dejaste tu reseña');
    foreach (listDocs('reviews') as $r) if (g($r, 'customerId') === $a['id']) fail('Ya dejaste tu reseña');
    $rating = jsround(jsnum($doc->rating ?? 0)); $text = trunc(trim(str($doc->text ?? '')), 400);
    if (!($rating >= 1 && $rating <= 5)) fail('Elige de 1 a 5 estrellas');
    if ($text === '') fail('Escribe tu opinión');
    $img = (isset($doc->img) && is_string($doc->img) && strlen($doc->img) <= 300000 && preg_match('#^data:image/(webp|jpeg|png);base64,[A-Za-z0-9+/=]+$#', $doc->img)) ? $doc->img : '';
    $pp = preg_split('/\s+/', trim(str(g($cu, 'name')))) ?: [];
    $nd = (object)['id' => $id, 'customerId' => $a['id'], 'name' => (($pp[0] ?? '') ?: 'Clienta') . (isset($pp[1]) && $pp[1] !== '' ? ' ' . mb_strtoupper(mb_substr($pp[1], 0, 1)) . '.' : ''), 'rating' => $rating, 'text' => $text, 'date' => nowms()];
    if ($img) $nd->img = $img;
    putDoc('reviews', $id, $nd); $cu2 = clone $cu; $cu2->reviewed = true; putDoc('customers', $a['id'], $cu2); return $nd;
  }
  if ($col === 'cashClosings') {
    if ($isDel) { if (!isAdmin($a)) fail('No tienes permiso'); delDoc('cashClosings', $id); return null; }
    if ($a['kind'] !== 'user') fail('No tienes permiso');
    $prev = getDoc('cashClosings', $id);
    if (!$prev) {
      foreach (listDocs('cashClosings') as $c) if ($c->userId === $a['id'] && $c->status === 'abierta') fail('Ya tienes una caja abierta');
      $nd = (object)['id' => $id, 'userId' => $a['id'], 'userName' => $a['doc']->name, 'openedAt' => nowms(), 'openingFloat' => max(0, jsround(jsnum($doc->openingFloat ?? 0))), 'status' => 'abierta', 'closedAt' => null];
      putDoc('cashClosings', $id, $nd); return $nd;
    }
    if ($prev->userId !== $a['id'] && !isStaff($a)) fail('No tienes permiso');
    if ($prev->status === 'cerrada') fail('Esa caja ya está cerrada');
    if (($doc->status ?? '') !== 'cerrada') return $prev;
    if (!isset($doc->counted) || !is_numeric($doc->counted) || jsround(jsnum($doc->counted)) < 0) fail('Escribe el efectivo contado');
    $counted = jsround(jsnum($doc->counted));
    $by = ['efectivo' => 0, 'tarjeta' => 0, 'transferencia' => 0]; $total = 0; $count = 0;
    foreach (listDocs('orders') as $o) if (g($o, 'cashId') === $id && $o->status !== 'cancelado') { $by[$o->payMethod] = ($by[$o->payMethod] ?? 0) + $o->total; $total += $o->total; $count++; }
    $expected = $prev->openingFloat + $by['efectivo'];
    $nd = clone $prev; $nd->status = 'cerrada'; $nd->closedAt = nowms(); $nd->counted = $counted; $nd->expected = $expected; $nd->diff = $counted - $expected; $nd->note = trunc(str($doc->note ?? ''), 200); $nd->totals = (object)['by' => (object)$by, 'total' => $total, 'count' => $count];
    putDoc('cashClosings', $id, $nd); return $nd;
  }
  if ($col === 'notifs') {
    $prev = getDoc('notifs', $id);
    $mine = fn($d) => $d && ((g($d, 'to') === 'staff' && isStaff($a)) || ($a['kind'] === 'customer' && g($d, 'to') === $a['id']));
    if (!$prev && !$isDel) {
      $ok = isStaff($a) || (($a['kind'] === 'customer' || ($a['kind'] === 'user' && $a['role'] === 'vendedor')) && ($doc->to ?? '') === 'staff');
      if (!$ok) fail('No tienes permiso');
      $nd = (object)['id' => $id, 'to' => trunc(str($doc->to ?? ''), 64), 'title' => trunc(str($doc->title ?? ''), 200), 'body' => trunc(str($doc->body ?? ''), 600), 'go' => trunc(str($doc->go ?? ''), 40), 'ts' => nowms(), 'read' => false];
      putDoc('notifs', $id, $nd); return $nd;
    }
    if (!$prev) return null;
    if ($isDel) { if (!(isStaff($a) || $mine($prev))) fail('No tienes permiso'); delDoc('notifs', $id); return null; }
    if (!(isStaff($a) || $mine($prev))) fail('No tienes permiso');
    $nd = clone $prev; $nd->read = !empty($doc->read); putDoc('notifs', $id, $nd); return $nd;
  }
  fail('Colección no válida');
}

/* ---------- pedidos: se recalculan aquí con la misma lógica del cliente ---------- */
function loadS(): stdClass {
  $S = new stdClass();
  $S->settings = getDoc('settings', 'main') ?: new stdClass();
  foreach (['categories', 'products', 'coupons', 'zones', 'users'] as $c) $S->$c = listDocs($c);
  $S->customers = array_map(function ($k) { if (hasSecret('customer', $k->id)) $k->passHash = '1'; else unset($k->passHash); return $k; }, listDocs('customers'));
  $S->orders = null; $S->counter = (int)(meta('counter') ?? 100) ?: 100; $S->newNotifs = [];
  return $S;
}
function friendCheck($code, $c, ?stdClass $S = null): array {
  $settings = $S ? $S->settings : (getDoc('settings', 'main') ?: new stdClass());
  if (!js_true($settings->refOn ?? null)) return ['err' => 'Los códigos de amiga no están activos'];
  $K = listDocs('customers'); $with = [];
  foreach (listDocs('orders') as $o) if ($o->status !== 'cancelado') $with[g($o, 'customerId')] = 1;
  $GLOBALS['S'] = $S ?: (object)['settings' => $settings];
  return refResolve($code, $c, $K, fn($id) => isset($with[$id]));
}
function markCartsBought($ord, $cartId): void {
  $cid = preg_match('/^[A-Za-z0-9]{6,40}$/', str($cartId)) ? 'cart_' . $cartId : null;
  $ph = substr(preg_replace('/\D/', '', str(g($ord->customer, 'phone'))), -10); $em = strtolower(str(g($ord->customer, 'email')));
  foreach (listDocs('carts') as $c) {
    if ($c->status !== 'open') continue;
    if ($c->id === $cid || (strlen($ph) >= 7 && substr(preg_replace('/\D/', '', str(g($c, 'phone'))), -10) === $ph) || ($em && strtolower(str(g($c, 'email'))) === $em)) {
      $c->status = 'bought'; $c->rec = count((array)g($c, 'sent', [])) > 0; $c->orderTotal = $ord->total; $c->boughtAt = nowms(); putDoc('carts', $c->id, $c);
    }
  }
}
function placeOrder(array $a, $d) {
  $inp = plain($d->_in ?? null) ? $d->_in : new stdClass();
  $items = [];
  foreach ((is_array($inp->items ?? null) ? $inp->items : []) as $i) { $qty = (int)floor(jsnum(g($i, 'qty'))); if ($qty > 0 && $qty < 1000) $items[] = (object)['pid' => str(g($i, 'pid')), 'qty' => $qty]; }
  if (!count($items)) fail('Agrega al menos un producto');
  $method = $inp->method ?? '';
  if (!in_array($method, ['presencial', 'domicilio', 'transferencia', 'online', 'contraentrega'], true)) fail('Elige cómo recibirás tu compra');
  $c = plain($inp->customer ?? null) ? $inp->customer : new stdClass();
  $counter = ($inp->channel ?? '') === 'mostrador';
  if ($counter) {
    if ($a['kind'] !== 'user') fail('No tienes permiso');
    $cj = getDoc('cashClosings', str($inp->cashId ?? ''));
    if (!$cj || $cj->status !== 'abierta' || $cj->userId !== $a['id']) fail('Abre tu caja para vender en el mostrador');
    if (!in_array($inp->payMethod ?? '', ['efectivo', 'tarjeta', 'transferencia'], true)) fail('Elige la forma de pago');
  } elseif (trim(str($c->name ?? '')) === '' || trim(str($c->phone ?? '')) === '') fail('Escribe tu nombre y teléfono');
  $S = loadS(); $GLOBALS['S'] = $S;
  $acctId = null;
  if ($a['kind'] === 'customer') $acctId = $a['id'];
  elseif (isStaff($a) || ($a['kind'] === 'user' && $a['role'] === 'vendedor')) { $cid = $inp->customerId ?? null; if ($cid) foreach ($S->customers as $k) if ($k->id === $cid && g($k, 'passHash') === '1') $acctId = $cid; }
  $S->customers = array_values(array_filter($S->customers, fn($k) => g($k, 'passHash') !== '1' || $k->id === $acctId));
  $friendCtx = null;
  if (js_true($S->settings->refOn ?? null) && trim(str($inp->friendCode ?? '')) !== '') {
    $r = friendCheck($inp->friendCode, $c, $S); $GLOBALS['S'] = $S;
    if (!empty($r['err'])) fail($r['err']);
    $friendCtx = $r;
    $ow = getDoc('customers', $r['owner']['id']);
    if ($ow) { $have = false; foreach ($S->customers as $k) if ($k->id === $ow->id) $have = true; if (!$have) $S->customers[] = $ow; }
  }
  $in = new stdClass();
  $in->items = $items;
  $in->customer = (object)['name' => trunc(str($c->name ?? ''), 120), 'phone' => trunc(str($c->phone ?? ''), 40), 'email' => trunc(str($c->email ?? ''), 120), 'address' => trunc(str($c->address ?? ''), 240)];
  $in->method = $method; $in->zoneId = str($inp->zoneId ?? ''); $in->couponCode = trunc(str($inp->couponCode ?? ''), 40); $in->refCode = trunc(str($inp->refCode ?? ''), 40);
  $in->note = trunc(str($inp->note ?? ''), 400); $in->cur = trunc(str($inp->cur ?? ''), 3);
  $in->payType = ($inp->payType ?? '') === 'credito' ? 'credito' : 'contado'; $in->installments = (int)jsnum($inp->installments ?? 0); $in->down = jsnum($inp->down ?? 0);
  $in->pickup = !empty($inp->pickup);
  $in->friendCode = js_true($S->settings->refOn ?? null) ? trunc(str($inp->friendCode ?? ''), 20) : ''; $in->friendCtx = $friendCtx; $in->usePts = !empty($inp->usePts);
  $in->paid = $a['kind'] === 'user' && ($counter || !empty($inp->paid)); $in->channel = $counter ? 'mostrador' : ''; $in->payMethod = $counter ? $inp->payMethod : ''; $in->cashId = $counter ? str($inp->cashId) : null;
  $in->userId = $a['kind'] === 'user' ? $a['id'] : null; $in->customerId = $acctId; $in->staffActor = $a['kind'] === 'user' && isStaff($a);
  $bP = []; foreach ($S->products as $x) $bP[$x->id] = jenc($x);
  $bC = []; foreach ($S->coupons as $x) $bC[$x->id] = jenc($x);
  $bK = []; foreach ($S->customers as $x) $bK[$x->id] = jenc($x);
  $ord = createOrder($in);
  if (!getDoc('orders', $d->id)) $ord->id = preg_match('/^[A-Za-z0-9_-]{3,40}$/', $d->id) ? $d->id : $ord->id;
  foreach ($S->products as $p) if (($bP[$p->id] ?? null) !== jenc($p)) putDoc('products', $p->id, $p);
  foreach ($S->coupons as $x) if (($bC[$x->id] ?? null) !== jenc($x)) putDoc('coupons', $x->id, $x);
  foreach ($S->customers as $k) if (($bK[$k->id] ?? null) !== jenc($k)) { $kk = clone $k; unset($kk->passHash); putDoc('customers', $k->id, $kk); }
  setMeta('counter', (string)$S->counter);
  foreach ($S->newNotifs as $n) putDoc('notifs', $n->id, $n);
  putDoc('orders', $ord->id, $ord);
  markCartsBought($ord, $inp->cartId ?? null);
  queueMail(['ord' => $ord, 'kind' => 'nuevo']);
  return $ord;
}

/* ---------- siembra inicial ---------- */
function seedIfEmpty(): void {
  if (meta('seeded')) return;
  $seed = null; $f = __DIR__ . '/data/seed.json';
  if (is_file($f)) $seed = json_decode((string)file_get_contents($f), false);
  tx(function () use ($seed) {
    if (meta('seeded')) return;
    if ($seed) {
      putDoc('settings', 'main', $seed->settings ?? new stdClass());
      foreach (COLS as $c) foreach (($seed->$c ?? []) as $d) {
        if ($c === 'users' || $c === 'customers') { $u = clone $d; $kind = $c === 'users' ? 'user' : 'customer'; if (!empty($u->passHash)) setSecret($kind, $u->id, (string)$u->passHash); unset($u->passHash); putDoc($c, $u->id, $u); }
        else putDoc($c, $d->id, $d);
      }
      setMeta('counter', (string)($seed->counter ?? 100));
    } else {
      putDoc('settings', 'main', (object)['whatsapp' => '', 'email' => '', 'address' => '', 'currency' => 'COP', 'defaultCommission' => 10, 'freeShippingFrom' => 0]);
      $id = 'u' . bin2hex(random_bytes(4)); setSecret('user', $id, hash('sha256', 'gt:admin123'));
      putDoc('users', $id, (object)['id' => $id, 'name' => 'Administrador', 'user' => 'admin', 'role' => 'admin', 'commission' => null, 'active' => true, 'code' => 'ADMIN1', 'phone' => '', 'email' => '', 'defaultPass' => true]);
      setMeta('counter', '100');
    }
    setMeta('seeded', '1');
  });
}

/* ---------- rutas ---------- */
function route(): void {
  $a = actorOf(); $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  $pos = strpos($path, '/api/'); $p = $pos === false ? $path : substr($path, $pos);
  $get = fn($k) => str($_GET[$k] ?? '');
  if ($m === 'POST') {
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o !== '') { $h = parse_url($o, PHP_URL_HOST); $pt = parse_url($o, PHP_URL_PORT); $host = $h . ($pt ? ':' . $pt : ''); if (!$h || strcasecmp($host, $_SERVER['HTTP_HOST'] ?? '') !== 0) send(403, ['error' => 'Origen no permitido']); }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) send(415, ['error' => 'Tipo no válido']);
  }
  /* ---- configuración en MySQL: POST /api/sync.php y POST /api/ops.php ---- */
  if ($p === '/api/sync.php') {
    if ($m !== 'POST') send(405, ['ok' => false, 'error' => 'Método no permitido']);
    $b = readBody();
    if (str($b->collection ?? '') !== 'config') send(400, ['ok' => false, 'error' => 'Colección no válida']);
    $st = getDoc('settings', 'main'); $recs = [];
    if ($st) $recs[] = ['id' => 'settings', 'data' => $st];
    send(200, ['ok' => true, 'collection' => 'config', 'rev' => curRev(), 'records' => $recs]);
  }
  if ($p === '/api/ops.php') {
    if ($m !== 'POST') send(405, ['ok' => false, 'error' => 'Método no permitido']);
    $b = readBody(); $ops = $b->ops ?? null;
    if (!is_array($ops) || count($ops) !== 1 || !plain($ops[0])) send(400, ['ok' => false, 'error' => 'Solicitud no válida']);
    $op = $ops[0];
    if (str($op->collection ?? '') !== 'config' || str($op->id ?? '') !== 'settings' || str($op->op ?? '') !== 'set' || !plain($op->data ?? null)) send(400, ['ok' => false, 'error' => 'Operación no válida']);
    if (strlen(jenc($op->data)) > MAXDOC) send(400, ['ok' => false, 'error' => 'La configuración es demasiado grande']);
    if (!isStaff($a)) send(403, ['ok' => false, 'error' => 'No tienes permiso']);
    try { tx(function () use ($op) { putDoc('settings', 'main', $op->data); }); }
    catch (Throwable $e) { error_log('cfg save ' . $e->getMessage()); send(500, ['ok' => false, 'error' => 'Error interno']); }
    send(200, ['ok' => true]);
  }
  if ($p === '/api/ping') send(200, ['ok' => true, 'app' => 'golden-touch', 'v' => 1, 'rt' => 'poll']);
  if ($p === '/api/me' && $m === 'GET') send(200, ['me' => meOf($a)]);
  if ($p === '/api/sync' && $m === 'GET') { $s = syncOut($a, max(0, (int)($_GET['since'] ?? 0))); $s['me'] = meOf($a); send(200, $s); }
  if ($p === '/api/events') send(404, ['error' => 'No disponible']);
  if ($p === '/api/coupon' && $m === 'GET') {
    $code = strtoupper(trim($get('code'))); $f = null;
    if ($code !== '') foreach (listDocs('coupons') as $x) if (g($x, 'active') && strtoupper(str($x->code ?? '')) === $code) { $f = $x; break; }
    send(200, ['coupon' => $f]);
  }
  if ($p === '/api/friend' && $m === 'GET') {
    if (rate('friend', 20)) send(429, ['error' => 'Demasiados intentos. Espera un momento']);
    $r = friendCheck($get('code'), (object)['phone' => $get('phone'), 'email' => $get('email')]);
    send(200, !empty($r['err']) ? ['err' => $r['err']] : ['name' => explode(' ', str($r['owner']['name'] ?? ''))[0]]);
  }
  if ($p === '/api/cart' && $m === 'POST') {
    if (rate('cart', 30)) send(429, ['error' => 'Demasiados intentos']);
    $st = getDoc('settings', 'main') ?: new stdClass(); $GLOBALS['S'] = (object)['settings' => $st];
    if (!js_true($st->cartOn ?? null)) send(200, ['ok' => false]);
    $b = readBody(); $cid = str($b->id ?? '');
    if (!preg_match('/^[A-Za-z0-9]{6,40}$/', $cid)) send(400, ['error' => 'Carrito no válido']);
    $id = 'cart_' . $cid; $res = tx(function () use ($b, $id, $st) {
      $prev = getDoc('carts', $id); $items = [];
      foreach (array_slice(is_array($b->items ?? null) ? $b->items : [], 0, 40) as $i) { $pr = getDoc('products', str(g($i, 'pid'))); $qq = (int)floor(jsnum(g($i, 'qty'))); if ($pr && g($pr, 'active') !== false && $qq > 0 && $qq < 1000) $items[] = (object)['pid' => $pr->id, 'name' => $pr->name, 'price' => salePrice($pr), 'qty' => $qq]; }
      if (!count($items)) { if ($prev && $prev->status === 'open') delDoc('carts', $id); return ['ok' => true]; }
      $email = trunc(trim(str($b->email ?? '')), 120); $phone = trunc(trim(str($b->phone ?? '')), 40);
      $okE = preg_match(MAIL_RX, $email) === 1; $okP = strlen(preg_replace('/\D/', '', $phone)) >= 10;
      if (!$okE && !$okP) return ['ok' => false];
      if (!$prev && (int)(one("SELECT COUNT(*) AS n FROM gt_docs WHERE col='carts' AND deleted=0")['n'] ?? 0) > 5000) return ['ok' => false];
      $base = ($prev && $prev->status === 'open') ? $prev : (object)['id' => $id, 'createdAt' => nowms(), 'status' => 'open', 'sent' => []];
      $nd = clone $base; $nd->name = trunc(trim(str($b->name ?? '')), 120); $nd->email = $okE ? $email : ''; $nd->phone = $okP ? $phone : '';
      $nd->items = $items; $t = 0; foreach ($items as $i) $t += jsnum($i->price) * $i->qty; $nd->total = num($t); $nd->updatedAt = nowms();
      if (!$prev || jenc([g($prev, 'items'), g($prev, 'email'), g($prev, 'phone'), g($prev, 'name')]) !== jenc([$nd->items, $nd->email, $nd->phone, $nd->name])) putDoc('carts', $id, $nd);
      return ['ok' => true];
    });
    send(200, $res);
  }
  if ($p === '/api/ref' && $m === 'GET') {
    $code = strtoupper(trim($get('code'))); $f = null;
    if ($code !== '') foreach (listDocs('users') as $u) if (g($u, 'active') && strtoupper(str($u->code ?? '')) === $code) { $f = $u; break; }
    send(200, ['user' => $f ? ['id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'role' => $f->role, 'active' => true, 'commission' => g($f, 'commission')] : null]);
  }
  if ($p === '/api/track' && $m === 'GET') {
    if (throttled()) send(429, ['error' => 'Demasiados intentos. Espera unos minutos']);
    $n = strtoupper(substr(trim($get('n')), 0, 20)); $ph = preg_replace('/\D/', '', $get('p')); $o = null;
    if ($n !== '' && strlen($ph) >= 7) foreach (listDocs('orders') as $x) if (strtoupper(str($x->number ?? '')) === $n && substr(preg_replace('/\D/', '', str(g($x->customer, 'phone'))), -7) === substr($ph, -7)) { $o = $x; break; }
    if (!$o) { noteFail(); send(404, ['error' => 'No encontramos ese pedido. Revisa el número y el teléfono.']); }
    send(200, ['order' => ['number' => $o->number, 'date' => $o->date, 'status' => $o->status, 'payType' => $o->payType, 'method' => $o->method, 'channel' => g($o, 'channel') ?: '', 'pickup' => !empty($o->pickup), 'ship' => g($o, 'ship'), 'customer' => ['name' => explode(' ', str(g($o->customer, 'name')))[0]]]]);
  }
  if ($p === '/api/login' && $m === 'POST') {
    if (throttled()) send(429, ['error' => 'Demasiados intentos. Espera unos minutos']);
    $b = readBody(); $user = strtolower(trim(str($b->user ?? ''))); $h = str($b->h ?? ''); $sid = null; $me = null;
    $u = null; foreach (listDocs('users') as $x) if (g($x, 'active') && strtolower(str($x->user ?? '')) === $user) { $u = $x; break; }
    if ($u && checkSecret('user', $u->id, $h)) { $sid = newSession('user', $u->id); $me = ['kind' => 'user', 'id' => $u->id, 'role' => $u->role]; }
    else { foreach (listDocs('customers') as $k) if (strtolower(str(g($k, 'email'))) === $user && hasSecret('customer', $k->id) && checkSecret('customer', $k->id, $h)) { $sid = newSession('customer', $k->id); $me = ['kind' => 'customer', 'id' => $k->id]; break; } }
    if (!$sid) { noteFail(); send(401, ['error' => 'Usuario o contraseña incorrectos']); }
    if (!empty($a['sid'])) q('DELETE FROM gt_sessions WHERE sid=?', [$a['sid']]);
    setSidCookie($sid); send(200, ['me' => $me]);
  }
  if ($p === '/api/register' && $m === 'POST') {
    if (throttled()) send(429, ['error' => 'Demasiados intentos. Espera unos minutos']);
    $b = readBody(); $email = trim(str($b->email ?? '')); $em = strtolower($email); $h = str($b->h ?? '');
    if (!preg_match(MAIL_RX, $email) || $h === '') send(400, ['error' => 'Correo o contraseña no válidos']);
    if (trim(str($b->name ?? '')) === '' || trim(str($b->phone ?? '')) === '') send(400, ['error' => 'Escribe tu nombre y teléfono']);
    foreach (listDocs('users') as $x) if (strtolower(str($x->user ?? '')) === $em) send(409, ['error' => 'Ese correo no está disponible']);
    foreach (listDocs('customers') as $x) if (strtolower(str(g($x, 'email'))) === $em && hasSecret('customer', $x->id)) send(409, ['error' => 'Ya existe una cuenta con ese correo. Inicia sesión']);
    noteFail();
    $id = 'k' . bin2hex(random_bytes(4));
    tx(function () use ($id, $b, $email, $h) { putDoc('customers', $id, (object)['id' => $id, 'name' => trunc(trim(str($b->name)), 120), 'phone' => trunc(trim(str($b->phone)), 40), 'email' => trunc($email, 120), 'address' => '', 'notes' => '', 'ownerId' => null, 'createdAt' => nowms()]); setSecret('customer', $id, $h); });
    $sid = newSession('customer', $id); setSidCookie($sid); send(200, ['me' => ['kind' => 'customer', 'id' => $id]]);
  }
  if ($p === '/api/logout' && $m === 'POST') { if (!empty($a['sid'])) q('DELETE FROM gt_sessions WHERE sid=?', [$a['sid']]); setSidCookie('', true); send(200, ['ok' => true]); }
  if ($p === '/api/ops' && $m === 'POST') {
    $b = readBody(); $ops = array_slice(is_array($b->ops ?? null) ? $b->ops : [], 0, 200); $results = [];
    foreach ($ops as $op) {
      $col = str(g($op, 'col')); $id = str(g($op, 'id'));
      try {
        $doc = tx(fn() => applyOp($a, $op));
        $results[] = ['col' => $col, 'id' => $id, 'ok' => true, 'doc' => $doc];
      } catch (Throwable $e) {
        $user = $e instanceof UserErr; if (!$user) error_log('op error ' . $col . ' ' . $e->getMessage());
        $cur = $col === 'settings' ? getDoc('settings', 'main') : getDoc($col, $id);
        $results[] = ['col' => $col, 'id' => $id, 'ok' => false, 'error' => $user ? $e->getMessage() : 'Error interno', 'doc' => ($cur && canSee($a, $col, $cur)) ? cleanOut($col, $cur) : null];
      }
    }
    $GLOBALS['S'] = (object)['settings' => getDoc('settings', 'main') ?: new stdClass()];
    $payload = ['results' => $results, 'rev' => curRev(), 'counter' => (int)(meta('counter') ?? 100) ?: 100];
    flushMailLater(); send(200, $payload);
  }
  if ($p === '/api/backup' && $m === 'GET') {
    if (!isAdmin($a)) send(403, ['error' => 'Solo el administrador']);
    $dump = ['app' => 'golden-touch', 'date' => date('c'), 'docs' => all('SELECT * FROM gt_docs'), 'meta' => all('SELECT * FROM gt_meta'), 'secrets' => all('SELECT * FROM gt_secrets')];
    header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="goldentouch-' . date('Y-m-d') . '.json"'); header('Cache-Control: no-store');
    echo jenc($dump); exit;
  }
  send(404, ['error' => 'No encontrado']);
}
/* los correos se envían después de responder al cliente */
function flushMailLater(): void {
  register_shutdown_function(function () { if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); try { flushMail(); } catch (Throwable $e) { error_log('mail ' . $e->getMessage()); } });
}

try {
  try { $seeded = meta('seeded'); } catch (Throwable $e) { installSchema(); $seeded = null; }
  if (!$seeded) seedIfEmpty();
  if (random_int(1, 40) === 1) q('DELETE FROM gt_sessions WHERE exp<?', [nowms()]); // limpia sesiones vencidas
  route();
} catch (Throwable $e) {
  error_log('GT ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
  if (str_ends_with((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/api/ping')) send(503, ['app' => 'golden-touch', 'dberror' => true, 'error' => 'No se pudo conectar con la base de datos']);
  send(500, ['error' => 'Error interno']);
}
