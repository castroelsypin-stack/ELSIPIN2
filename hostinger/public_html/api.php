<?php
/* GOLDEN TOUCH · api.php — punto de entrada único del servidor.
   Uso: /api.php (comprobación), /api.php/ping, /api.php?r=ping, /api.php/sync.php, /api.php/ops.php ...
   Reenvía a api/index.php, que es el servidor real (PHP + MySQL). */
$sub = '';
if (!empty($_SERVER['PATH_INFO'])) $sub = $_SERVER['PATH_INFO'];
elseif (isset($_GET['r'])) $sub = '/' . ltrim((string)$_GET['r'], '/');
$sub = preg_replace('#[^A-Za-z0-9_./-]#', '', $sub);
if ($sub === '' || $sub === '/') $sub = '/ping';
$_SERVER['REQUEST_URI'] = '/api' . $sub;
chdir(__DIR__ . '/api');
require __DIR__ . '/api/index.php';
