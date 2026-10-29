<?php
/* GOLDEN TOUCH · api/api.php — igual que /api.php, por si el panel de Hostinger lo pide dentro de la carpeta api. */
$_SERVER['PATH_INFO'] = $_SERVER['PATH_INFO'] ?? '';
require dirname(__DIR__) . '/api.php';
