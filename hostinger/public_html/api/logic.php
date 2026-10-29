<?php
/* GOLDEN TOUCH · lógica de precios, créditos, puntos y pedidos (port de logic.js) */
declare(strict_types=1);

$GLOBALS['S'] = null; // estado usado por el cálculo: settings, products, coupons, zones, categories, customers, users, orders, counter, notifs

function S(): stdClass { return $GLOBALS['S']; }
function stg(string $k, $d = null) { return S()->settings->$k ?? $d; }
function js_true($v): bool { return !($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '' || $v === '0' && false); }
function phoneDigits($p): string { $d = preg_replace('/\D/', '', str($p)); if (strlen($d) === 10 && $d[0] === '3') $d = '57' . $d; return $d; }
function prodById(string $id) { foreach (S()->products as $p) if (g($p, 'id') === $id) return $p; return null; }
function userByIdL(?string $id) { if (!$id) return null; foreach (S()->users as $u) if (g($u, 'id') === $id) return $u; return null; }

function creditOptions(): array { $c = stg('creditInstallments'); return is_array($c) && count($c) ? array_map('intval', $c) : [2, 3, 4, 6]; }
function creditPct(string $pid): float { $p = prodById($pid); return ($p && g($p, 'creditMarkup') !== null) ? jsnum($p->creditMarkup) : jsnum(stg('creditMarkupDefault')); }
function ratePct(int $n): float { if (!$n) return 0.0; $r = stg('creditRates'); $v = is_object($r) ? ($r->{(string)$n} ?? 0) : 0; return max(0.0, jsnum($v)); }
function unitPrice($l, string $pt, int $n) { return $pt === 'credito' ? jsround(jsnum($l['price']) * (1 + (creditPct($l['pid']) + ratePct($n)) / 100)) : $l['price']; }
function discOf($p): float { return min(90.0, max(0.0, jsnum(g($p, 'discount')))); }
function salePrice($p) { return discOf($p) > 0 ? jsround(jsnum($p->price) * (1 - discOf($p) / 100)) : $p->price; }
function creditOkP($p): bool { return $p && !js_true(g($p, 'creditOff')); }
function catName(?string $id): string { foreach (S()->categories as $c) if (g($c, 'id') === $id) return str(g($c, 'name')); return ''; }
function scopeText($c): string {
  $sc = g($c, 'scope');
  if ($sc === 'category') return 'la categoría “' . (catName(g($c, 'scopeId')) ?: 'eliminada') . '”';
  if ($sc === 'product') { $p = prodById((string)g($c, 'scopeId')); return 'el producto “' . ($p ? $p->name : 'eliminado') . '”'; }
  return 'toda la tienda';
}
function inScope($c, $l): bool {
  $sc = g($c, 'scope');
  if (!$sc || $sc === 'all') return true;
  if ($sc === 'category') { $p = prodById($l['pid']); return $p && g($p, 'categoryId') === g($c, 'scopeId'); }
  if ($sc === 'product') return $l['pid'] === g($c, 'scopeId');
  return false;
}
function money($n, $cur = null): string {
  $n = jsnum($n);
  if ($cur && jsnum(g($cur, 'rate')) > 0) { $cc = g($cur, 'code'); $d = in_array($cc, ['CLP', 'PYG'], true) ? 0 : 2; return $cc . "\u{00A0}" . number_format($n / jsnum($cur->rate), $d, ',', '.'); }
  $base = stg('currency', 'COP');
  if ($base === 'COP') return '$' . "\u{00A0}" . number_format($n, 0, ',', '.');
  return $base . "\u{00A0}" . number_format($n, 0, ',', '.');
}
function sumL(array $a, callable $f): float { $t = 0.0; foreach ($a as $x) $t += jsnum($f($x)); return $t; }

function findCoupon($code, array $lines, string $pt, int $n): array {
  $code = strtoupper(trim(str($code))); if ($code === '') return [];
  $c = null; foreach (S()->coupons as $x) if (strtoupper(str(g($x, 'code'))) === $code && js_true(g($x, 'active'))) { $c = $x; break; }
  if (!$c) return ['err' => 'El cupón no existe o está inactivo'];
  $elig = array_values(array_filter($lines, fn($l) => inScope($c, $l)));
  $sub = sumL($elig, fn($l) => jsnum(unitPrice($l, $pt, $n)) * $l['qty']);
  if (!count($elig)) return ['err' => 'Este cupón solo aplica a ' . scopeText($c)];
  if (jsnum(g($c, 'minTotal')) > $sub) return ['err' => 'Este cupón requiere una compra mínima de ' . money(g($c, 'minTotal')) . ((g($c, 'scope') && g($c, 'scope') !== 'all') ? ' en ' . scopeText($c) : '')];
  if (jsnum(g($c, 'maxUses')) > 0 && jsnum(g($c, 'used')) >= jsnum($c->maxUses)) return ['err' => 'Este cupón ya alcanzó su límite de usos'];
  $discount = g($c, 'type') === 'percent' ? jsround($sub * jsnum($c->value) / 100) : min(jsnum(g($c, 'value')), $sub);
  return ['coupon' => $c, 'discount' => num($discount), 'eligSub' => $sub];
}
function calcTotals(array $lines, $couponCode, $method, $zoneId, string $pt, int $n, ?array $x = null): array {
  $subtotal = sumL($lines, fn($l) => jsnum(unitPrice($l, $pt, $n)) * $l['qty']);
  $cp = findCoupon($couponCode, $lines, $pt, $n);
  $discount = jsnum($cp['discount'] ?? 0);
  $shipping = 0; $free = false;
  if ($method && $method !== 'presencial') {
    $z = null; foreach (S()->zones as $zz) if (g($zz, 'id') === $zoneId) { $z = $zz; break; }
    $shipping = $z ? jsnum(g($z, 'cost')) : 0;
    $ff = jsnum(stg('freeShippingFrom'));
    if ($ff > 0 && $subtotal - $discount >= $ff && $shipping > 0) { $shipping = 0; $free = true; }
  }
  $loy = max(0.0, min(jsnum($x['pts'] ?? 0) + jsnum($x['ref'] ?? 0), $subtotal - $discount));
  $base = sumL($lines, fn($l) => jsnum($l['price']) * $l['qty']);
  return ['subtotal' => num($subtotal), 'discount' => num($discount), 'loy' => num($loy), 'coupon' => $cp['coupon'] ?? null, 'couponErr' => $cp['err'] ?? null, 'shipping' => num($shipping), 'free' => $free, 'total' => num($subtotal - $discount - $loy + $shipping), 'extra' => num($subtotal - $base)];
}
/* clientes */
function upsertCustomer($c, ?string $ownerId) {
  $ph = phoneDigits(g($c, 'phone')); $em = strtolower(trim(str(g($c, 'email'))));
  $x = null;
  foreach (S()->customers as $k) if (($ph && phoneDigits(g($k, 'phone')) === $ph) || ($em && strtolower(str(g($k, 'email'))) === $em)) { $x = $k; break; }
  if (!$x) {
    $x = (object)['id' => 'k' . uid6(), 'name' => g($c, 'name'), 'phone' => str(g($c, 'phone')), 'email' => str(g($c, 'email')), 'address' => str(g($c, 'address')), 'notes' => '', 'ownerId' => $ownerId, 'createdAt' => nowms()];
    S()->customers[] = $x;
  } else {
    $x->name = g($c, 'name') ?: g($x, 'name'); $x->phone = g($c, 'phone') ?: g($x, 'phone'); $x->email = g($c, 'email') ?: g($x, 'email'); $x->address = g($c, 'address') ?: g($x, 'address');
    if (!g($x, 'ownerId') && $ownerId) $x->ownerId = $ownerId;
  }
  return $x;
}
/* puntos y referidos */
function loyOn(): bool { return js_true(stg('loyOn')); }
function refOn(): bool { return js_true(stg('refOn')); }
function ptVal(): float { return max(1.0, jsnum(stg('loyVal', 50)) ?: 50.0); }
function refCodeOf($k): string { return $k && g($k, 'id') ? 'GT-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', str($k->id)), -6)) : ''; }
function addPts($k, $n, string $why): void {
  $k->pts = max(0, (int)floor(jsnum(g($k, 'pts')) + $n));
  $log = g($k, 'ptsLog'); $log = is_array($log) ? $log : [];
  array_unshift($log, (object)['d' => nowms(), 'n' => $n, 'why' => $why]); if (count($log) > 60) $log = array_slice($log, 0, 60);
  $k->ptsLog = $log;
}
function ptsMax($k, $net): array {
  if (!loyOn() || !$k) return ['pts' => 0, 'disc' => 0];
  $bal = (int)floor(jsnum(g($k, 'pts'))); $min = max(1.0, jsnum(stg('loyMin')) ?: 0.0); if ($min < 1) $min = 1.0;
  $pc = min(100.0, max(1.0, jsnum(stg('loyMaxPct', 50)) ?: 50.0));
  $pts = (int)min($bal, floor(max(0.0, jsnum($net)) * $pc / 100 / ptVal()));
  return $pts >= $min ? ['pts' => $pts, 'disc' => $pts * ptVal()] : ['pts' => 0, 'disc' => 0];
}
function isPaidO($o): bool { return in_array(g($o, 'status'), ['pagado', 'entregado'], true); }
function ptsEarn($o): int { return max(0, (int)floor((jsnum($o->subtotal) - jsnum($o->discount) - jsnum(g($o, 'loyDisc'))) / 1000 * jsnum(stg('loyEarn')))); }
function awardPoints($o): void {
  if (!$o || js_true(g($o, 'ptsDone')) || !isPaidO($o)) return;
  $k = null; foreach (S()->customers as $x) if (g($x, 'id') === g($o, 'customerId')) { $k = $x; break; }
  if (loyOn() && $k) { $p = ptsEarn($o); if ($p > 0) { addPts($k, $p, 'Compra ' . $o->number); $o->ptsGot = $p; } }
  if (refOn() && g($o, 'refBy')) {
    $f = null; foreach (S()->customers as $x) if (g($x, 'id') === $o->refBy) { $f = $x; break; }
    $b = (int)floor(jsnum(stg('refInviterPts')));
    if ($f && $b > 0) { addPts($f, $b, 'Tu amiga ' . explode(' ', str(g($o->customer, 'name')))[0] . ' hizo su primera compra'); $o->ptsBonus = $b; }
  }
  $o->ptsDone = true;
}
/* devuelve ['err'=>..] o ['owner'=>[id,name]] */
function refResolve($code, $c, array $K, callable $hasOrd): array {
  $code = strtoupper(trim(str($code))); if ($code === '') return [];
  $own = null; foreach ($K as $k) if (refCodeOf($k) === $code) { $own = $k; break; }
  if (!$own) return ['err' => 'Ese código de amiga no existe'];
  $ph = phoneDigits(g($c, 'phone')); $em = strtolower(trim(str(g($c, 'email'))));
  $mine = array_values(array_filter($K, fn($k) => ($ph && phoneDigits(g($k, 'phone')) === $ph) || ($em && strtolower(str(g($k, 'email'))) === $em)));
  foreach ($mine as $k) if ($k->id === $own->id) return ['err' => 'No puedes usar tu propio código'];
  foreach ($mine as $k) if ($hasOrd($k->id)) return ['err' => 'El código de amiga es solo para tu primera compra'];
  return ['owner' => ['id' => $own->id, 'name' => g($own, 'name')]];
}
/* créditos */
function custLimit($k): float { return ($k && g($k, 'creditLimit') !== null) ? jsnum($k->creditLimit) : jsnum(stg('creditLimitDefault')); }
function ordersAll(): array { if (S()->orders === null) S()->orders = listDocs('orders'); return S()->orders; }
function unpaidO($o): float { $t = 0.0; foreach ((g($o, 'plan') ?: []) as $i) if (!js_true(g($i, 'paid'))) $t += jsnum($i->amount); return $t; }
function custOutstanding(string $cid): float { $t = 0.0; foreach (ordersAll() as $o) if (g($o, 'customerId') === $cid && g($o, 'payType') === 'credito' && in_array(g($o, 'status'), ['pendiente', 'credito'], true)) $t += unpaidO($o); return $t; }
function downOf($total, $raw): int { return js_true(stg('creditDownEnabled')) ? (int)min(max(0, jsround(jsnum($raw))), floor(jsnum($total) * .9)) : 0; }
function buildPlan($total, int $n, int $fromMs): array {
  $base = (int)floor($total / $n); $plan = [];
  for ($i = 0; $i < $n; $i++) {
    $d = (new DateTime('@' . intdiv($fromMs, 1000)))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $d->modify('+' . ($i + 1) . ' months');
    $plan[] = (object)['n' => $i + 1, 'due' => $d->getTimestamp() * 1000 + ($fromMs % 1000), 'amount' => $i === $n - 1 ? $total - $base * ($n - 1) : $base, 'paid' => false, 'paidAt' => null];
  }
  return $plan;
}
function buildCreditPlan($total, int $n, int $from, int $down): array {
  $plan = buildPlan($total - $down, $n, $from);
  if ($down > 0) array_unshift($plan, (object)['n' => 0, 'initial' => true, 'due' => $from + 2 * 864000 * 100, 'amount' => $down, 'paid' => false, 'paidAt' => null]);
  return $plan;
}
function notifyL(string $to, string $title, string $body, string $go): void {
  $n = (object)['id' => 'n' . uid6() . uid6(), 'to' => $to, 'title' => $title, 'body' => $body, 'go' => $go, 'ts' => nowms(), 'read' => false];
  S()->newNotifs[] = $n;
}
function pickupOnL(): bool { return js_true(stg('pickupOn')); }

/* Crea el pedido con los mismos cálculos que el cliente. $o: stdClass con la entrada ya depurada */
function createOrder(stdClass $o) {
  $pt = ($o->payType ?? '') === 'credito' ? 'credito' : 'contado';
  $base = [];
  foreach ($o->items as $i) {
    if ($i->qty <= 0) continue;
    $p = prodById($i->pid);
    if (!$p) fail('Producto no disponible');
    if (!js_true(g($p, 'active'))) fail($p->name . ' no está disponible');
    if ($i->qty > jsnum(g($p, 'stock'))) fail('No hay stock suficiente de ' . $p->name);
    $base[] = ['pid' => $p->id, 'name' => $p->name, 'price' => salePrice($p), 'qty' => $i->qty];
  }
  if (!count($base)) fail('Agrega al menos un producto');
  $n0 = $pt === 'credito' ? (int)jsnum($o->installments) : 0;
  $pick = !empty($o->pickup); if ($pick && !pickupOnL()) fail('Recoger en tienda no está disponible por ahora');
  $mEff = $pick ? 'presencial' : $o->method;
  $t0 = calcTotals($base, $o->couponCode, $mEff, $pick ? '' : $o->zoneId, $pt, $n0);
  if ($o->couponCode && $t0['couponErr']) fail($t0['couponErr']);
  if ($mEff !== 'presencial' && !$o->zoneId) fail('Elige la zona de entrega');
  $acct = null;
  if (!empty($o->customerId)) foreach (S()->customers as $k) if ($k->id === $o->customerId && !empty($k->passHash)) { $acct = $k; break; }
  $net0 = $t0['subtotal'] - $t0['discount'];
  $refDisc = 0; $friend = null; $ptsUsed = 0; $ptsDisc = 0;
  if (refOn() && trim(str($o->friendCode ?? '')) !== '') {
    $r = $o->friendCtx ?? null;
    if (!$r) fail('Ese código de amiga no existe');
    if (!empty($r['err'])) fail($r['err']);
    $mn = jsnum(stg('refFriendMin')); if ($net0 < $mn) fail('El código de amiga aplica en compras desde ' . money($mn));
    $refDisc = (int)min(jsnum(stg('refFriend')), $net0); $friend = $r['owner'];
  }
  if (loyOn() && !empty($o->usePts) && $acct) { $m = ptsMax($acct, $net0 - $refDisc); $ptsUsed = $m['pts']; $ptsDisc = $m['disc']; }
  $t = calcTotals($base, $o->couponCode, $mEff, $pick ? '' : $o->zoneId, $pt, $n0, ['pts' => $ptsDisc, 'ref' => $refDisc]);
  $n = 0; $down = 0;
  if ($pt === 'credito') {
    if (!js_true(stg('creditEnabled', true))) fail('El crédito no está disponible por ahora');
    $nc = array_filter($base, fn($l) => !creditOkP(prodById($l['pid'])));
    if (count($nc)) fail('No se vende a crédito: ' . implode(', ', array_map(fn($l) => $l['name'], $nc)));
    if (!$acct) fail('Inicia sesión para comprar a crédito');
    if (js_true(g($acct, 'creditBlocked'))) fail('Tu crédito está suspendido. Comunícate con la tienda');
    if ($o->method === 'contraentrega') fail('El crédito no aplica para contra entrega');
    $n = (int)jsnum($o->installments); if (!in_array($n, creditOptions(), true)) fail('Elige el número de cuotas');
    $down = downOf($t['total'], $o->down); $lim = custLimit($acct);
    if ($lim > 0 && custOutstanding($acct->id) + $t['total'] - $down > $lim) fail('Superas tu cupo de crédito (' . money($lim) . ')');
  }
  $lines = array_map(fn($l) => (object)['pid' => $l['pid'], 'name' => $l['name'], 'price' => unitPrice($l, $pt, $n0), 'basePrice' => $l['price'], 'qty' => $l['qty']], $base);
  $emp = !empty($o->userId) ? userByIdL($o->userId) : null;
  if (!$emp && !empty($o->refCode)) foreach (S()->users as $u) if (js_true(g($u, 'active')) && strtoupper(str(g($u, 'code'))) === strtoupper($o->refCode)) { $emp = $u; break; }
  if ($emp && $emp->role === 'admin' && empty($o->userId)) $emp = null;
  $pct = ($emp && $emp->role !== 'cajero') ? (g($emp, 'commission') ?? jsnum(stg('defaultCommission'))) : 0;
  $net = $t['subtotal'] - $t['discount'];
  $zone = null; if (!$pick) foreach (S()->zones as $z) if (g($z, 'id') === $o->zoneId) { $zone = $z; break; }
  $mostr = ($o->channel ?? '') === 'mostrador';
  $cust0 = $acct ?: ($mostr && trim(str(g($o->customer, 'phone'))) === '' ? (object)['id' => null] : upsertCustomer($o->customer, ($emp && $emp->role === 'vendedor') ? $emp->id : null));
  if ($acct && $mEff !== 'presencial' && !empty($o->customer->address) && empty($acct->address)) $acct->address = $o->customer->address;
  S()->counter++;
  $now = nowms();
  $cur = null; if (!empty($o->cur) && $o->cur !== stg('currency', 'COP')) foreach ((is_array(stg('curs')) ? stg('curs') : []) as $k) if (g($k, 'code') === $o->cur && jsnum(g($k, 'rate')) > 0) { $cur = (object)['code' => $k->code, 'rate' => num(jsnum($k->rate))]; break; }
  $paidNow = $pt !== 'credito' && !empty($o->paid);
  $ord = (object)[
    'id' => 'o' . uid6(), 'number' => 'GT-' . str_pad((string)S()->counter, 5, '0', STR_PAD_LEFT), 'date' => $now, 'lines' => $lines, 'subtotal' => $t['subtotal'], 'discount' => $t['discount'],
    'couponCode' => $t['coupon'] ? $t['coupon']->code : '', 'shipping' => $t['shipping'], 'total' => $t['total'],
    'payType' => $pt, 'installments' => $n, 'ratePct' => $pt === 'credito' ? ratePct($n) : 0, 'downPayment' => $down, 'plan' => $pt === 'credito' ? buildCreditPlan($t['total'], $n, $now, $down) : null, 'creditExtra' => $pt === 'credito' ? $t['extra'] : 0,
    'cur' => $cur, 'method' => $o->method, 'pickup' => $pick, 'zoneName' => ($zone && $mEff !== 'presencial') ? $zone->name : '', 'customerId' => $cust0->id,
    'customer' => (object)['name' => $o->customer->name, 'phone' => $o->customer->phone, 'email' => $o->customer->email, 'address' => $o->method !== 'presencial' ? $o->customer->address : ''],
    'employeeId' => $emp ? $emp->id : null, 'viaLink' => !empty($o->refCode) && empty($o->userId) && $emp ? true : false, 'commissionPct' => $pct, 'commissionAmt' => $emp ? jsround($net * jsnum($pct) / 100) : 0, 'commissionPaid' => false,
    'channel' => $mostr ? 'mostrador' : '', 'payMethod' => $mostr ? (in_array($o->payMethod, ['efectivo', 'tarjeta', 'transferencia'], true) ? $o->payMethod : 'efectivo') : '', 'cashId' => $mostr ? ($o->cashId ?: null) : null,
    'status' => $paidNow ? 'pagado' : 'pendiente', 'paidAt' => $paidNow ? $now : null, 'note' => $o->note ?? '',
    'loyDisc' => $t['loy'] ?: 0, 'ptsUsed' => $ptsUsed, 'ptsDisc' => num($ptsDisc), 'refDisc' => $refDisc, 'friendCode' => $friend ? strtoupper(trim($o->friendCode)) : '', 'refBy' => $friend ? $friend['id'] : '',
  ];
  foreach ($lines as $l) { $p = prodById($l->pid); $p->stock = num(jsnum($p->stock) - $l->qty); }
  if ($t['coupon']) $t['coupon']->used = num(jsnum(g($t['coupon'], 'used')) + 1);
  if ($ptsUsed && $acct) addPts($acct, -$ptsUsed, 'Canje en ' . $ord->number);
  if ($ord->status === 'pagado') awardPoints($ord);
  if ($pt === 'credito' && !($o->staffActor ?? false)) notifyL('staff', 'Nueva compra a crédito', $o->customer->name . ' · ' . $ord->number . ' · ' . money($ord->total) . ' en ' . $n . ' cuotas' . ($down ? ' con cuota inicial de ' . money($down) : '') . '. Espera tu aprobación.', 'creditos');
  return $ord;
}
