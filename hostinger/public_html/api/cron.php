<?php
/* Tareas programadas (cron de hPanel): correos de carritos abandonados.
 * Programa cada 10 minutos:  php /home/TU_USUARIO/domains/TU_DOMINIO/public_html/api/cron.php
 * (o abre https://tudominio.com/api/cron.php?key=TU_CRON_KEY desde un cron de URL) */
declare(strict_types=1);
ini_set('display_errors', '0');
require __DIR__ . '/core.php';
require __DIR__ . '/logic.php';
require __DIR__ . '/mail.php';
if (PHP_SAPI !== 'cli' && !hash_equals((string)cfg('cron_key', ''), (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('No autorizado'); }
$st = getDoc('settings', 'main') ?: new stdClass(); $GLOBALS['S'] = (object)['settings' => $st];
$sent = 0;
if (js_true($st->cartOn ?? null) && js_true($st->cartAuto ?? null) && js_true($st->mailOn ?? null) && preg_match('#^https?://#', str($st->storeUrl ?? ''))) {
  $now = nowms(); $delay = (jsnum($st->cartDelay ?? 1) ?: 1) * 3600000; $all = listDocs('carts');
  $hour = one("SELECT v FROM gt_meta WHERE k='cron_hour'"); $hv = $hour ? json_decode($hour['v'], true) : ['t' => 0, 'n' => 0];
  if ($now - $hv['t'] > 3600000) $hv = ['t' => $now, 'n' => 0];
  foreach ($all as $c) {
    if ($hv['n'] >= 30) break;
    if ($c->status !== 'open' || count((array)g($c, 'sent', [])) || !preg_match(MAIL_RX, str(g($c, 'email'))) || !count((array)g($c, 'items', []))) continue;
    if ($now - jsnum(g($c, 'updatedAt')) < $delay || $now - jsnum(g($c, 'updatedAt')) > 7 * 86400000) continue;
    $em = strtolower(str($c->email)); $dup = false;
    foreach ($all as $x) if ($x->id !== $c->id && strtolower(str(g($x, 'email'))) === $em) foreach ((array)g($x, 'sent', []) as $y) if ($now - jsnum(g($y, 'at')) < 7 * 86400000) $dup = true;
    if ($dup) continue;
    $c2 = clone $c; $c2->mailUrl = '';
    if (mailCart($c2)) { $hv['n']++; $sent++; tx(function () use ($c, $now) { $c->sent = [(object)['ch' => 'mail', 'at' => $now, 'auto' => true]]; putDoc('carts', $c->id, $c); }); }
  }
  tx(function () use ($hv) { setMeta('cron_hour', json_encode($hv)); });
}
echo "OK $sent\n";
