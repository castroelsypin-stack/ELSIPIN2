<?php
/* Diagnóstico de la instalación. Abre: https://tudominio.com/api/check.php?key=TU_CRON_KEY  (la clave está en config.php) */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) { echo "FALTA api/config.php: copia config.sample.php como config.php y llena tus datos de MySQL.\n"; exit; }
$CFG = require $cfgFile;
if (!hash_equals((string)($CFG['cron_key'] ?? ''), (string)($_GET['key'] ?? ''))) { http_response_code(403); echo "Agrega ?key=TU_CRON_KEY (el valor de cron_key en config.php)\n"; exit; }
$ok = fn($b) => $b ? 'OK     ' : 'FALLA  ';
echo "GOLDEN TOUCH · diagnóstico\n\n";
echo $ok(version_compare(PHP_VERSION, '8.0.0', '>=')) . 'PHP ' . PHP_VERSION . " (se necesita 8.0 o superior)\n";
foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json'] as $e) echo $ok(extension_loaded($e)) . "extensión $e\n";
echo $ok(is_file(__DIR__ . '/data/seed.json')) . "datos iniciales (api/data/seed.json)\n";
try {
  require __DIR__ . '/core.php';
  $r = one('SELECT 1 AS x'); echo $ok(!!$r) . "conexión a MySQL\n";
  installSchema(); echo $ok(true) . "tablas creadas\n";
  $t = nowms(); setMeta('check', (string)$t); echo $ok(meta('check') === (string)$t) . "escritura en la base de datos\n";
  echo (meta('seeded') ? 'OK     tienda inicializada' : 'AVISO  la tienda se inicializa sola al abrir el sitio por primera vez') . "\n";
  echo "\nproductos: " . (one("SELECT COUNT(*) AS n FROM gt_docs WHERE col='products' AND deleted=0")['n'] ?? 0) . ' · pedidos: ' . (one("SELECT COUNT(*) AS n FROM gt_docs WHERE col='orders' AND deleted=0")['n'] ?? 0) . "\n";
  echo "\nCorreos automáticos: " . (cfg('resend_key') && cfg('mail_from') ? 'configurados' : 'sin configurar (opcional)') . "\n";
  echo 'WhatsApp automático: ' . (cfg('wa_token') && cfg('wa_phone_id') ? 'configurado' : 'sin configurar (opcional)') . "\n";
  echo 'cron_key: ' . ((string)cfg('cron_key') === 'CAMBIA-ESTA-CLAVE' ? "FALLA  cámbiala por un texto secreto\n" : "OK\n");
} catch (Throwable $e) { echo 'FALLA  ' . $e->getMessage() . "\n"; }
