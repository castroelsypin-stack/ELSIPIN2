<?php
/* GOLDEN TOUCH · correos (Resend) y avisos de WhatsApp (Cloud API) */
declare(strict_types=1);

function esc($s): string { return htmlspecialchars(str($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function httpPost(string $url, array $headers, string $body): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  return [$code, (string)$r, $err];
}
function sendMail(string $to, string $subject, string $html, string $text): bool {
  $key = cfg('resend_key'); $from = cfg('mail_from');
  if (!$key || !$from) return false;
  [$c, $r, $e] = httpPost(cfg('resend_url', 'https://api.resend.com/emails'), ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], jenc(['from' => $from, 'to' => [$to], 'subject' => $subject, 'html' => $html, 'text' => $text]));
  if ($c < 200 || $c >= 300) { error_log("correo $c " . substr($r, 0, 200) . " $e"); return false; }
  return true;
}
function fiscalTxt(): string { $a = []; foreach (['legalName', 'nit', 'taxRegime'] as $k) { $v = stg($k); if ($v) $a[] = ($k === 'nit' ? 'NIT ' : '') . $v; } return implode(' · ', $a); }
function pickupTxt(): string { $p = []; foreach ([stg('pickupName'), stg('pickupAddr'), stg('pickupHours') ? 'Horario: ' . stg('pickupHours') : ''] as $x) if ($x) $p[] = $x; return implode(' · ', $p); }
function isPickO($o): bool { return $o->method === 'presencial' || js_true(g($o, 'pickup')); }
function trackStepsO($o): array {
  if ($o->status === 'cancelado') return [['Pedido recibido', true], ['Pedido cancelado', true]];
  $sh = g($o, 'ship') ?: new stdClass(); $st = $o->status; $stage = g($sh, 'stage') ?: ($st === 'entregado' ? 'entregado' : '');
  $paid = $st === 'pagado' || $st === 'entregado' || ($o->payType === 'credito' && $st === 'credito');
  $pk = js_true(g($o, 'pickup')); $L = $pk ? ['preparando', 'listo', 'entregado'] : ['preparando', 'enviado', 'entregado']; $i = array_search($stage, $L, true); $i = $i === false ? -1 : $i;
  return [['Pedido recibido', true], [$o->payType === 'credito' ? 'Crédito aprobado' : 'Pago confirmado', $paid], ['Preparando tu pedido', $i >= 0], [$pk ? 'Listo para recoger' : 'Enviado', $i >= 1], ['Entregado', $i >= 2]];
}
function trackLastO($o): string { $sh = g($o, 'ship'); $h = $sh ? array_values(array_filter((array)g($sh, 'hist', []), fn($x) => g($x, 'note'))) : []; return count($h) ? str(g($h[count($h) - 1], 'note')) : ''; }
function okUrl($u): bool { return is_string($u) && preg_match('#^https?://#i', $u) === 1; }

function emailOrder($o, string $kind): array {
  $cur = g($o, 'cur'); $m = fn($n) => money($n, $cur);
  $nm = stg('storeName') ?: 'GOLDEN TOUCH'; $cr = $o->payType === 'credito'; $sh = g($o, 'ship') ?: new stdClass();
  $T = ['nuevo' => $cr ? 'Recibimos tu solicitud de crédito' : '¡Recibimos tu pedido!', 'pagado' => 'Confirmamos tu pago', 'preparando' => 'Estamos preparando tu pedido', 'enviado' => 'Tu pedido va en camino', 'entregado' => 'Tu pedido fue entregado', 'listo' => 'Tu pedido está listo para recoger'];
  $nuevoMsg = $cr ? 'La tienda revisará tu solicitud y te contactará por WhatsApp.' : ($o->method === 'transferencia' ? 'Haz la transferencia con los datos de abajo y envíanos el comprobante por WhatsApp.' : ($o->method === 'online' ? 'Paga en línea desde el enlace de tu factura o escríbenos por WhatsApp.' : ($o->method === 'contraentrega' ? 'Pagarás en efectivo al domiciliario cuando recibas tu pedido.' : (js_true(g($o, 'pickup')) && $o->method === 'presencial' ? 'Pagas y recoges en la tienda. Te avisamos cuando esté listo.' : 'Te escribiremos por WhatsApp para coordinar tu pedido.'))));
  $msg = ['nuevo' => $nuevoMsg, 'pagado' => 'Recibimos tu pago. Pronto empezamos a preparar tu pedido.', 'preparando' => 'Estamos alistando tu pedido con mucho cuidado.', 'enviado' => 'Tu pedido ya salió. Aquí tienes los datos para rastrearlo.', 'entregado' => 'Gracias por tu compra. ¡Esperamos que la disfrutes!', 'listo' => 'Ya puedes pasar por él. Aquí tienes la dirección y el horario.'][$kind] ?? '';
  $pkl = js_true(g($o, 'pickup')) ? pickupTxt() : '';
  $subj = ($kind === 'nuevo' && stg('mailSubject') ? str_replace('{n}', $o->number, str(stg('mailSubject'))) : '') ?: (($T[$kind] ?? 'Tu pedido') . ' · ' . $o->number);
  $ban = ($kind === 'nuevo' && $o->method === 'transferencia') ? array_values(array_filter((array)(stg('banks') ?: []), fn($b) => g($b, 'name') || g($b, 'number'))) : [];
  $rows = array_map(fn($l) => [$l->name, $l->qty, $m(jsnum($l->price) * $l->qty)], $o->lines);
  $steps = trackStepsO($o);
  $trk = implode("\n", array_map(fn($x) => ($x[1] ? '✔ ' : '○ ') . $x[0], $steps));
  $trkLine = (g($sh, 'carrier') ? 'Transportadora: ' . $sh->carrier : '') . (g($sh, 'guide') ? (g($sh, 'carrier') ? ' · ' : '') . 'Guía: ' . $sh->guide : '') . (okUrl(g($sh, 'url')) ? "\nRastrear: " . $sh->url : '');
  $lines = [$T[$kind] ?? '', '', 'Hola ' . $o->customer->name . ',', $msg, '', 'Pedido ' . $o->number];
  foreach ($rows as $r) $lines[] = "- {$r[0]} x{$r[1]}: {$r[2]}";
  if (!isPickO($o)) $lines[] = 'Domicilio: ' . $m($o->shipping);
  $lines[] = 'Total: ' . $m($o->total);
  if ($pkl) { $lines[] = ''; $lines[] = 'Recoger en: ' . $pkl . (stg('pickupNote') ? "\n" . stg('pickupNote') : ''); }
  $lines[] = ''; $lines[] = $kind === 'nuevo' ? '' : $trk; $lines[] = $trkLine; $lines[] = trackLastO($o);
  if (count($ban)) { $lines[] = ''; $lines[] = 'Datos para transferir:'; foreach ($ban as $b) $lines[] = g($b, 'name') . ': ' . g($b, 'number'); }
  if (stg('mailNote')) $lines[] = "\n" . stg('mailNote');
  $lines[] = ''; $lines[] = $nm; $lines[] = fiscalTxt();
  $lines[] = implode(' · ', array_filter([stg('whatsapp') ? 'WhatsApp ' . stg('whatsapp') : '', stg('email')]));
  $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
  $td = 'padding:8px 0;border-bottom:1px solid #eadfc4';
  $h = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#2a2418"><div style="background:#0b0a08;color:#c9a45c;text-align:center;padding:22px;letter-spacing:.2em;font-size:18px">' . esc($nm) . '</div><div style="padding:22px 18px"><h2 style="margin:0 0 8px;font-weight:400">' . esc($T[$kind] ?? '') . '</h2><p style="margin:0 0 16px">Hola ' . esc($o->customer->name) . ', ' . esc($msg) . '</p>';
  $h .= '<p style="margin:0 0 6px"><b>Pedido ' . esc($o->number) . '</b></p><table style="width:100%;border-collapse:collapse;font-size:14px">';
  foreach ($rows as $r) $h .= '<tr><td style="' . $td . '">' . esc($r[0]) . '</td><td style="' . $td . ';text-align:center">×' . $r[1] . '</td><td style="' . $td . ';text-align:right">' . esc($r[2]) . '</td></tr>';
  if (!isPickO($o)) $h .= '<tr><td style="' . $td . '" colspan="2">Domicilio</td><td style="' . $td . ';text-align:right">' . esc($m($o->shipping)) . '</td></tr>';
  $h .= '<tr><td colspan="2" style="padding:10px 0"><b>Total</b></td><td style="padding:10px 0;text-align:right"><b>' . esc($m($o->total)) . '</b></td></tr></table>';
  if ($kind !== 'nuevo') {
    $h .= '<div style="margin:16px 0;padding:14px;border:1px solid #c9a45c;font-size:14px">';
    foreach ($steps as $x) $h .= '<div style="padding:3px 0;color:' . ($x[1] ? '#2a2418' : '#9a8f78') . '">' . ($x[1] ? '✔' : '○') . ' ' . esc($x[0]) . '</div>';
    if ($trkLine) $h .= '<div style="margin-top:8px"><b>' . str_replace("\n", '<br>', esc($trkLine)) . '</b></div>';
    if (trackLastO($o)) $h .= '<div style="margin-top:6px">' . esc(trackLastO($o)) . '</div>';
    $h .= '</div>';
  }
  if ($pkl) {
    $h .= '<div style="margin:16px 0;padding:14px;border:1px solid #c9a45c;font-size:14px"><b>Recoge tu pedido en tienda</b><div>' . esc($pkl) . '</div>';
    if (stg('pickupNote')) $h .= '<div style="margin-top:6px">' . esc(stg('pickupNote')) . '</div>';
    if (okUrl(stg('pickupMap'))) $h .= '<div style="margin-top:6px"><a href="' . esc(stg('pickupMap')) . '">Ver mapa</a></div>';
    $h .= '</div>';
  }
  if (count($ban)) { $h .= '<div style="margin:16px 0;padding:14px;border:1px solid #c9a45c;font-size:14px"><b>Datos para tu transferencia</b>'; foreach ($ban as $b) $h .= '<div>' . esc(g($b, 'name')) . ': <b>' . esc(g($b, 'number')) . '</b></div>'; $h .= '</div>'; }
  if (stg('mailNote')) $h .= '<p style="font-size:14px">' . esc(stg('mailNote')) . '</p>';
  $h .= '<p style="font-size:13px;color:#7a6f58;margin-top:22px">' . esc($nm) . (fiscalTxt() ? ' · ' . esc(fiscalTxt()) : '') . (stg('whatsapp') ? ' · WhatsApp ' . esc(stg('whatsapp')) : '') . (stg('email') ? ' · ' . esc(stg('email')) : '') . '</p></div></div>';
  return ['subject' => $subj, 'html' => $h, 'text' => $text];
}
function cartText($c): string {
  $nm = explode(' ', trim(str(g($c, 'name'))))[0];
  $prods = implode(', ', array_map(fn($i) => $i->name . (jsnum($i->qty) > 1 ? ' x' . $i->qty : ''), (array)(g($c, 'items') ?: [])));
  $tpl = stg('cartMsg') ?: 'Hola {nombre}, vimos que dejaste estas joyas en tu carrito. Siguen esperándote: {productos}. Complétalo aquí: {link}';
  $t = str_replace(['{nombre}', '{productos}', '{link}'], [$nm, $prods, str(g($c, 'url'))], $tpl);
  if (stg('cartCode')) $t .= ' Usa el cupón ' . stg('cartCode') . (stg('cartCodeNote') ? ' (' . stg('cartCodeNote') . ')' : '') . '.';
  return $t;
}
function emailCart($c): array {
  $nm = stg('storeName') ?: 'GOLDEN TOUCH'; $text = cartText($c); $url = str(g($c, 'url'));
  $rows = ''; foreach ((array)(g($c, 'items') ?: []) as $i) $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eadfc4">' . esc($i->name) . '</td><td style="padding:8px 0;border-bottom:1px solid #eadfc4;text-align:center">×' . $i->qty . '</td><td style="padding:8px 0;border-bottom:1px solid #eadfc4;text-align:right">' . esc(money(jsnum($i->price) * $i->qty)) . '</td></tr>';
  $body = trim($url ? str_replace($url, '', $text) : $text);
  $h = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#2a2418"><div style="background:#0b0a08;color:#c9a45c;text-align:center;padding:22px;letter-spacing:.2em;font-size:18px">' . esc($nm) . '</div><div style="padding:22px 18px"><h2 style="margin:0 0 8px;font-weight:400">¿Olvidaste algo?</h2><p style="font-size:14px">' . esc($body) . '</p><table style="width:100%;border-collapse:collapse;font-size:14px">' . $rows . '</table>';
  if ($url) $h .= '<p style="text-align:center;margin:22px 0"><a href="' . esc($url) . '" style="background:#c9a45c;color:#0b0a08;text-decoration:none;padding:12px 26px;letter-spacing:.1em;font-size:13px">COMPLETAR MI COMPRA</a></p>';
  $h .= '<p style="font-size:12px;color:#7a6f58">' . esc($nm) . (stg('whatsapp') ? ' · WhatsApp ' . esc(stg('whatsapp')) : '') . '</p></div></div>';
  return ['subject' => stg('cartSubject') ?: ('Tu carrito te está esperando · ' . $nm), 'html' => $h, 'text' => $text];
}
function waParts($o, string $kind): array {
  $cur = g($o, 'cur'); $sh = g($o, 'ship') ?: new stdClass(); $pk = pickupTxt(); $first = explode(' ', str(g($o->customer, 'name')))[0];
  $trk = implode(' · ', array_filter([g($sh, 'carrier') ? 'Transportadora ' . $sh->carrier : '', g($sh, 'guide') ? 'guía ' . $sh->guide : '', okUrl(g($sh, 'url')) ? $sh->url : '']));
  $head = ['nuevo' => 'recibimos tu pedido', 'pagado' => 'confirmamos el pago de tu pedido', 'preparando' => 'estamos preparando tu pedido', 'enviado' => 'tu pedido va en camino', 'listo' => 'tu pedido está listo para recoger', 'entregado' => 'tu pedido fue entregado'][$kind] ?? 'novedades de tu pedido';
  $detail = ['nuevo' => 'Total ' . money($o->total, $cur) . (js_true(g($o, 'pickup')) && $pk ? ' · Lo recoges en ' . $pk : ''), 'pagado' => 'Total pagado ' . money($o->total, $cur), 'preparando' => 'Lo estamos alistando con mucho cuidado', 'enviado' => $trk ?: 'Pronto recibirás los datos de rastreo', 'listo' => ($pk ?: 'Pasa por la tienda') . (stg('pickupNote') ? ' · ' . stg('pickupNote') : ''), 'entregado' => '¡Gracias por tu compra!'][$kind] ?? '—';
  $dt = $detail . (trackLastO($o) && $kind !== 'nuevo' ? ' · ' . trackLastO($o) : '');
  return ['name' => $first, 'num' => $o->number, 'head' => $head, 'detail' => $dt, 'text' => "Hola $first, $head {$o->number}. $dt"];
}
function waOrder($o, string $kind): void {
  try {
    $token = cfg('wa_token'); $pid = cfg('wa_phone_id'); $k = stg('waKinds');
    if (!$token || !$pid || !js_true(stg('waOn')) || !$o || !$o->customer || g($o, 'channel') === 'mostrador' || !(is_object($k) && js_true($k->$kind ?? null))) return;
    $to = phoneDigits($o->customer->phone ?? ''); if (strlen($to) < 10) return;
    $parts = waParts($o, $kind);
    $clean = fn($t) => mb_substr(preg_replace('/ {4,}/', '   ', preg_replace('/[\n\t]+/', ' ', str($t) ?: '—')), 0, 300);
    if (stg('waMode') === 'text') $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => mb_substr($parts['text'], 0, 1000)]];
    else {
      $tpl = stg('waTpl'); $name = is_object($tpl) ? ($tpl->$kind ?? '') : ''; if (!$name) return;
      $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $name, 'language' => ['code' => stg('waLang') ?: 'es'], 'components' => [['type' => 'body', 'parameters' => array_map(fn($t) => ['type' => 'text', 'text' => $clean($t)], [$parts['name'], $parts['num'], $parts['detail']])]]]];
    }
    [$c, $r] = httpPost(cfg('wa_url') ?: 'https://graph.facebook.com/v21.0/' . $pid . '/messages', ['Authorization: Bearer ' . $token, 'Content-Type: application/json'], jenc($body));
    if ($c < 200 || $c >= 300) error_log("whatsapp $c " . substr($r, 0, 200));
  } catch (Throwable $e) { error_log('whatsapp ' . $e->getMessage()); }
}
/* cola de correos: se envía al terminar la respuesta */
$GLOBALS['MAILQ'] = [];
function queueMail(array $x): void { $GLOBALS['MAILQ'][] = $x; }
function mailOrder($o, string $kind): void {
  try {
    if (!js_true(stg('mailOn')) || !$o || !$o->customer || !preg_match(MAIL_RX, str($o->customer->email ?? ''))) return;
    $m = emailOrder($o, $kind); sendMail($o->customer->email, $m['subject'], $m['html'], $m['text']);
  } catch (Throwable $e) { error_log('correo ' . $e->getMessage()); }
}
function mailCart($c): bool {
  try {
    if (!js_true(stg('mailOn')) || !$c || !preg_match(MAIL_RX, str(g($c, 'email')))) return false;
    $mu = str(g($c, 'mailUrl')); $url = (preg_match('#^https?://[^\s<>"\']{4,300}$#', $mu) ? $mu : '') ?: str(stg('storeUrl'));
    if (!preg_match('#^https?://#', $url)) return false;
    $c2 = clone $c; $c2->url = $url; $m = emailCart($c2); return sendMail($c->email, $m['subject'], $m['html'], $m['text']);
  } catch (Throwable $e) { error_log('correo ' . $e->getMessage()); return false; }
}
function flushMail(): void {
  $q = $GLOBALS['MAILQ']; $GLOBALS['MAILQ'] = [];
  foreach ($q as $x) { if (!empty($x['cart'])) mailCart($x['cart']); else { mailOrder($x['ord'], $x['kind']); waOrder($x['ord'], $x['kind']); } }
}
